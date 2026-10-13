"""Verify and retain OCI release artifacts. No registry credentials or publication."""

import argparse
import gzip
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import tarfile


MANIFEST = 'application/vnd.oci.image.manifest.v1+json'
INDEX = 'application/vnd.oci.image.index.v1+json'
CONFIG = 'application/vnd.oci.image.config.v1+json'
LAYERS = {'application/vnd.oci.image.layer.v1.tar', 'application/vnd.oci.image.layer.v1.tar+gzip'}
ROLES = {'php': ('runtime', '/usr/local/bin/app-entrypoint'), 'nginx': ('nginx-tls', '/usr/local/bin/tls-entrypoint')}
LOCKFILES = ('composer.lock', 'package-lock.json')


def sha256(content):
    return hashlib.sha256(content).hexdigest()


def file_hash(path):
    with Path(path).open('rb') as stream:
        result = hashlib.file_digest(stream, 'sha256').hexdigest()
    return result


def strict_json(content):
    def unique(pairs):
        result = {}
        for name, value in pairs:
            if name in result:
                raise ValueError('Duplicate JSON property.')
            result[name] = value
        return result
    return json.loads(content, object_pairs_hook=unique)


def validate_identity(revision, version):
    if not re.fullmatch(r'[a-f0-9]{40}', revision or ''):
        raise ValueError('A full source Git SHA is required.')
    if not re.fullmatch(r'v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', version or ''):
        raise ValueError('A stable vMAJOR.MINOR.PATCH release is required.')


def normalized(name):
    while name.startswith('./'):
        name = name[2:]
    path = PurePosixPath(name)
    if path.is_absolute() or '..' in path.parts:
        raise ValueError('Archive path escapes its content root.')
    return str(path).rstrip('/')


class DigestReader:
    def __init__(self, stream):
        self.stream = stream
        self.digest = hashlib.sha256()
        self.size = 0

    def read(self, size=-1):
        content = self.stream.read(size)
        self.size += len(content)
        if self.size > 2 * 1024**3:
            raise ValueError('Image layer exceeds the supported size limit.')
        self.digest.update(content)
        return content


