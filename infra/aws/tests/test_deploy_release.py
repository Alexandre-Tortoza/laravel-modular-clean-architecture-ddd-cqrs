import copy
from datetime import datetime, timezone
import fcntl
import json
from pathlib import Path
import tempfile
import unittest

from sys import path
path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from deploy_release import HostState, deploy, validate_release, contract_hash


def release(revision='a', version='v1.0.0'):
    return dict(source_revision=revision * 40, version=version, php_image='123456789012.dkr.ecr.us-east-1.amazonaws.com/fixture/php@sha256:' + revision * 64,
                nginx_image='123456789012.dkr.ecr.us-east-1.amazonaws.com/fixture/nginx@sha256:' + revision * 64,
                manifest_sha256=revision * 64, assets_sha256=revision * 64)


class RuntimeDouble:
    def __init__(self, fail=None):
        self.events, self.fail = [], fail
    def event(self, name):
        self.events.append(name)
        if self.fail == name:
            raise RuntimeError('PRIVATE_SQL_PASSWORD_CANARY')
    def pull(self, selected): self.event('pull-' + selected['version'])
    def migrate(self, selected): self.event('migrate')
    def stop_processes(self): self.event('stop-processes')
    def start_web(self, selected): self.event('start-web')
    def ready(self, selected): self.event('ready')
    def start_processes(self, selected): self.event('start-processes')
    def processes_ready(self, selected): self.event('processes-ready')
    def stop_candidate(self, selected): self.event('stop-candidate')


class ControlledDeploymentTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.store = HostState(Path(self.temporary.name))
        self.contract = dict(php_repository='123456789012.dkr.ecr.us-east-1.amazonaws.com/fixture/php', nginx_repository='123456789012.dkr.ecr.us-east-1.amazonaws.com/fixture/nginx')
        self.request = dict(schema_version=1, contract_sha256=contract_hash(self.contract), release=release(), previous=None)
        self.clock = lambda: datetime(2026, 1, 1, tzinfo=timezone.utc)
    def run_deploy(self, runtime=None):
        return deploy(self.request, self.contract, self.store, runtime or RuntimeDouble(), self.clock)
    def test_only_successful_health_and_processes_commit_current_receipt(self):
        runtime = RuntimeDouble()
        receipt = self.run_deploy(runtime)
        self.assertEqual(runtime.events, ['pull-v1.0.0', 'migrate', 'stop-processes', 'start-web', 'ready', 'start-processes', 'ready', 'processes-ready'])
        self.assertEqual(self.store.read()['current']['release'], release())
        self.assertEqual(receipt['completed_at'], '2026-01-01T00:00:00+00:00')
        self.assertIsNone(self.store.read()['pending'])
        self.assertNotIn('PASSWORD', json.dumps(self.store.read()))
    def test_retains_real_completed_current_and_binds_predecessor_before_promotion(self):
        self.run_deploy()
        self.request['previous'] = release()
        self.request['release'] = release('b', 'v1.0.1')
        runtime = RuntimeDouble()
        self.run_deploy(runtime)
        state = self.store.read()
        self.assertEqual(state['previous']['release'], release())
        self.assertEqual(state['current']['release'], release('b', 'v1.0.1'))
        self.assertEqual(runtime.events[:2], ['pull-v1.0.0', 'pull-v1.0.1'])
    def test_stale_previous_or_contract_drift_fails_before_any_runtime_command(self):
        self.run_deploy()
        for request in [self.request | {'release': release('b', 'v1.0.1')}, self.request | {'contract_sha256': 'c' * 64}]:
            runtime = RuntimeDouble()
            with self.assertRaises(ValueError): deploy(request, self.contract, self.store, runtime, self.clock)
            self.assertEqual(runtime.events, [])
    def test_migration_failure_does_not_stop_running_release_or_change_receipt(self):
        self.run_deploy()
        original = self.store.read()
        self.request.update(release=release('b', 'v1.0.1'), previous=release())
        runtime = RuntimeDouble('migrate')
        with self.assertRaises(RuntimeError): self.run_deploy(runtime)
        self.assertEqual(self.store.read(), original)
        self.assertNotIn('stop-processes', runtime.events)
    def test_readiness_failure_stops_candidate_and_preserves_valid_previous_receipt(self):
        self.run_deploy()
        original = self.store.read()['current']
        self.request.update(release=release('b', 'v1.0.1'), previous=release())
        runtime = RuntimeDouble('ready')
        with self.assertRaises(RuntimeError): self.run_deploy(runtime)
        self.assertEqual(self.store.read()['current'], original)
        self.assertEqual(self.store.read()['pending']['release'], release('b', 'v1.0.1'))
        self.assertEqual(runtime.events[-1], 'stop-candidate')
        self.assertNotIn('start-processes', runtime.events)
    def test_completed_repeat_checks_readiness_without_migrating_or_replacing_processes(self):
        self.run_deploy()
        runtime = RuntimeDouble()
        self.run_deploy(runtime)
        self.assertEqual(runtime.events, ['ready', 'processes-ready'])
    def test_completed_retry_rejects_missing_process_without_mutating_a_valid_receipt(self):
        self.run_deploy()
        original = self.store.read()
        runtime = RuntimeDouble('processes-ready')
        with self.assertRaises(RuntimeError): self.run_deploy(runtime)
        self.assertEqual(runtime.events, ['ready', 'processes-ready'])
        self.assertEqual(self.store.read(), original)
    def test_rejects_mutable_foreign_malformed_or_unexpected_release_fields(self):
        for patch in [dict(php_image='fixture:latest'), dict(nginx_image=release()['nginx_image'].replace('123456789012', '999999999999')), dict(version='latest'), dict(source_revision='main'), dict(manifest_sha256='wrong'), dict(assets_sha256='wrong'), dict(secret='PRIVATE_CANARY')]:
            with self.subTest(patch=patch), self.assertRaises(ValueError): validate_release(release() | patch, self.contract)
    def test_root_state_rejects_symlink_permissions_and_concurrent_writer(self):
        self.run_deploy()
        state_path = self.store.root / 'state.json'
        self.assertEqual(state_path.stat().st_mode & 0o777, 0o600)
        state_path.chmod(0o644)
        with self.assertRaises(ValueError): self.store.read()
        state_path.chmod(0o600)
        with self.store.lock():
            with self.assertRaises(BlockingIOError):
                with self.store.lock(): pass
        state_path.unlink()
        state_path.symlink_to('/etc/passwd')
        with self.assertRaises(ValueError): self.store.read()


