import { createPublicKey, verify } from 'node:crypto';
import { readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { dirname, resolve, sep } from 'node:path';
import { pathToFileURL } from 'node:url';

const issuer = 'https://token.actions.githubusercontent.com';
function require(condition) {
    if (!condition) throw new Error('GitHub OIDC identity validation rejected.');
}

export function validateGitHubToken(token, jwks, expected, now = Math.floor(Date.now() / 1000)) {
    require(typeof token === 'string' && token.length < 16384);
    const parts = token.split('.');
    require(parts.length === 3 && parts.every((part) => /^[A-Za-z0-9_-]+$/.test(part)));
    const header = JSON.parse(Buffer.from(parts[0], 'base64url').toString());
    require(header.alg === 'RS256' && header.typ === 'JWT' && typeof header.kid === 'string');
    const matching = jwks.keys.filter((key) => key.kid === header.kid && key.kty === 'RSA' && key.alg === 'RS256' && key.use === 'sig');
    require(matching.length === 1);
    const key = createPublicKey({ key: matching[0], format: 'jwk' });
    require(key.asymmetricKeyDetails.modulusLength >= 2048);
    require(verify('RSA-SHA256', Buffer.from(parts[0] + '.' + parts[1]), key, Buffer.from(parts[2], 'base64url')));
    const claims = JSON.parse(Buffer.from(parts[1], 'base64url').toString());
    require(claims.iss === issuer && claims.aud === expected.audience && expected.audience === 'sts.amazonaws.com');
    require(claims.sub === expected.subject && claims.repository === expected.repository && claims.repository_id === expected.repository_id && claims.repository_owner_id === expected.owner_id);
    require(claims.environment === expected.environment && claims.environment_node_id === expected.environment_node_id);
    require(claims.ref === 'refs/heads/main' && (claims.ref_protected === true || claims.ref_protected === 'true') && claims.event_name === 'workflow_dispatch');
    require(claims.sha === expected.workflow_revision && claims.workflow_sha === expected.workflow_revision && claims.workflow_ref === expected.workflow_ref);
    require(Number.isInteger(claims.exp) && claims.exp > now && Number.isInteger(claims.iat) && claims.iat <= now + 60 && claims.iat >= now - 600);
    require(Number.isInteger(claims.nbf) && claims.nbf <= now + 60);
    return { issuer, audience: claims.aud, subject: claims.sub, repository: claims.repository, workflow_revision: claims.sha };
}

export function storeVerifiedToken(token, destination, temporary) {
    require(Boolean(temporary));
    const file = resolve(destination);
    const parent = realpathSync(dirname(file));
    const approved = realpathSync(resolve(temporary));
    require(parent === approved || parent.startsWith(approved + sep));
    // Exclusive creation rejects final-component symlinks/overwrite.
    writeFileSync(file, token, { mode: 0o600, flag: 'wx' });
}

async function main() {
    // Called only after the approved preflight is rerun inside the protected job.
    // This diagnostic never exchanges the JWT for AWS credentials or calls STS.
    const expected = JSON.parse(readFileSync(process.argv[2], 'utf8'));
    require(process.env.GITHUB_EVENT_NAME === 'workflow_dispatch' && process.env.GITHUB_REF === 'refs/heads/main');
    const endpoint = new URL(process.env.ACTIONS_ID_TOKEN_REQUEST_URL);
    require(endpoint.protocol === 'https:' && (endpoint.hostname === 'token.actions.githubusercontent.com' || endpoint.hostname.endsWith('.actions.githubusercontent.com')));
    endpoint.searchParams.set('audience', 'sts.amazonaws.com');
    require(Boolean(process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN));
    const response = await fetch(endpoint, { headers: { Authorization: 'Bearer ' + process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN }, redirect: 'error', signal: AbortSignal.timeout(15000) });
    require(response.ok);
    const { value } = await response.json();
    const jwksResponse = await fetch(issuer + '/.well-known/jwks', { redirect: 'error', signal: AbortSignal.timeout(15000) });
    require(jwksResponse.ok);
    const result = validateGitHubToken(value, await jwksResponse.json(), expected);
    if (process.argv[3] === '--token-file') {
        storeVerifiedToken(value, process.argv[4], process.env.RUNNER_TEMP);
    }
    // Only selected public claims; never the JWT, request bearer or AWS keys.
    process.stdout.write(JSON.stringify(result) + '\n');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main().catch(() => {
        process.stderr.write('OIDC diagnostic rejected unapproved or unverifiable identity. No AWS credential exchange performed.\n');
        process.exitCode = 1;
    });
}