class OciArchive:
    def __init__(self, path):
        self.archive = tarfile.open(path, 'r:*')
        try:
            self.load_layout()
        except Exception:
            self.archive.close()
            raise

    def load_layout(self):
        self.members = {}
        for member in self.archive:
            name = normalized(member.name)
            if member.isdir() and name in ('.', 'blobs', 'blobs/sha256'):
                continue
            if not member.isfile() or not re.fullmatch(r'(oci-layout|index\.json|blobs/sha256/[a-f0-9]{64})', name):
                raise ValueError('OCI layout contains an unsupported path or link.')
            if name in self.members:
                raise ValueError('OCI layout contains duplicate entries.')
            self.members[name] = member
        if self.document('oci-layout') != {'imageLayoutVersion': '1.0.0'}:
            raise ValueError('Unsupported OCI layout version.')

    def close(self):
        self.archive.close()

    def document(self, name):
        if name not in self.members or self.members[name].size > 8 * 1024**2:
            raise ValueError('OCI metadata is missing or oversized.')
        with self.archive.extractfile(self.members[name]) as stream:
            return strict_json(stream.read())

    def blob(self, descriptor):
        digest = descriptor.get('digest', '')
        if not re.fullmatch(r'sha256:[a-f0-9]{64}', digest) or descriptor.get('urls'):
            raise ValueError('OCI descriptor must reference an included sha256 blob.')
        name = 'blobs/sha256/' + digest.split(':')[1]
        member = self.members.get(name)
        if member is None or type(descriptor.get('size')) is not int or descriptor['size'] != member.size:
            raise ValueError('OCI descriptor size or blob is invalid.')
        with self.archive.extractfile(member) as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != digest.split(':')[1]:
                raise ValueError('OCI blob digest does not match its bytes.')
        return name

    def image(self, role, revision, version, platform):
        index = self.document('index.json')
        if index.get('schemaVersion') != 2 or len(index.get('manifests', [])) != 1:
            raise ValueError('Require one OCI release reference per archive.')
        root = index['manifests'][0]
        name = self.blob(root)
        descriptor = root
        image = self.document(name)
        if root.get('mediaType') == INDEX:
            if image.get('schemaVersion') != 2 or len(image.get('manifests', [])) != 1:
                raise ValueError('Require one explicitly selected platform without auxiliary manifests.')
            descriptor = image['manifests'][0]
            image = self.document(self.blob(descriptor))
        if descriptor.get('mediaType') != MANIFEST or image.get('schemaVersion') != 2:
            raise ValueError('The release digest must identify an OCI image manifest/index, not a config ID.')
        config_descriptor = image['config']
        if config_descriptor.get('mediaType') != CONFIG:
            raise ValueError('Invalid OCI image configuration.')
        config = self.document(self.blob(config_descriptor))
        if f"{config['os']}/{config['architecture']}" != platform:
            raise ValueError('OCI architecture does not match the selected platform.')
        runtime = config.get('config', {})
        labels = runtime.get('Labels', {})
        if labels.get('org.opencontainers.image.revision') != revision or labels.get('org.opencontainers.image.version') != version:
            raise ValueError('OCI source/version provenance differs from the release.')
        if runtime.get('Entrypoint') != [ROLES[role][1]] or runtime.get('User', '').split(':')[0] in ('', 'root', '0'):
            raise ValueError('Incorrect production target or root runtime identity.')
        if any(re.search(r'^(?:VITE_|AWS_ACCESS_KEY_ID=|AWS_SECRET_ACCESS_KEY=|AWS_SESSION_TOKEN=|APP_KEY=|DB_PASSWORD=|COMPOSER_AUTH=)', item) for item in runtime.get('Env', [])):
            raise ValueError('Runtime image contains baked secret/build environment values.')
        layers = image.get('layers', [])
        diff_ids = config.get('rootfs', {}).get('diff_ids', [])
        if not layers or len(layers) != len(diff_ids):
            raise ValueError('OCI root filesystem does not match its layers.')
        assets = {}
        for layer, expected_diff in zip(layers, diff_ids):
            media = layer.get('mediaType')
            if media not in LAYERS:
                raise ValueError('Unsupported OCI layer compression/media type.')
            member = self.members[self.blob(layer)]
            with self.archive.extractfile(member) as compressed:
                stream = gzip.GzipFile(fileobj=compressed) if media.endswith('+gzip') else compressed
                reader = DigestReader(stream)
                with tarfile.open(fileobj=reader, mode='r|') as contents:
                    for entry in contents:
                        path = normalized(entry.name)
                        if path in ('app/.env', 'app/auth.json') or path.startswith(('app/.git/', 'app/node_modules/', 'app/resources/js/', 'app/resources/css/')):
                            raise ValueError('Runtime image contains private files or build sources.')
                        prefix = 'app/public/build/'
                        if not path.startswith(prefix):
                            continue
                        relative = path[len(prefix):]
                        filename = PurePosixPath(relative).name
                        parent = str(PurePosixPath(relative).parent)
                        if filename == '.wh..wh..opq':
                            assets = {name: value for name, value in assets.items() if not name.startswith('' if parent == '.' else parent + '/')}
                        elif filename.startswith('.wh.'):
                            assets.pop(str(PurePosixPath(parent) / filename[4:]), None)
                        elif entry.isdir():
                            continue
                        elif entry.isfile() and entry.size <= 32 * 1024**2:
                            assets[relative] = contents.extractfile(entry).read()
                        else:
                            raise ValueError('Production asset must be a bounded regular file.')
                while reader.read(1024**2):
                    pass
                if 'sha256:' + reader.digest.hexdigest() != expected_diff:
                    raise ValueError('Uncompressed OCI layer digest differs from its config.')
        if 'release.json' not in assets:
            raise ValueError('Production asset provenance is absent.')
        release = strict_json(assets.pop('release.json'))
        if release.get('schema_version') != 1 or release.get('source_revision') != revision:
            raise ValueError('Asset source provenance differs from the OCI image.')
        if not re.fullmatch(r'[a-f0-9]{64}', release.get('wayfinder_sha256', '')):
            raise ValueError('Wayfinder artifact provenance is missing.')
        inventory = {name: sha256(content) for name, content in sorted(assets.items())}
        if release.get('files') != inventory or 'manifest.json' not in inventory or any(re.search(r'(?:^|/)\.|\.(?:map|vue|tsx?|jsx|php|pem|key|env)$', name, re.I) for name in inventory):
            raise ValueError('Asset inventory differs or includes sources/secrets.')
        if set(release.get('lockfiles', {})) != set(LOCKFILES) or any(not re.fullmatch(r'[a-f0-9]{64}', value) for value in release['lockfiles'].values()):
            raise ValueError('Asset dependency lock provenance is invalid.')
        return {'digest': root['digest'], 'config_digest': config_descriptor['digest'], 'target': ROLES[role][0]}, release


