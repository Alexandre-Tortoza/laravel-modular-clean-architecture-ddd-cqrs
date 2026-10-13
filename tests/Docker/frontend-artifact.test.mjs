import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import {
    mkdtempSync,
    mkdirSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { test } from 'node:test';

const script = resolve('docker/frontend-artifact.mjs');
const revision = 'a'.repeat(40);
const hash = (bytes) => createHash('sha256').update(bytes).digest('hex');

function fixture(context) {
    const directory = mkdtempSync(`${tmpdir()}/frontend-artifact-`);
    context.after(() => rmSync(directory, { recursive: true, force: true }));
    for (const path of [
        'public/build/assets',
        'resources/js/actions',
        'resources/js/routes',
        'resources/js/wayfinder',
    ]) {
        mkdirSync(`${directory}/${path}`, { recursive: true });
    }
    for (const path of [
        'composer.lock',
        'package-lock.json',
        'public/build/manifest.json',
    ]) {
        writeFileSync(`${directory}/${path}`, '{}');
    }
    writeFileSync(
        `${directory}/public/build/assets/app.js`,
        'console.log("compiled");',
    );
    for (const name of ['actions', 'routes', 'wayfinder']) {
        writeFileSync(
            `${directory}/resources/js/${name}/index.ts`,
            'export const generated = true;',
        );
    }
    const run = (sha = revision, env = process.env) =>
        spawnSync(process.execPath, [script, sha], {
            cwd: directory,
            env,
            encoding: 'utf8',
        });
    return {
        directory,
        run,
        release: () =>
            JSON.parse(
                readFileSync(`${directory}/public/build/release.json`, 'utf8'),
            ),
    };
}

await test('release identifies the source, locks, generated routes and every compiled file deterministically', (context) => {
    const { directory, run, release } = fixture(context);
    assert.equal(run().status, 0);
    const first = readFileSync(
        `${directory}/public/build/release.json`,
        'utf8',
    );
    assert.equal(release().source_revision, revision);
    assert.equal(release().lockfiles['package-lock.json'], hash('{}'));
    assert.equal(
        release().files['assets/app.js'],
        hash('console.log("compiled");'),
    );
    assert.equal(run().status, 0);
    assert.equal(
        readFileSync(`${directory}/public/build/release.json`, 'utf8'),
        first,
    );
    writeFileSync(
        `${directory}/resources/js/routes/index.ts`,
        'export const updated = true;',
    );
    const oldWayfinder = release().wayfinder_sha256;
    assert.equal(run().status, 0);
    assert.notEqual(release().wayfinder_sha256, oldWayfinder);
});

await test('production packaging refuses an ambiguous source revision and VITE environment input', (context) => {
    const { run } = fixture(context);
    assert.notEqual(run('main').status, 0);
    const result = run(revision, {
        ...process.env,
        VITE_PRIVATE_KEY: 'PRIVATE_VALUE_CANARY',
    });
    assert.notEqual(result.status, 0);
    assert.ok(!result.stderr.includes('PRIVATE_VALUE_CANARY'));
});

await test('production packaging refuses source maps and original frontend source files', (context) => {
    const { directory, run } = fixture(context);
    for (const path of ['assets/app.js.map', 'assets/Login.vue', '.env']) {
        writeFileSync(
            `${directory}/public/build/${path}`,
            'PRIVATE_SOURCE_CANARY',
        );
        assert.notEqual(run().status, 0);
        rmSync(`${directory}/public/build/${path}`);
    }
    writeFileSync(
        `${directory}/public/build/assets/app.js`,
        'console.log("compiled");\n//# sourceMappingURL=data:application/json;base64,e30=',
    );
    assert.notEqual(run().status, 0);
});

await test('production packaging requires the manifest and fresh generated Wayfinder output', (context) => {
    const { directory, run } = fixture(context);
    rmSync(`${directory}/public/build/manifest.json`);
    assert.notEqual(run().status, 0);
    writeFileSync(`${directory}/public/build/manifest.json`, '{}');
    rmSync(`${directory}/resources/js/routes/index.ts`);
    assert.notEqual(run().status, 0);
});
