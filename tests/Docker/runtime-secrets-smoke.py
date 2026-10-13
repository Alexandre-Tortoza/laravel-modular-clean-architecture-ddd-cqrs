"""Disposable PHP/Compose credential round trip with a local fake AWS CLI."""

import base64
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import uuid


def checked(command, **options):
    result = subprocess.run(command, capture_output=True, text=True, check=False, **options)
    if result.returncode:
        raise ValueError('Disposable runtime command failed')
    return result.stdout


def main():
    if len(sys.argv) != 2:
        raise ValueError('Select the locally built production runtime fixture image')
    root = Path(__file__).resolve().parents[2]
    project = 'runtime-secret-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=project) as temporary:
        directory = Path(temporary)
        private = directory / 'private'
        private.mkdir(mode=0o700)
        runtime = private / 'production.env'
        fixture = {'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(),
                   'DB_USERNAME': 'fixture_app', 'DB_PASSWORD': 'fixture $HOME ${UNDEFINED} $$ "quote" # slash\\ tail'}
        response = json.dumps({'SecretString': json.dumps(fixture)})
        fake = directory / 'aws-fixture'
        fake.write_text('#!' + sys.executable + '\nimport sys\n'
                        'assert sys.argv[1:3] == ["secretsmanager", "get-secret-value"]\n'
                        'assert "AWSCURRENT" in sys.argv\n'
                        'print(' + repr(response) + ')\n')
        fake.chmod(0o700)
        config = directory / 'metadata.json'
        config.write_text(json.dumps({'secret_arn': 'arn:aws:secretsmanager:us-east-1:123456789012:secret:fixture-app-Ab1234',
                                     'region': 'us-east-1', 'database_username': 'fixture_app',
                                     'environment': {'APP_URL': 'https://fixture.invalid', 'DB_HOST': 'fixture.rds.invalid',
                                                     'DB_DATABASE': 'fixture_app', 'DB_SSLROOTCERT': '/run/rds/ca.pem'}}))
        checked([sys.executable, str(root / 'infra/aws/scripts/runtime_secrets.py'), '--config', str(config),
                 '--output', str(runtime), '--aws-cli', str(fake), '--execute'])
        empty = directory / 'empty.env'
        empty.touch()
        image = sys.argv[1]
        environment = dict(os.environ, RUNTIME_ENV_FILE=str(runtime), PHP_IMAGE=image, SOURCE_REVISION='a' * 40,
                           PRODUCTION_RUNTIME_ENV_FILE=str(runtime), PRODUCTION_PHP_IMAGE=image, PRODUCTION_NGINX_TLS_IMAGE=image,
                           PRODUCTION_UPLOAD_DIRECTORY=str(private), TLS_DOMAIN='fixture.invalid', TLS_CERTIFICATE_MECHANISM='provided',
                           TLS_CERTIFICATE_DIRECTORY=str(private), TLS_ACME_WEBROOT=str(private), TLS_BIND_IPV4='127.0.0.1')
        command = ['docker', 'compose', '--env-file', str(empty), '--project-name', project,
                   '-f', str(root / 'compose.production.https.yaml'),
                   '-f', str(root / 'compose.production.processes.yaml'), '-f', str(root / 'compose.production.migrations.yaml'),
                   '--profile', 'release']
        override = directory / 'fixture.yaml'
        override.write_text('services:\n  php:\n    volumes: !override ["app-storage:/app/storage/app"]\n')
        command += ['-f', str(override)]
        try:
            php = 'echo json_encode(array_map(fn($key) => hash("sha256", getenv($key)), ["APP_KEY", "DB_PASSWORD", "DB_USERNAME"]));'
            expected = [hashlib.sha256(fixture[key].encode()).hexdigest() for key in ['APP_KEY', 'DB_PASSWORD', 'DB_USERNAME']]
            for service in ['php', 'worker', 'scheduler', 'migration']:
                actual = checked(command + ['run', '--rm', '--no-deps', '-T', '--entrypoint', 'php', service, '-r', php], env=environment)
                if json.loads(actual) != expected:
                    raise ValueError('Credential round trip changed runtime values')
            native = ('require "/app/vendor/autoload.php"; $app = require "/app/bootstrap/app.php"; '
                      '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
                      'echo json_encode(array_map(fn($value) => hash("sha256", $value), '
                      '[config("app.key"), config("database.connections.pgsql.password"), config("database.connections.pgsql.username")]));')
            for password in ['null', '(TRUE)', '"quoted"', "'quoted'", ' edge spaces ', fixture['DB_PASSWORD']]:
                fixture['DB_PASSWORD'] = password
                response = json.dumps({'SecretString': json.dumps(fixture)})
                fake.write_text('#!' + sys.executable + '\nprint(' + repr(response) + ')\n')
                checked([sys.executable, str(root / 'infra/aws/scripts/runtime_secrets.py'), '--config', str(config),
                         '--output', str(runtime), '--aws-cli', str(fake), '--execute'])
                expected = [hashlib.sha256(fixture[key].encode()).hexdigest() for key in ['APP_KEY', 'DB_PASSWORD', 'DB_USERNAME']]
                for service in ['php', 'worker', 'scheduler', 'migration']:
                    shell = 'php artisan config:cache >/dev/null; exec php -r ' + "'" + native + "'"
                    actual = checked(command + ['run', '--rm', '--no-deps', '-T', '--entrypoint', 'sh', service, '-ec', shell], env=environment)
                    if json.loads(actual) != expected:
                        raise ValueError('Cached native Laravel configuration changed runtime credentials')
            print('Actual PHP getenv and cached Laravel configuration preserved credentials in all four services and six edge cases; no AWS contacted')
        finally:
            checked(command + ['down', '--volumes', '--remove-orphans'], env=environment)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.SubprocessError):
        print('Disposable runtime secrets smoke failed; diagnostics redacted', file=sys.stderr)
        sys.exit(1)