class PulledImageBoundaryTest(unittest.TestCase):
    def test_actual_pull_boundary_rejects_wrong_digest_source_and_asset_provenance(self):
        from unittest.mock import patch
        from deploy_release import DockerRuntime
        with tempfile.TemporaryDirectory() as temporary:
            selected = release()
            assets = {'schema_version': 1, 'source_revision': selected['source_revision']}
            selected['assets_sha256'] = contract_hash(assets)
            runtime = DockerRuntime({'compose_environment': {}}, 'unused-compose.yaml', temporary)
            calls = []
            def output(arguments):
                calls.append(arguments)
                if arguments[1:3] == ['image', 'inspect']:
                    return json.dumps([{'RepoDigests': [arguments[-1]], 'Config': {'Labels': {'org.opencontainers.image.revision': selected['source_revision'], 'org.opencontainers.image.version': selected['version']}}}]).encode()
                if arguments[1] == 'run': return json.dumps(assets).encode()
                return b''
            with patch.object(runtime, 'command', side_effect=output): runtime.pull(selected)
            self.assertEqual(len([command for command in calls if command[1] == 'pull']), 2)
            for failure in ('digest', 'source', 'assets'):
                def invalid(arguments):
                    value = output(arguments)
                    if arguments[1:3] == ['image', 'inspect']:
                        metadata = json.loads(value)
                        if failure == 'digest': metadata[0]['RepoDigests'] = ['different']
                        if failure == 'source': metadata[0]['Config']['Labels']['org.opencontainers.image.revision'] = 'b'*40
                        return json.dumps(metadata).encode()
                    if failure == 'assets' and arguments[1] == 'run': return b'{}'
                    return value
                with self.subTest(failure=failure), patch.object(runtime, 'command', side_effect=invalid), self.assertRaises(ValueError): runtime.pull(selected)

    def test_native_readiness_connects_to_the_explicit_host_bind_with_verified_tls(self):
        from unittest.mock import patch
        from deploy_release import DockerRuntime
        for bind, expected in [('0.0.0.0', '127.0.0.1'), ('10.123.1.5', '10.123.1.5')]:
            with self.subTest(bind=bind), tempfile.TemporaryDirectory() as temporary:
                contract = {'project': 'fixture', 'tls_ca_bundle': '/fixture/ca.pem', 'compose_environment': {'TLS_DOMAIN': 'fixture.invalid', 'TLS_BIND_IPV4': bind}}
                runtime = DockerRuntime(contract, 'fixture-compose.yaml', temporary)
                calls = []
                def output(arguments, **options):
                    calls.append(arguments)
                    return b'[{"State":"running"},{"State":"running"}]' if arguments[:2] == ['docker','compose'] else b'{}'
                with patch.object(runtime, 'command', side_effect=output): runtime.ready(release())
                curl = calls[0]
                self.assertEqual(curl[curl.index('--resolve')+1], 'fixture.invalid:443:' + expected)
                self.assertEqual(curl[curl.index('--cacert')+1], '/fixture/ca.pem')
                self.assertNotIn('--insecure', curl)


if __name__ == '__main__': unittest.main()
