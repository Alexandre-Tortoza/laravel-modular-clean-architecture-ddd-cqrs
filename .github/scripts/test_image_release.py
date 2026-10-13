import gzip
import hashlib
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest

from image_release import create_manifest, verify_bundle


def digest(content):
    return 'sha256:' + hashlib.sha256(content).hexdigest()


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()


class ImageReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='image-release-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source = self.root / 'source'
        self.source.mkdir()
        for name in ('composer.lock', 'package-lock.json'):
            (self.source / name).write_text(name + '-locked-fixture')
        self.sha = 'a' * 40
        self.version = 'v1.2.3'
        self.bundle = self.root / 'current'
        self.bundle.mkdir()
        self.oci(self.bundle, 'php')
        self.oci(self.bundle, 'nginx')

    def oci(self, directory, role, sha=None, version=None, asset=b'compiled-public-js', extra=None, entrypoint=None, env=None, diff_id=None):
        sha = sha or self.sha
        version = version or self.version
        release = {
            'schema_version': 1, 'source_revision': sha,
            'lockfiles': {name: hashlib.sha256((self.source / name).read_bytes()).hexdigest() for name in ('composer.lock', 'package-lock.json')},
            'wayfinder_sha256': 'c' * 64,
            'files': {'manifest.json': hashlib.sha256(b'{}').hexdigest(), 'assets/app.js': hashlib.sha256(asset).hexdigest()},
        }
        files = {'app/public/build/manifest.json': b'{}', 'app/public/build/assets/app.js': asset, 'app/public/build/release.json': encoded(release)} | (extra or {})
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode='w') as layer:
            for name, content in files.items():
                member = tarfile.TarInfo(name)
                member.size = len(content)
                layer.addfile(member, io.BytesIO(content))
        layer_bytes = gzip.compress(buffer.getvalue(), mtime=0)
        config_bytes = encoded({'architecture': 'amd64', 'os': 'linux', 'config': {
            'User': 'www-data:www-data' if role == 'php' else 'nginx:nginx',
            'Entrypoint': entrypoint or ['/usr/local/bin/app-entrypoint' if role == 'php' else '/usr/local/bin/tls-entrypoint'],
            'Labels': {'org.opencontainers.image.revision': sha, 'org.opencontainers.image.version': version},
            'Env': ['APP_ENV=production'] if env is None else env,
        }, 'rootfs': {'type': 'layers', 'diff_ids': [diff_id or digest(buffer.getvalue())]}})
        descriptor = lambda content, media: {'digest': digest(content), 'size': len(content), 'mediaType': media}
        manifest_bytes = encoded({'schemaVersion': 2, 'mediaType': 'application/vnd.oci.image.manifest.v1+json',
            'config': descriptor(config_bytes, 'application/vnd.oci.image.config.v1+json'),
            'layers': [descriptor(layer_bytes, 'application/vnd.oci.image.layer.v1.tar+gzip')]})
        index_bytes = encoded({'schemaVersion': 2, 'manifests': [descriptor(manifest_bytes, 'application/vnd.oci.image.manifest.v1+json')]})
        contents = {'oci-layout': encoded({'imageLayoutVersion': '1.0.0'}), 'index.json': index_bytes}
        for content in (config_bytes, layer_bytes, manifest_bytes):
            contents['blobs/sha256/' + digest(content).split(':')[1]] = content
        with tarfile.open(directory / f'{role}.oci.tar', 'w') as archive:
            for name, content in contents.items():
                member = tarfile.TarInfo(name)
                member.size = len(content)
                archive.addfile(member, io.BytesIO(content))
        return {'manifest': digest(manifest_bytes), 'config': digest(config_bytes), 'contents': contents}

    def test_manifest_records_actual_oci_manifest_not_image_config_id(self):
        record = self.oci(self.bundle, 'php')
        manifest = create_manifest(self.bundle, self.source, self.sha, self.version)
        self.assertEqual(manifest['images']['php']['digest'], record['manifest'])
        self.assertNotEqual(manifest['images']['php']['digest'], record['config'])
        self.assertEqual(manifest['source_revision'], self.sha)
        self.assertEqual(manifest['nginx_target'], 'nginx-tls')
        self.assertEqual(manifest['tags'], ['release-' + self.sha, self.version])
        self.assertEqual(verify_bundle(self.bundle)['images'], manifest['images'])

    def test_rejects_config_id_substitution_and_modified_archive(self):
        manifest = create_manifest(self.bundle, self.source, self.sha, self.version)
        manifest['images']['php']['digest'] = self.oci(self.bundle, 'php')['config']
        (self.bundle / 'image-release.json').write_text(json.dumps(manifest))
        with self.assertRaises(ValueError):
            verify_bundle(self.bundle)
        create_manifest(self.bundle, self.source, self.sha, self.version)
        with (self.bundle / 'php.oci.tar').open('ab') as archive:
            archive.write(b'tampered')
        with self.assertRaises(ValueError):
            verify_bundle(self.bundle)

    def test_rejects_source_version_asset_or_lock_mismatch(self):
        cases = [{'sha': 'b' * 40}, {'version': 'v9.9.9'}, {'asset': b'different-assets'}]
        for case in cases:
            with self.subTest(case=case):
                self.oci(self.bundle, 'nginx', **case)
                with self.assertRaises(ValueError):
                    create_manifest(self.bundle, self.source, self.sha, self.version)
        self.oci(self.bundle, 'nginx')
        (self.source / 'composer.lock').write_text('different-lock')
        with self.assertRaises(ValueError):
            create_manifest(self.bundle, self.source, self.sha, self.version)

    def test_rejects_plain_http_nginx_and_private_files(self):
        for case in [{'entrypoint': ['nginx']}, {'extra': {'app/.env': b'PRIVATE_ENV_CANARY'}}]:
            with self.subTest(case=case):
                self.oci(self.bundle, 'nginx', **case)
                with self.assertRaises(ValueError):
                    create_manifest(self.bundle, self.source, self.sha, self.version)

    def test_rejects_runtime_node_baked_vite_values_and_corrupt_rootfs(self):
        cases = [{'extra': {'usr/local/bin/node': b'build-only binary'}}, {'env': ['VITE_PRIVATE_TOKEN=private']}, {'diff_id': 'sha256:' + 'f' * 64}]
        for case in cases:
            with self.subTest(case=case):
                self.oci(self.bundle, 'php', **case)
                with self.assertRaises(ValueError):
                    create_manifest(self.bundle, self.source, self.sha, self.version)

    def test_recovers_distinct_predecessor_and_refuses_missing_previous_archive(self):
        previous = self.root / 'previous'
        previous.mkdir()
        for role in ('php', 'nginx'):
            self.oci(previous, role, sha='b' * 40, version='v1.2.2')
        create_manifest(previous, self.source, 'b' * 40, 'v1.2.2')
        manifest = create_manifest(self.bundle, self.source, self.sha, self.version, previous)
        self.assertEqual(manifest['previous']['source_revision'], 'b' * 40)
        self.assertEqual(verify_bundle(self.bundle, previous)['previous'], manifest['previous'])
        (previous / 'php.oci.tar').unlink()
        with self.assertRaises(ValueError):
            verify_bundle(self.bundle, previous)

    def test_rejects_malformed_semver_sha_and_archive_paths(self):
        for version, sha in [('latest', self.sha), ('v01.2.3', self.sha), (self.version, 'main')]:
            with self.subTest(version=version, sha=sha), self.assertRaises(ValueError):
                create_manifest(self.bundle, self.source, sha, version)
        original = self.oci(self.bundle, 'php')['contents']
        with tarfile.open(self.bundle / 'php.oci.tar', 'w') as archive:
            for name, content in (original | {'../outside': b'invalid'}).items():
                member = tarfile.TarInfo(name)
                member.size = len(content)
                archive.addfile(member, io.BytesIO(content))
        with self.assertRaises(ValueError):
            create_manifest(self.bundle, self.source, self.sha, self.version)


if __name__ == '__main__':
    unittest.main()
