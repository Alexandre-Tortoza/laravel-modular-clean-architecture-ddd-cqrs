"""Exercise the runtime boundary with a fake AWS CLI; never contact AWS."""

import base64
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).parents[1] / 'scripts/runtime_secrets.py'


class RuntimeSecretsTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.private = self.root / 'private'
        self.private.mkdir(mode=0o700)
        self.destination = self.private / 'production.env'
        self.config = {'secret_arn': 'arn:aws:secretsmanager:us-east-1:123456789012:secret:fixture-app-Ab1234',
                       'region': 'us-east-1', 'database_username': 'fixture_app',
                       'environment': {'APP_URL': 'https://fixture.invalid', 'DB_HOST': 'fixture.rds.invalid',
                                       'DB_DATABASE': 'fixture_app', 'DB_SSLROOTCERT': '/run/rds/ca.pem'}}
        self.secret = {'APP_KEY': 'base64:' + base64.b64encode(b'x' * 32).decode(),
                       'DB_USERNAME': 'fixture_app', 'DB_PASSWORD': 'canary $HOME ${MISSING} "quote" # slash\\ tail'}

    def load(self):
        self.assertTrue(SCRIPT.is_file(), 'Runtime secret delivery is missing')
        spec = importlib.util.spec_from_file_location('runtime_secrets', SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def deliver(self, secret=None):
        module = self.load()
        response = subprocess.CompletedProcess([], 0, json.dumps({'SecretString': json.dumps(secret or self.secret)}), '')
        with patch.object(module.subprocess, 'run', return_value=response) as run:
            module.deliver(self.config, self.destination, '/approved/aws')
        return run

    def test_private_atomic_delivery_uses_only_selected_current_secret(self):
        run = self.deliver()
        self.assertEqual(stat.S_IMODE(self.destination.stat().st_mode), 0o600)
        content = self.destination.read_text()
        self.assertIn('DB_PASSWORD=' + self.secret['DB_PASSWORD'] + '\n', content)
        self.assertIn('APP_ENV=production\nAPP_DEBUG=false\n', content)
        command = run.call_args.args[0]
        self.assertIn(self.config['secret_arn'], command)
        self.assertIn('AWSCURRENT', command)
        self.assertNotIn(self.secret['DB_PASSWORD'], str(run.call_args))
        environment = run.call_args.kwargs['env']
        self.assertEqual(environment['AWS_SHARED_CREDENTIALS_FILE'], '/dev/null')
        self.assertEqual(environment['AWS_CONFIG_FILE'], '/dev/null')
        self.assertEqual(environment['BOTO_CONFIG'], '/dev/null')
        self.assertNotIn('AWS_ACCESS_KEY_ID', environment)
        self.assertEqual(list(self.private.iterdir()), [self.destination])

    def test_database_rotation_preserves_key_and_key_change_preserves_old_file(self):
        self.deliver()
        changed = dict(self.secret, DB_PASSWORD='rotated fixture')
        self.deliver(changed)
        previous = self.destination.read_bytes()
        self.assertIn(b'rotated fixture', previous)
        changed['APP_KEY'] = 'base64:' + base64.b64encode(b'y' * 32).decode()
        with self.assertRaises(ValueError):
            self.deliver(changed)
        self.assertEqual(self.destination.read_bytes(), previous)

    def test_invalid_or_unsafe_values_never_create_a_runtime_file(self):
        for changed in [dict(self.secret, DB_USERNAME='postgres'), dict(self.secret, APP_KEY='invalid'),
                        dict(self.secret, DB_PASSWORD='line\nbreak'), dict(self.secret, DB_PASSWORD=''),
                        dict(self.secret, VITE_PASSWORD='fixture'), dict(self.secret, APP_DEBUG='true'),
                        dict(self.secret, DB_PASSWORD=None), {'APP_KEY': self.secret['APP_KEY']}]:
            with self.subTest(keys=sorted(changed)):
                with self.assertRaises(ValueError):
                    self.deliver(changed)
                self.assertFalse(self.destination.exists())

    def test_insecure_directory_file_and_symlink_are_refused(self):
        module = self.load()
        self.private.chmod(0o755)
        with self.assertRaises(ValueError):
            module.deliver(self.config, self.destination, '/approved/aws')
        self.private.chmod(0o700)
        victim = self.root / 'victim'
        victim.write_text('keep fixture')
        self.destination.symlink_to(victim)
        with self.assertRaises((ValueError, OSError)):
            self.deliver()
        self.assertEqual(victim.read_text(), 'keep fixture')
        self.destination.unlink()
        self.destination.write_text('APP_KEY=' + self.secret['APP_KEY'] + '\n')
        self.destination.chmod(0o644)
        with self.assertRaises(ValueError):
            self.deliver()

    def test_fetch_failure_retains_previous_file_and_cli_redacts_all_errors(self):
        self.deliver()
        previous = self.destination.read_bytes()
        module = self.load()
        for result in [subprocess.CompletedProcess([], 1, '', 'canary-secret'),
                       subprocess.CompletedProcess([], 0, 'canary-secret', ''),
                       subprocess.CompletedProcess([], 0, json.dumps({'SecretBinary': 'fixture'}), '')]:
            with patch.object(module.subprocess, 'run', return_value=result):
                with self.assertRaises(ValueError):
                    module.deliver(self.config, self.destination, '/approved/aws')
            self.assertEqual(self.destination.read_bytes(), previous)
        config = self.root / 'config.json'
        config.write_text(json.dumps(self.config))
        result = subprocess.run([sys.executable, str(SCRIPT), '--config', str(config), '--output', str(self.destination),
                                 '--aws-cli', '/missing/canary-secret', '--execute'], capture_output=True, text=True)
        self.assertEqual(result.returncode, 1)
        self.assertNotIn('canary-secret', result.stdout + result.stderr)

    def test_default_cli_is_offline_and_does_not_generate_a_key(self):
        self.load()
        config = self.root / 'config.json'
        config.write_text(json.dumps(self.config))
        result = subprocess.run([sys.executable, str(SCRIPT), '--config', str(config), '--output', str(self.destination),
                                 '--aws-cli', '/missing/aws'], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(self.destination.exists())
        self.assertNotIn(self.config['secret_arn'], result.stdout)

    def test_invalid_public_configuration_cannot_override_production_or_inject_arguments(self):
        module = self.load()
        for config in [dict(self.config, secret_arn='*'), dict(self.config, region='--unsafe'),
                       dict(self.config, database_username='rdsadmin'),
                       dict(self.config, environment=dict(self.config['environment'], APP_DEBUG='true')),
                       dict(self.config, environment=dict(self.config['environment'], DB_HOST='host\nAPP_KEY=bad'))]:
            with self.assertRaises(ValueError):
                module.validate_config(config)

    def test_timeout_and_atomic_replace_failure_retain_previous_file(self):
        self.deliver()
        previous = self.destination.read_bytes()
        module = self.load()
        with patch.object(module.subprocess, 'run', side_effect=subprocess.TimeoutExpired('fixture', 30)):
            with self.assertRaises(subprocess.TimeoutExpired):
                module.deliver(self.config, self.destination, '/approved/aws')
        response = subprocess.CompletedProcess([], 0, json.dumps({'SecretString': json.dumps(self.secret)}), '')
        with patch.object(module.subprocess, 'run', return_value=response), patch.object(module.os, 'replace', side_effect=OSError('fixture')):
            with self.assertRaises(OSError):
                module.deliver(self.config, self.destination, '/approved/aws')
        self.assertEqual(self.destination.read_bytes(), previous)
        self.assertEqual(list(self.private.iterdir()), [self.destination])

    def test_actual_compose_preserves_credentials_for_worker_scheduler_and_migration(self):
        self.deliver()
        root = SCRIPT.parents[3]
        empty = self.root / 'empty.env'
        empty.touch()
        environment = dict(os.environ, RUNTIME_ENV_FILE=str(self.destination), PHP_IMAGE='fixture.invalid/php@sha256:' + 'a' * 64,
                           SOURCE_REVISION='a' * 40, PRODUCTION_RUNTIME_ENV_FILE=str(self.destination),
                           PRODUCTION_PHP_IMAGE='fixture.invalid/php@sha256:' + 'a' * 64,
                           PRODUCTION_NGINX_TLS_IMAGE='fixture.invalid/nginx@sha256:' + 'b' * 64,
                           PRODUCTION_UPLOAD_DIRECTORY=str(self.private), TLS_DOMAIN='fixture.invalid',
                           TLS_CERTIFICATE_MECHANISM='provided', TLS_CERTIFICATE_DIRECTORY=str(self.private),
                           TLS_ACME_WEBROOT=str(self.private), TLS_BIND_IPV4='127.0.0.1')
        command = ['docker', 'compose', '--env-file', str(empty), '--project-name', 'runtime-secrets-fixture',
                   '-f', str(root / 'compose.production.https.yaml'),
                   '-f', str(root / 'compose.production.processes.yaml'), '-f', str(root / 'compose.production.migrations.yaml'),
                   '--profile', 'release', 'config', '--format', 'json']
        result = subprocess.run(command, env=environment, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, 'Compose runtime configuration failed')
        configuration = json.loads(result.stdout)
        for name in ['php', 'worker', 'scheduler', 'migration']:
            values = configuration['services'][name]['environment']
            # Compose's canonical serializer escapes every dollar for reloading
            # (cmd/compose/config.go). The container smoke checks actual getenv.
            self.assertEqual(values['DB_PASSWORD'].replace('$$', '$'), self.secret['DB_PASSWORD'])
            self.assertEqual(values['APP_KEY'], self.secret['APP_KEY'])
            self.assertEqual(values['DB_SSLMODE'], 'verify-full')


if __name__ == '__main__':
    unittest.main()
