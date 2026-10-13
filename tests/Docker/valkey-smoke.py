"""Disposable real Valkey TLS/ACL contracts; no AWS service or credential read."""

import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile
import uuid
from unittest.mock import patch


def checked(command, **options):
    result = subprocess.run(command, capture_output=True, text=True, check=False, timeout=180, **options)
    if result.returncode:
        raise ValueError('Disposable Valkey command failed; diagnostics redacted')
    return result.stdout


def main():
    if len(sys.argv) != 2:
        raise ValueError('Select the locally built production runtime fixture image')
    root = Path(__file__).resolve().parents[2]
    spec = importlib.util.spec_from_file_location('runtime_secrets', root / 'infra/aws/scripts/runtime_secrets.py')
    delivery = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(delivery)
    project = 'valkey-fixture-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=project) as temporary:
        directory = Path(temporary)
        private = directory / 'private'
        private.mkdir(mode=0o700)
        runtime = private / 'production.env'
        certificates = directory / 'certificates'
        certificates.mkdir(mode=0o755)
        for name, subject, extra in [('ca', '/CN=Disposable Valkey fixture CA', []),
                                     ('server', '/CN=fixture.cache.amazonaws.com', ['-addext', 'subjectAltName=DNS:fixture.cache.amazonaws.com'])]:
            checked(['openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(certificates / f'{name}.key'),
                     '-out', str(certificates / f'{name}.csr'), '-subj', subject, *extra])
        checked(['openssl', 'x509', '-req', '-in', str(certificates / 'ca.csr'), '-signkey', str(certificates / 'ca.key'),
                 '-days', '1', '-out', str(certificates / 'ca.crt'), '-extfile', '/dev/stdin'],
                input='basicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n')
        extensions = certificates / 'extensions.cnf'
        extensions.write_text('subjectAltName=DNS:fixture.cache.amazonaws.com\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n')
        checked(['openssl', 'x509', '-req', '-in', str(certificates / 'server.csr'), '-CA', str(certificates / 'ca.crt'),
                 '-CAkey', str(certificates / 'ca.key'), '-CAcreateserial', '-days', '1', '-extfile', str(extensions),
                 '-out', str(certificates / 'server.crt')])
        certificates.joinpath('server.key').chmod(0o600)
        password = secrets.token_urlsafe(32)
        acl = directory / 'users.acl'
        acl.write_text('user default off\nuser fixture_app on #' + hashlib.sha256(password.encode()).hexdigest() +
                       ' ~valkey-smoke-* -@all +@read +@write +@scripting +@connection -@dangerous\n')
        acl.chmod(0o600)
        server = directory / 'valkey.conf'
        server.write_text('port 0\ntls-port 6379\ntls-cert-file /run/certificates/server.crt\n'
                          'tls-key-file /run/certificates/server.key\ntls-ca-cert-file /run/certificates/ca.crt\n'
                          'tls-auth-clients no\naclfile /run/users.acl\nsave ""\nappendonly no\n')
        uid, gid = os.getuid(), os.getgid()
        compose = directory / 'compose.json'
        compose.write_text(json.dumps({'services': {
            'valkey': {'image': 'valkey/valkey:8.1.10-alpine@sha256:081c2f5cb575efc901aa80ff9cdbd1ec6a301682fd35e1ebb4b0990a4a4a8507',
                       'user': f'{uid}:{gid}', 'entrypoint': ['valkey-server'], 'command': ['/run/valkey.conf'],
                       'volumes': [f'{certificates}:/run/certificates:ro', f'{server}:/run/valkey.conf:ro', f'{acl}:/run/users.acl:ro'],
                       'read_only': True, 'tmpfs': [f'/data:uid={uid},gid={gid},mode=0700'], 'cap_drop': ['ALL'],
                       'security_opt': ['no-new-privileges:true'],
                       'networks': {'app': {'aliases': ['fixture.cache.amazonaws.com', 'mismatch.cache.amazonaws.com']}}},
            'php': {'image': sys.argv[1], 'env_file': [{'path': str(runtime), 'format': 'raw'}],
                    'environment': {'REDIS_PREFIX': 'valkey-smoke-', 'CACHE_PREFIX': 'valkey-smoke-cache-'},
                    'volumes': [f'{certificates / "ca.crt"}:/etc/ssl/certs/ca-certificates.crt:ro',
                                f'{root / "tests/Docker/valkey-fixture.php"}:/run/valkey-fixture.php:ro',
                                'app-storage:/app/storage/app'], 'networks': ['app'], 'cap_drop': ['ALL'],
                    'security_opt': ['no-new-privileges:true']}},
            'volumes': {'app-storage': {}}, 'networks': {'app': {'internal': True}}}))
        config = {'secret_arn': 'arn:aws:secretsmanager:us-east-1:123456789012:secret:fixture-app-Ab1234',
                  'region': 'us-east-1', 'database_username': 'fixture_app',
                  'environment': {'APP_URL': 'https://fixture.invalid', 'DB_HOST': 'fixture.rds.invalid',
                                  'DB_DATABASE': 'fixture_app', 'DB_SSLROOTCERT': '/run/rds/ca.pem'},
                  'valkey': {'host': 'fixture.cache.amazonaws.com', 'username': 'fixture_app',
                             'cache_store': 'database', 'session_driver': 'database', 'queue_connection': 'database'}}
        secret = {'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(), 'DB_USERNAME': 'fixture_app',
                  'DB_PASSWORD': secrets.token_urlsafe(32), 'REDIS_PASSWORD': password}
        def prepare():
            with patch.object(delivery, 'fetch', return_value=secret):
                delivery.deliver(config, runtime, '/not-called/aws')
        prepare()
        command = ['docker', 'compose', '--env-file', '/dev/null', '--project-name', project, '-f', str(compose)]
        try:
            checked(command + ['up', '-d', 'valkey'])
            for attempt in range(30):
                ready = subprocess.run(command + ['exec', '-T', 'valkey', 'valkey-cli', '--tls', '--cacert', '/run/certificates/ca.crt',
                                                  '-h', 'fixture.cache.amazonaws.com', 'ping'], capture_output=True, text=True, check=False)
                if 'NOAUTH' in ready.stdout:
                    break
                checked(['sleep', '1'])
            else:
                raise ValueError('TLS-only Valkey did not reach its unauthenticated rejection boundary')
            for mode, field in [('cache', 'cache_store'), ('session', 'session_driver'), ('queue', 'queue_connection')]:
                config['valkey'].update(cache_store='database', session_driver='database', queue_connection='database')
                config['valkey'][field] = 'redis'
                prepare()
                checked(command + ['run', '--rm', '--no-deps', '-T', '--entrypoint', 'sh', 'php', '-ec',
                                   f'php artisan config:cache >/dev/null; exec php /run/valkey-fixture.php {mode}'])
                print(f'Native {mode} TLS/ACL round trip passed with the other drivers unchanged')
            for overrides in [{'REDIS_PASSWORD': 'wrong-disposable-credential'},
                              {'REDIS_URL': 'rediss://mismatch.cache.amazonaws.com:6379'},
                              {'REDIS_URL': 'redis://fixture.cache.amazonaws.com:6379'}]:
                extra = [argument for key, value in overrides.items() for argument in ['-e', f'{key}={value}']]
                checked(command + ['run', '--rm', '--no-deps', '-T', *extra, '--entrypoint', 'sh', 'php', '-ec',
                                   'php artisan config:cache >/dev/null; exec php /run/valkey-fixture.php rejected'])
            print('Wrong authentication, mismatched TLS identity and plaintext transport all failed closed; no AWS contacted')
        finally:
            checked(command + ['down', '--volumes', '--remove-orphans'])
            for kind in ['container', 'volume', 'network']:
                remaining = checked(['docker', kind, 'ls', *(['-a'] if kind == 'container' else []), '--filter', f'label=com.docker.compose.project={project}', '--format', '{{.ID}}'])
                if remaining.strip():
                    raise ValueError('Disposable Valkey cleanup leaked project resources')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.SubprocessError):
        print('Disposable Valkey smoke failed; diagnostics redacted', file=sys.stderr)
        sys.exit(1)
