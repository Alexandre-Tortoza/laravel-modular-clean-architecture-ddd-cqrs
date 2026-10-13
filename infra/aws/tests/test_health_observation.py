"""Probe/telemetry contracts using local doubles only; never contact AWS."""

import importlib.util
import copy
import json
from pathlib import Path
import subprocess
import tempfile
import contextlib
import http.server
import http.client
import io
import ssl
import threading
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).parents[1] / 'scripts/observe_health.py'


class HealthObservationTest(unittest.TestCase):
    def load(self):
        self.assertTrue(SCRIPT.is_file(), 'Host observation boundary is missing')
        spec = importlib.util.spec_from_file_location('observe_health', SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def config(self):
        return {'environment': 'fixture', 'region': 'us-east-1', 'app_url': 'https://fixture.invalid',
                'certificate_authority_file': None, 'release_revision': 'a' * 40,
                'log_group_name': '/template/fixture/runtime', 'log_stream_name': 'health'}

    def test_liveness_and_readiness_are_separate_and_do_not_log_response_bodies(self):
        module = self.load()
        with patch.object(module, 'status', side_effect=[200, 503]) as status:
            observation = module.collect(self.config())
        self.assertEqual(observation['metrics'], {'Liveness': 1, 'Readiness': 0})
        self.assertEqual(observation['event']['up_status'], 200)
        self.assertEqual(observation['event']['ready_status'], 503)
        self.assertEqual(set(observation['event']), {'event', 'release_revision', 'up_status', 'ready_status'})
        self.assertEqual([call.args[0] for call in status.call_args_list], ['https://fixture.invalid/up', 'https://fixture.invalid/ready'])

    def test_private_metrics_and_log_payloads_have_no_credential_or_body_fields(self):
        module = self.load()
        with patch.object(module, 'status', side_effect=[0, 200]):
            observation = module.collect(self.config())
        with patch.object(module.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, '{}', '')) as run:
            module.publish(self.config(), observation, '/approved/aws')
        self.assertEqual(run.call_count, 2)
        calls = run.call_args_list
        metrics = json.loads(calls[0].kwargs['input'])
        logs = json.loads(calls[1].kwargs['input'])
        self.assertEqual(metrics['Namespace'], 'Template/fixture/Runtime')
        self.assertEqual([point['MetricName'] for point in metrics['MetricData']], ['Liveness', 'Readiness'])
        self.assertEqual(json.loads(logs['logEvents'][0]['message']), observation['event'])
        self.assertEqual(logs['logGroupName'], '/template/fixture/runtime')
        self.assertEqual(logs['logStreamName'], 'health')
        self.assertEqual(calls[0].kwargs['env']['BOTO_CONFIG'], '/dev/null')
        self.assertNotIn('AWS_ACCESS_KEY_ID', calls[0].kwargs['env'])
        self.assertNotIn('fixture.invalid', str(metrics) + str(logs))

    def test_failed_publication_is_propagated_without_printing_provider_errors(self):
        module = self.load()
        with patch.object(module, 'status', side_effect=[200, 200]):
            observation = module.collect(self.config())
        with patch.object(module.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1, '', 'secret-canary')):
            with self.assertRaises(ValueError) as error:
                module.publish(self.config(), observation, '/approved/aws')
        self.assertNotIn('secret-canary', str(error.exception))

    def test_http_success_with_rejected_log_events_is_not_reported_as_publication_success(self):
        module = self.load()
        with patch.object(module, 'status', side_effect=[200, 200]):
            observation = module.collect(self.config())
        for rejection in ['tooNewLogEventStartIndex', 'tooOldLogEventEndIndex', 'expiredLogEventEndIndex']:
            results = [subprocess.CompletedProcess([], 0, '', ''),
                       subprocess.CompletedProcess([], 0, json.dumps({'rejectedLogEventsInfo': {rejection: 0}}), '')]
            with patch.object(module.subprocess, 'run', side_effect=results), self.subTest(rejection=rejection), self.assertRaises(ValueError):
                module.publish(self.config(), observation, '/approved/aws')

    def test_public_configuration_cannot_select_http_credentials_or_unrelated_log_streams(self):
        module = self.load()
        for changed in [dict(self.config(), app_url='http://fixture.invalid'),
                        dict(self.config(), app_url='https://user:password@fixture.invalid'),
                        dict(self.config(), region='*'), dict(self.config(), release_revision='latest'),
                        dict(self.config(), log_group_name='/unrelated/logs')]:
            with self.assertRaises(ValueError):
                module.validate(changed)

    def test_publication_rejects_extra_fields_and_non_numeric_values_before_aws(self):
        module = self.load()
        with patch.object(module, 'status', side_effect=[200, 200]):
            observation = module.collect(self.config())
        for part, key, value in [('event', 'password', 'secret-canary'), ('metrics', 'Liveness', 'secret-canary'),
                                 ('event', 'release_revision', 'b' * 40), ('event', 'up_status', 999)]:
            changed = copy.deepcopy(observation)
            changed[part][key] = value
            with patch.object(module.subprocess, 'run') as run:
                with self.assertRaises(ValueError):
                    module.publish(self.config(), changed, '/approved/aws')
                run.assert_not_called()

    def test_cli_preview_has_no_network_or_telemetry_side_effects(self):
        module = self.load()
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / 'public.json'
            config.write_text(json.dumps(self.config()))
            with patch.object(module.sys, 'argv', ['observe_health', '--config', str(config)]), patch.object(module, 'collect') as collect, patch.object(module, 'publish') as publish, contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(module.main(), 0)
            collect.assert_not_called()
            publish.assert_not_called()

    def test_cli_reports_non_ready_after_publishing_and_redacts_subprocess_errors(self):
        module = self.load()
        observation = {'metrics': {'Liveness': 1, 'Readiness': 0}, 'event': {'event': 'health', 'release_revision': 'a' * 40, 'up_status': 200, 'ready_status': 503}}
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / 'public.json'
            config.write_text(json.dumps(self.config()))
            with patch.object(module.sys, 'argv', ['observe_health', '--config', str(config), '--execute']), patch.object(module, 'collect', return_value=observation), patch.object(module, 'publish') as publish, contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(module.main(), 1)
                publish.assert_called_once()
            error_output = io.StringIO()
            with patch.object(module.sys, 'argv', ['observe_health', '--config', str(config), '--execute']), patch.object(module, 'collect', return_value=observation), patch.object(module, 'publish', side_effect=subprocess.TimeoutExpired('secret-canary', 30, output='secret-canary')), contextlib.redirect_stderr(error_output):
                self.assertEqual(module.main(), 1)
            self.assertNotIn('secret-canary', error_output.getvalue())


class HealthHttpsBoundaryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory()
        cls.ca_file = Path(cls.directory.name) / 'fixture.pem'
        key_file = Path(cls.directory.name) / 'fixture.key'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '1',
                        '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost',
                        '-keyout', str(key_file), '-out', str(cls.ca_file)], check=True, capture_output=True)
        cls.requests = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                cls.requests.append(self.path)
                code = {'/up': 200, '/ready': 503, '/redirect': 302}.get(self.path, 404)
                self.send_response(code)
                self.send_header('X-Private-Diagnostic', 'secret-canary')
                if code == 302:
                    self.send_header('Location', '/private')
                self.end_headers()
                try:
                    self.wfile.write(b'secret-canary')
                except (BrokenPipeError, ConnectionResetError):
                    pass

            def log_message(self, format, *args):
                pass

        cls.server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cls.ca_file, key_file)
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        spec = importlib.util.spec_from_file_location('observe_health', SCRIPT)
        cls.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.module)

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.thread.join(timeout=5)
        cls.server.server_close()
        cls.directory.cleanup()

    def origin(self, hostname='localhost'):
        return f'https://{hostname}:{self.server.server_port}'

    def test_selected_ca_accepts_status_without_exporting_diagnostic_body(self):
        with patch.object(http.client.HTTPResponse, 'read', side_effect=AssertionError('Diagnostic response bodies must remain unread')) as read:
            self.assertEqual(self.module.status(self.origin() + '/up', str(self.ca_file)), 200)
            self.assertEqual(self.module.status(self.origin() + '/ready', str(self.ca_file)), 503)
            read.assert_not_called()

    def test_bad_hostname_and_untrusted_ca_fail_closed(self):
        self.assertEqual(self.module.status(self.origin('127.0.0.1') + '/up', str(self.ca_file)), 0)
        self.assertEqual(self.module.status(self.origin() + '/up', None), 0)

    def test_redirect_is_reported_without_following_its_target(self):
        before = len(self.requests)
        self.assertEqual(self.module.status(self.origin() + '/redirect', str(self.ca_file)), 302)
        self.assertEqual(self.requests[before:], ['/redirect'])


if __name__ == '__main__':
    unittest.main()
