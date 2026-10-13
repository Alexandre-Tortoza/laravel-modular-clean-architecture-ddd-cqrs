"""Execute the actual host promotion against disposable Docker/Pg/TLS, without AWS."""

import base64
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile
import uuid

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'infra/aws/scripts'))
from deploy_release import DockerRuntime, HostState, contract_hash, deploy


def command(arguments, **options):
    result = subprocess.run(arguments, capture_output=True, check=False, **options)
    if result.returncode: raise RuntimeError('Disposable promotion fixture failed; command output withheld')
    return result.stdout


class FixtureRuntime(DockerRuntime):
    def __init__(self, contract, compose, directory, images, revision, overlay):
        super().__init__(contract, compose, directory)
        self.images, self.revision, self.overlay = images, revision, overlay
        self.fail_migration = False
        self.break_candidate_database = False
        self.migrations = 0
    def command(self, arguments, **options):
        if arguments[:2] == ['docker', 'compose']:
            arguments = arguments[:6] + ['--file', str(self.overlay)] + arguments[6:]
        return super().command(arguments, **options)
    def pull(self, selected):
        # Local image transport is the only substituted production boundary.
        # No registry login/pull/publication occurs in the fixture.
        for role, image in self.images.items():
            info = json.loads(self.command(['docker', 'image', 'inspect', image]))[0]
            if info['Config']['Labels']['org.opencontainers.image.revision'] != self.revision:
                raise ValueError('Fixture image source differs')
            raw = self.command(['docker', 'run', '--rm', '--network', 'none', '--entrypoint', '/bin/cat', image, '/app/public/build/release.json'])
            if contract_hash(json.loads(raw)) != selected['assets_sha256']:
                raise ValueError('Fixture PHP/Nginx asset provenance differs')
    def compose_command(self, selected, arguments):
        self.environment.update(PHP_IMAGE=self.images['php'], NGINX_IMAGE=self.images['nginx'], SOURCE_REVISION=self.revision)
        return self.command(['docker', 'compose', '--project-name', self.contract['project'], '--file', str(self.compose), *arguments])
    def migrate(self, selected):
        self.migrations += 1
        if self.fail_migration:
            return self.compose_command(selected, ['run', '--rm', '--no-deps', '-T', '--entrypoint', 'php', 'migration', '-r', 'exit(23);'])
        return super().migrate(selected)
    def start_web(self, selected):
        if self.break_candidate_database:
            runtime_file = Path(self.contract['compose_environment']['RUNTIME_ENV_FILE'])
            runtime_file.write_text(runtime_file.read_text().replace('DB_HOST=pgsql\n', 'DB_HOST=unreachable.fixture.invalid\n'))
            runtime_file.chmod(0o600)
        return super().start_web(selected)
    def login(self): raise RuntimeError('AWS login is forbidden in this fixture')


