import copy
import sys
from pathlib import Path
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from deploy_release import HostState, contract_hash, deploy
from rollback_release import rollback
from test_deploy_release import RuntimeDouble, release
from datetime import datetime, timezone

def contract():
    return dict(php_repository="123456789012.dkr.ecr.us-east-1.amazonaws.com/fixture/php", nginx_repository="123456789012.dkr.ecr.us-east-1.amazonaws.com/fixture/nginx", runtime_env_file="/srv/laravel/runtime/application.env")

def request(configuration, selected, previous=None):
    return dict(schema_version=1, contract_sha256=contract_hash(configuration), release=selected, previous=previous)

def Clock(): return lambda: datetime(2026, 1, 1, tzinfo=timezone.utc)


class RollbackRuntime(RuntimeDouble):
    def processes_ready(self, selected): self.event('processes-ready')
    def smoke(self, selected): self.event('smoke')


class RollbackTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.store = HostState(Path(self.temporary.name) / 'state')
        self.addCleanup(self.temporary.cleanup)
        self.contract = contract()
        self.a = release('a', 'v1.0.0')
        self.b = release('b', 'v1.1.0')
        deploy(request(self.contract, self.a), self.contract, self.store, RuntimeDouble(), Clock())
        deploy(request(self.contract, self.b, self.a), self.contract, self.store, RuntimeDouble(), Clock())
        self.before = self.store.read()
        self.request = dict(schema_version=1, expected_current=self.b, expected_previous=self.a, expected_pending=None,
                            target='previous', target_contract_sha256=contract_hash(self.contract), schema_compatible=True)
    def test_restores_retained_completed_receipt_without_migrations_and_records_ordered_checks(self):
        runtime = RollbackRuntime()
        receipt = rollback(self.request, self.store, runtime, Clock())
        self.assertEqual(runtime.events, ['pull-v1.0.0', 'stop-processes', 'start-web', 'ready', 'start-processes', 'ready', 'processes-ready', 'smoke'])
        self.assertEqual(receipt['release'], self.a)
        self.assertEqual(receipt['rollback_from'], self.b)
        self.assertEqual(self.store.read()['previous'], self.before['current'])
        self.assertIsNone(self.store.read()['pending'])
        self.assertEqual(receipt['contract'], self.before['previous']['contract'])
    def test_stale_identity_missing_previous_incompatible_schema_or_extra_secret_refused_before_side_effects(self):
        for changes in [dict(expected_current=self.a), dict(expected_previous=None), dict(expected_pending=self.a),
                        dict(target_contract_sha256='f'*64), dict(schema_compatible=False), dict(secret='PRIVATE_CANARY')]:
            runtime = RollbackRuntime()
            with self.subTest(changes=changes), self.assertRaises(ValueError): rollback(self.request | changes, self.store, runtime, Clock())
            self.assertEqual(runtime.events, [])
            self.assertEqual(self.store.read(), self.before)
    def test_health_process_or_smoke_failure_keeps_receipts_and_recovery_phase_stops_candidate(self):
        for failure in ['ready', 'processes-ready', 'smoke']:
            self.store.write(copy.deepcopy(self.before))
            runtime = RollbackRuntime(failure)
            with self.subTest(failure=failure), self.assertRaises(RuntimeError): rollback(self.request, self.store, runtime, Clock())
            state = self.store.read()
            self.assertEqual(state['current'], self.before['current'])
            self.assertEqual(state['previous'], self.before['previous'])
            self.assertEqual(state['pending']['operation'], 'rollback')
            self.assertEqual(state['pending']['release'], self.a)
            self.assertEqual(runtime.events[-1], 'stop-candidate')
    def test_completed_retry_checks_health_processes_smoke_only_without_restart(self):
        runtime = RollbackRuntime()
        receipt = rollback(self.request, self.store, runtime, Clock())
        runtime.events.clear()
        self.assertEqual(rollback(self.request, self.store, runtime, Clock()), receipt)
        self.assertEqual(runtime.events, ['ready', 'processes-ready', 'smoke'])
    def test_failed_deployment_recovers_last_completed_current_and_preserves_older_previous(self):
        failed = release('c', 'v1.2.0')
        with self.assertRaises(RuntimeError): deploy(request(self.contract, failed, self.b), self.contract, self.store, RuntimeDouble('ready'), Clock())
        recovery = self.request | dict(expected_pending=failed, target='current')
        receipt = rollback(recovery, self.store, RollbackRuntime(), Clock())
        self.assertEqual(receipt['release'], self.b)
        self.assertEqual(self.store.read()['previous'], self.before['previous'])
        self.assertIsNone(self.store.read()['pending'])
    def test_pending_candidate_cannot_be_selected_or_silently_discarded(self):
        failed = release('c', 'v1.2.0')
        with self.assertRaises(RuntimeError): deploy(request(self.contract, failed, self.b), self.contract, self.store, RuntimeDouble('ready'), Clock())
        for changed in [self.request, self.request | dict(expected_pending=failed), self.request | dict(target='pending', expected_pending=failed)]:
            runtime = RollbackRuntime()
            with self.assertRaises(ValueError): rollback(changed, self.store, runtime, Clock())
            self.assertEqual(runtime.events, [])
    def test_tampered_receipt_or_persistent_paths_refused(self):
        for mutation in ['hash', 'schema', 'env']:
            state = copy.deepcopy(self.before)
            previous = state['previous']
            if mutation == 'hash': previous['contract_sha256'] = 'f'*64
            if mutation == 'schema': previous['schema_policy'] = 'reverse-ddl'
            if mutation == 'env':
                previous['contract']['runtime_env_file'] = '/different/secrets.env'
                previous['contract_sha256'] = contract_hash(previous['contract'])
            self.store.write(state)
            runtime = RollbackRuntime()
            with self.subTest(mutation=mutation), self.assertRaises(ValueError): rollback(self.request | dict(target_contract_sha256=previous['contract_sha256']), self.store, runtime, Clock())
            self.assertEqual(runtime.events, [])
    def test_lock_and_uncompleted_receipt_fail_closed(self):
        with self.store.lock(), self.assertRaises(BlockingIOError): rollback(self.request, self.store, RollbackRuntime(), Clock())
        state = copy.deepcopy(self.before)
        state['previous']['phase'] = 'starting-web'
        self.store.write(state)
        with self.assertRaises(ValueError): rollback(self.request, self.store, RollbackRuntime(), Clock())


if __name__ == '__main__': unittest.main()