def inspect_images(directory, revision, version, platform):
    images, releases = {}, []
    if platform not in ('linux/amd64', 'linux/arm64'):
        raise ValueError('Select an explicitly supported Linux platform.')
    for role in ROLES:
        path = Path(directory) / f'{role}.oci.tar'
        if path.is_symlink() or not path.is_file():
            raise ValueError('Release archive is absent or is a symlink.')
        archive = OciArchive(path)
        try:
            image, release = archive.image(role, revision, version, platform)
        finally:
            archive.close()
        images[role] = image | {'archive': path.name, 'archive_sha256': file_hash(path)}
        releases.append(release)
    if releases[0] != releases[1]:
        raise ValueError('PHP and HTTPS Nginx must share exactly the same source, lockfiles and asset inventory.')
    return images, releases[0]


def read_current(directory):
    path = Path(directory) / 'image-release.json'
    if path.is_symlink():
        raise ValueError('Release manifest must not be a symlink.')
    manifest = strict_json(path.read_bytes())
    if manifest.get('schema_version') != 1 or manifest.get('nginx_target') != 'nginx-tls':
        raise ValueError('Unsupported release schema or production TLS target.')
    validate_identity(manifest.get('source_revision'), manifest.get('version'))
    images, assets = inspect_images(directory, manifest['source_revision'], manifest['version'], manifest.get('platform'))
    if manifest.get('images') != images or manifest.get('assets') != assets or manifest.get('tags') != ['release-' + manifest['source_revision'], manifest['version']]:
        raise ValueError('Release record differs from the complete OCI archive bytes/provenance.')
    return manifest


def predecessor(directory):
    manifest = read_current(directory)
    return {key: manifest[key] for key in ('source_revision', 'version', 'platform', 'images')} | {'manifest_sha256': file_hash(Path(directory) / 'image-release.json')}


def create_manifest(directory, source_directory, revision, version, previous_directory=None, platform='linux/amd64'):
    validate_identity(revision, version)
    images, assets = inspect_images(directory, revision, version, platform)
    expected_locks = {name: file_hash(Path(source_directory) / name) for name in LOCKFILES}
    if assets['lockfiles'] != expected_locks:
        raise ValueError('Dependency lock hashes differ from the selected source checkout.')
    previous = predecessor(previous_directory) if previous_directory is not None else None
    if previous is not None and (previous['version'] == version or previous['platform'] != platform):
        raise ValueError('Predecessor must be a distinct release on the same platform.')
    manifest = dict(schema_version=1, source_revision=revision, version=version, platform=platform, nginx_target='nginx-tls', tags=['release-' + revision, version], images=images, assets=assets, previous=previous)
    (Path(directory) / 'image-release.json').write_text(json.dumps(manifest, indent=2, sort_keys=True) + '\n')
    return manifest


def verify_bundle(directory, previous_directory=None):
    manifest = read_current(directory)
    expected = predecessor(previous_directory) if previous_directory is not None else None
    if manifest.get('previous') != expected:
        raise ValueError('The exact predecessor manifest and both recoverable archives are required.')
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['create', 'verify'])
    parser.add_argument('--directory', type=Path, required=True)
    parser.add_argument('--source', type=Path)
    parser.add_argument('--source-revision')
    parser.add_argument('--version')
    parser.add_argument('--platform', default='linux/amd64')
    parser.add_argument('--previous', type=Path)
    args = parser.parse_args()
    if args.command == 'create':
        if args.source is None:
            parser.error('--source is required when creating a manifest')
        manifest = create_manifest(args.directory, args.source, args.source_revision, args.version, args.previous, args.platform)
    else:
        manifest = verify_bundle(args.directory, args.previous)
    print(f"Verified OCI {manifest['version']} at {manifest['source_revision']}; no publication occurred.")


if __name__ == '__main__':
    main()