def main():
    if len(sys.argv) != 4: raise ValueError('Provide locally loaded PHP/Nginx fixture images and exact source SHA')
    images, revision = {'php': sys.argv[1], 'nginx': sys.argv[2]}, sys.argv[3]
    project = 'deployment-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=project + '-', dir=os.environ.get('RUNNER_TEMP')) as temporary:
        directory = Path(temporary)
        directory.chmod(0o755)
        for name in ('uploads', 'acme', 'tls', 'auth', 'state'):
            (directory / name).mkdir(mode=0o700 if name in ('auth', 'state') else 0o755)
        os.chown(directory / 'uploads', 33, 33)
        for name in ('private', 'public'):
            child = directory / 'uploads' / name
            child.mkdir(mode=0o750)
            os.chown(child, 33, 33)
        domain = 'deployment.smoke.invalid'
        ca, key = directory / 'ca.pem', directory / 'key.pem'
        command(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-subj', '/CN=' + domain,
                 '-addext', 'subjectAltName=DNS:' + domain, '-addext', 'extendedKeyUsage=serverAuth', '-keyout', str(key), '-out', str(ca)])
        command(['python3', str(ROOT / 'infra/aws/https/install-certificate.py'), '--domain', domain, '--mechanism', 'provided',
                 '--fullchain', str(ca), '--private-key', str(key), '--directory', str(directory / 'tls'), '--nginx-gid', '101'])
        ca.chmod(0o644)
        runtime_file = directory / 'runtime.env'
        password = secrets.token_hex(24)
        runtime_file.write_text('APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:' + base64.b64encode(os.urandom(32)).decode() + '\nAPP_URL=https://' + domain + '\nDB_CONNECTION=pgsql\nDB_HOST=pgsql\nDB_PORT=5432\nDB_DATABASE=fixture\nDB_USERNAME=fixture\nDB_PASSWORD=' + password + '\nDB_SSLMODE=disable\nSESSION_DRIVER=database\nCACHE_STORE=database\nQUEUE_CONNECTION=database\nSESSION_SECURE_COOKIE=true\nLOG_CHANNEL=stderr\n')
        runtime_file.chmod(0o600)
        overlay = directory / 'fixture.yaml'
        overlay.write_text('services:\n  pgsql:\n    image: postgres:16.15-bookworm@sha256:0ea6700a3b4f0ae6ce746519073558aed4d88a79d8d07622a9a644946c7319c4\n    user: postgres\n    environment:\n      POSTGRES_DB: fixture\n      POSTGRES_USER: fixture\n      POSTGRES_PASSWORD: ' + password + '\n    networks: [app]\n    volumes: ["fixture-db:/var/lib/postgresql/data"]\n    healthcheck:\n      test: ["CMD", "pg_isready", "-U", "fixture"]\n      interval: 2s\n      timeout: 5s\n      retries: 30\nvolumes:\n  fixture-db:\n')
        overlay.chmod(0o600)
        contract = dict(php_repository='fixture.invalid/php', nginx_repository='fixture.invalid/nginx', project=project, tls_ca_bundle=str(ca), compose_environment=dict(RUNTIME_ENV_FILE=str(runtime_file), UPLOAD_DIRECTORY=str(directory / 'uploads'), DB_CA_BUNDLE=str(ca), TLS_DOMAIN=domain, TLS_CERTIFICATE_MECHANISM='provided', TLS_CERTIFICATE_DIRECTORY=str(directory / 'tls'), TLS_ACME_WEBROOT=str(directory / 'acme'), TLS_BIND_IPV4='127.0.0.1'))
        runtime = FixtureRuntime(contract, ROOT / 'compose.production.release.yaml', directory / 'auth', images, revision, overlay)
        assets = json.loads(runtime.command(['docker', 'run', '--rm', '--network', 'none', '--entrypoint', '/bin/cat', images['php'], '/app/public/build/release.json']))
        selected = dict(source_revision=revision, version='v0.0.1', php_image='fixture.invalid/php@sha256:' + 'a'*64, nginx_image='fixture.invalid/nginx@sha256:' + 'b'*64, manifest_sha256='c'*64, assets_sha256=contract_hash(assets))
        request = dict(schema_version=1, contract_sha256=contract_hash(contract), release=selected, previous=None)
        store = HostState(directory / 'state')
        try:
            runtime.compose_command(selected, ['up', '-d', '--wait', '--wait-timeout', '90', 'pgsql'])
            receipt = deploy(request, contract, store, runtime)
            if receipt['release'] != selected or runtime.migrations != 1: raise ValueError('Promotion receipt/migration differs')
            deploy(request, contract, store, runtime)
            if runtime.migrations != 1: raise ValueError('A completed retry repeated migration')
            for service in ('php', 'worker', 'scheduler'):
                container = runtime.compose_command(selected, ['ps', '-q', service]).decode().strip()
                info = json.loads(runtime.command(['docker', 'inspect', container]))[0]
                mounts = [mount for mount in info['Mounts'] if mount['Destination'] == '/app/storage/app']
                if len(mounts) != 1 or mounts[0]['Source'] != str(directory / 'uploads'): raise ValueError('A process lost the shared EBS fixture bind')
            runtime.compose_command(selected, ['run', '--rm', '--no-deps', '-T', '--entrypoint', 'php', 'migration', '-r', 'if (!is_writable("/app/storage/app")) exit(1); file_put_contents("/app/storage/app/deploy-shared", "retained");'])
            if (directory / 'uploads/deploy-shared').read_text() != 'retained': raise ValueError('Migration did not use the retained upload bind')
            # Repeated failure leaves the successful receipt intact and web ready.
            failed = selected | dict(source_revision='b'*40, version='v0.0.2', manifest_sha256='d'*64)
            runtime.fail_migration = True
            try: deploy(request | dict(release=failed, previous=selected), contract, store, runtime)
            except RuntimeError: pass
            else: raise ValueError('Migration failure was accepted')
            runtime.ready(selected)
            if store.read()['current'] != receipt: raise ValueError('Failed migration overwrote the receipt')
            runtime.fail_migration = False
            # The database becomes unreachable only after migration has succeeded.
            runtime.break_candidate_database = True
            try: deploy(request | dict(release=failed, previous=selected), contract, store, runtime)
            except RuntimeError: pass
            else: raise ValueError('Readiness failure was accepted')
            if store.read()['current'] != receipt or store.read()['pending']['release'] != failed: raise ValueError('Failed readiness discarded recovery state')
            active = runtime.compose_command(selected, ['ps', '--status', 'running', '--services']).decode().splitlines()
            if set(active) != {'pgsql'}: raise ValueError('Unready candidate or processes continued running')
            print('Disposable deployment passed: native migration/readiness, idempotence, four shared binds, scheduler singleton, failure stop and preserved receipt; no AWS operation.')
        finally:
            runtime.compose_command(selected, ['down', '--volumes', '--timeout', '30'])


if __name__ == '__main__': main()
