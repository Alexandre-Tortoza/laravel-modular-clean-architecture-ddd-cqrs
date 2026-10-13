import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';

const revision = process.argv[2];
if (!/^[a-f0-9]{40}$/.test(revision ?? '')) {
    throw new Error('SOURCE_REVISION must be the full source commit SHA.');
}
if (Object.keys(process.env).some((name) => name.startsWith('VITE_'))) {
    throw new Error(
        'Production image builds do not accept VITE_* environment values.',
    );
}

if (process.argv[3] !== '--validate-only') {
    const hash = (bytes) => createHash('sha256').update(bytes).digest('hex');
    const inventory = (directory) => {
        const entries = [];
        const visit = (path) => {
            for (const entry of readdirSync(path, { withFileTypes: true })) {
                const entryPath = join(path, entry.name);
                if (entry.isDirectory()) {
                    visit(entryPath);
                } else if (entry.isFile()) {
                    entries.push([
                        relative(directory, entryPath),
                        hash(readFileSync(entryPath)),
                    ]);
                } else {
                    throw new Error(
                        `Artifact contains a non-regular file: ${entryPath}`,
                    );
                }
            }
        };
        visit(directory);
        return Object.fromEntries(
            entries.sort(([left], [right]) => left.localeCompare(right, 'en')),
        );
    };

    const files = inventory('public/build');
    delete files['release.json'];
    if (
        !files['manifest.json'] ||
        Object.keys(files).some((path) =>
            /(?:^|\/)\.|\.(?:map|vue|tsx?|jsx|php|pem|key|env)$/i.test(path),
        )
    ) {
        throw new Error(
            'Production assets must contain a manifest and no source files, maps or secrets.',
        );
    }
    for (const path of Object.keys(files).filter((path) =>
        /\.(js|css)$/.test(path),
    )) {
        if (
            /(?:^|\n)\s*\/\/[#@]\s*sourceMappingURL=|\/\*[#@]\s*sourceMappingURL=/.test(
                readFileSync(join('public/build', path), 'utf8'),
            )
        ) {
            throw new Error(
                `Production asset contains a source map reference: ${path}`,
            );
        }
    }

    const wayfinder = Object.fromEntries(
        ['actions', 'routes', 'wayfinder'].map((directory) => {
            const generated = inventory(join('resources/js', directory));
            if (!Object.keys(generated).length) {
                throw new Error(
                    `Wayfinder generated directory is empty: ${directory}`,
                );
            }
            return [directory, generated];
        }),
    );
    const release = {
        schema_version: 1,
        source_revision: revision,
        lockfiles: Object.fromEntries(
            ['composer.lock', 'package-lock.json'].map((path) => [
                path,
                hash(readFileSync(path)),
            ]),
        ),
        wayfinder_sha256: hash(JSON.stringify(wayfinder)),
        files,
    };
    writeFileSync(
        'public/build/release.json',
        `${JSON.stringify(release, null, 2)}\n`,
    );
}
