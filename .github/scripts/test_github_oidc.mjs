import assert from 'node:assert/strict';
import { generateKeyPairSync, sign } from 'node:crypto';
import test from 'node:test';
import { mkdtempSync, readFileSync, rmSync, statSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { storeVerifiedToken, validateGitHubToken } from './verify_github_oidc.mjs';

const keys = generateKeyPairSync('rsa', { modulusLength: 2048 });
const jwks = { keys: [{ ...keys.publicKey.export({ format: 'jwk' }), kid: 'fixture', alg: 'RS256', use: 'sig' }] };
const expected = { subject: 'repo:owner@123/template@456:environment:publisher', audience: 'sts.amazonaws.com',
    repository: 'owner/template', repository_id: '456', owner_id: '123', environment: 'publisher', environment_node_id: 'environment-publisher',
    workflow_revision: 'a'.repeat(40), workflow_ref: 'owner/template/.github/workflows/aws-identity.yml@refs/heads/main' };
const now = 1900000000;
const claims = { iss: 'https://token.actions.githubusercontent.com', sub: expected.subject, aud: expected.audience,
    repository: expected.repository, repository_id: '456', repository_owner_id: '123', environment: 'publisher', environment_node_id: 'environment-publisher',
    sha: expected.workflow_revision, workflow_sha: expected.workflow_revision, workflow_ref: expected.workflow_ref,
    ref: 'refs/heads/main', ref_protected: true, event_name: 'workflow_dispatch', iat: now, nbf: now - 1, exp: now + 300 };
function token(payload = claims, header = { alg: 'RS256', typ: 'JWT', kid: 'fixture' }) {
    const prefix = Buffer.from(JSON.stringify(header)).toString('base64url') + '.' + Buffer.from(JSON.stringify(payload)).toString('base64url');
    return prefix + '.' + sign('RSA-SHA256', Buffer.from(prefix), keys.privateKey).toString('base64url');
}

await test('validates signed GitHub claims and exposes only an approved public identity summary', () => {
    const result = validateGitHubToken(token(), jwks, expected, now);
    assert.deepEqual(result, { issuer: claims.iss, audience: claims.aud, subject: claims.sub, repository: claims.repository, workflow_revision: claims.sha });
    assert.equal(JSON.stringify(result).includes('eyJ'), false);
});

for (const [name, value] of Object.entries({ aud: 'other-cloud', sub: 'repo:owner/template:pull_request', repository_id: '999',
    repository_owner_id: '999', environment: 'deploy', environment_node_id: 'foreign-environment', ref_protected: false, sha: 'b'.repeat(40), workflow_sha: 'b'.repeat(40),
    workflow_ref: 'owner/template/.github/workflows/unapproved.yml@refs/heads/main', ref: 'refs/pull/1/merge',
    event_name: 'pull_request_target', iss: 'https://issuer.invalid', exp: now - 1, iat: now + 120, nbf: now + 120 })) {
    await test('rejects wrong ' + name + ' despite a valid cryptographic signature', () => {
        assert.throws(() => validateGitHubToken(token({ ...claims, [name]: value }), jwks, expected, now));
    });
}
await test('rejects unsigned/unknown-key/tampered/multi-audience tokens', () => {
    assert.throws(() => validateGitHubToken(token(claims, { alg: 'none', kid: 'fixture' }), jwks, expected, now));
    assert.throws(() => validateGitHubToken(token(claims, { alg: 'RS256', kid: 'unknown' }), jwks, expected, now));
    assert.throws(() => validateGitHubToken(token().slice(0, -8) + 'tampered', jwks, expected, now));
    assert.throws(() => validateGitHubToken(token({ ...claims, aud: [expected.audience, 'other'] }), jwks, expected, now));
    assert.throws(() => validateGitHubToken('invalid.jwt.token', jwks, expected, now));
});

await test('stores only an exclusive mode-0600 token inside the real runner temporary directory', () => {
    const temporary = mkdtempSync(join(tmpdir(), 'oidc-private-fixture-'));
    const outside = mkdtempSync(join(tmpdir(), 'oidc-outside-fixture-'));
    try {
        const file = join(temporary, 'verified.jwt');
        storeVerifiedToken('synthetic-verified-token', file, temporary);
        assert.equal(statSync(file).mode & 0o777, 0o600);
        assert.equal(readFileSync(file, 'utf8'), 'synthetic-verified-token');
        assert.throws(() => storeVerifiedToken('replacement', file, temporary));
        assert.throws(() => storeVerifiedToken('token', join(outside, 'rejected.jwt'), temporary));
        symlinkSync(outside, join(temporary, 'escape'), 'dir');
        assert.throws(() => storeVerifiedToken('token', join(temporary, 'escape', 'rejected.jwt'), temporary));
        assert.throws(() => storeVerifiedToken('token', join(temporary, 'another.jwt'), undefined));
    } finally {
        rmSync(temporary, { recursive: true, force: true });
        rmSync(outside, { recursive: true, force: true });
    }
});
