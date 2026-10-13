"""Recover only a completed host receipt; never reverse schema or external effects."""

import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

from deploy_release import DockerRuntime, HostState, contract_hash, private_descriptor, validate_release


def validate_rollback_request(request):
    keys = {'schema_version', 'expected_current', 'expected_previous', 'expected_pending', 'target', 'target_contract_sha256', 'schema_compatible'}
    if not isinstance(request, dict) or set(request) != keys or request['schema_version'] != 1 or request['schema_compatible'] is not True:
        raise ValueError('Require explicit forward-compatible schema approval and exact recovery identities')
    if request['target'] not in {'previous', 'current'}:
        raise ValueError('Only a completed receipt can be restored')
    if not isinstance(request['expected_current'], dict) or any(value is not None and (not isinstance(value, dict) or set(value) != {'source_revision', 'version', 'php_image', 'nginx_image', 'manifest_sha256', 'assets_sha256'}) for value in (request['expected_current'], request['expected_previous'], request['expected_pending'])):
        raise ValueError('Require exact public completed/pending release shapes')
    if request['target'] == 'previous' and (request['expected_previous'] is None or request['expected_pending'] is not None):
        raise ValueError('A completed rollback requires its retained predecessor and no pending promotion')
    if request['target'] == 'current' and request['expected_pending'] is None:
        raise ValueError('Current recovery requires an explicitly bound failed promotion')
    if not isinstance(request['target_contract_sha256'], str) or not re.fullmatch('[a-f0-9]{64}', request['target_contract_sha256']):
        raise ValueError('Require the completed target contract hash')


def completed(receipt):
    if not isinstance(receipt, dict) or receipt.get('phase') != 'completed' or receipt.get('schema_policy') != 'expand-contract':
        raise ValueError('Recovery requires a completed forward-compatible release receipt')
    contract = receipt['contract']
    if receipt['contract_sha256'] != contract_hash(contract): raise ValueError('Stored release contract integrity differs')
    validate_release(receipt['release'], contract)
    for key in ('started_at', 'completed_at'):
        if datetime.fromisoformat(receipt[key]).tzinfo is None: raise ValueError('Require dated completed receipts')
    return receipt


def select_target(request, state):
    validate_rollback_request(request)
    current = completed(state['current'])
    if current.get('operation') == 'rollback' and current.get('request_sha256') == contract_hash(request) and state['pending'] is None:
        return current
    previous = completed(state['previous']) if state['previous'] else None
    pending = state['pending']
    if current['release'] != request['expected_current'] or (previous['release'] if previous else None) != request['expected_previous'] or (pending['release'] if pending else None) != request['expected_pending']:
        raise ValueError('Current, previous or pending host identity changed; review the private state before retry')
    if pending and request['target'] != 'current': raise ValueError('Failed promotion recovery must restore the last successful current release')
    if not pending and request['target'] != 'previous': raise ValueError('A completed promotion rolls back only to its retained predecessor')
    if pending and pending.get('operation') == 'rollback': raise ValueError('Interrupted rollback requires explicit manual recovery; no blind retry')
    target = current if request['target'] == 'current' else previous
    if not target or target['contract_sha256'] != request['target_contract_sha256']: raise ValueError('The retained target contract differs')
    old, new = target['contract'], current['contract']
    persistent = ('instance_id', 'upload_volume_id', 'upload_directory', 'runtime_env_file', 'runtime_directory', 'project', 'aws_region', 'php_repository', 'nginx_repository')
    if any(old.get(key) != new.get(key) for key in persistent): raise ValueError('Rollback cannot move persistent state, secrets, host or registry')
    for key in ('RUNTIME_ENV_FILE', 'UPLOAD_DIRECTORY', 'DB_CA_BUNDLE'):
        if old.get('compose_environment', {}).get(key) != new.get('compose_environment', {}).get(key): raise ValueError('Persistent Compose inputs differ')
    return target


def rollback(request, store, runtime, clock=lambda: datetime.now(timezone.utc)):
    validate_rollback_request(request)
    request_sha = contract_hash(request)
    with store.lock():
        state = store.read()
        current = state['current']
        if current and current.get('operation') == 'rollback' and current.get('request_sha256') == request_sha and state['pending'] is None:
            completed(current)
            runtime.ready(current['release'])
            runtime.processes_ready(current['release'])
            runtime.smoke(current['release'])
            return current
        target = select_target(request, state)
        selected = target['release']
        if hasattr(runtime, 'contract') and runtime.contract != target['contract']: raise ValueError('Recovery runtime must use the retained target contract')
        runtime.pull(selected)
        pending = dict(release=selected, contract=target['contract'], contract_sha256=target['contract_sha256'], schema_policy='expand-contract',
                       operation='rollback', request_sha256=request_sha, rollback_from=current['release'], recovered_pending=state['pending']['release'] if state['pending'] else None, started_at=clock().isoformat())
        state['pending'] = pending
        store.write(state)
        try:
            for phase, operation in [('stopping-processes', runtime.stop_processes), ('starting-web', lambda: runtime.start_web(selected)),
                                     ('checking-web-readiness', lambda: runtime.ready(selected)), ('starting-processes', lambda: runtime.start_processes(selected)),
                                     ('checking-process-readiness', lambda: runtime.ready(selected)), ('checking-processes', lambda: runtime.processes_ready(selected)),
                                     ('checking-smoke', lambda: runtime.smoke(selected))]:
                pending['phase'] = phase
                store.write(state)
                operation()
        except Exception:
            runtime.stop_candidate(selected)
            raise
        receipt = pending | dict(phase='completed', completed_at=clock().isoformat())
        state['current'], state['pending'] = receipt, None
        if request['target'] == 'previous': state['previous'] = current
        store.write(state)
        return receipt


class RollbackRuntime(DockerRuntime):
    input_fingerprint = None
    def smoke(self, selected):
        if self.input_fingerprint is not None and private_input_hash(self.contract['runtime_env_file']) != self.input_fingerprint:
            raise ValueError('Private runtime inputs changed; do not commit recovery')
        domain = self.contract['compose_environment']['TLS_DOMAIN']
        address = self.contract['compose_environment']['TLS_BIND_IPV4']
        if address == '0.0.0.0': address = '127.0.0.1'
        arguments = ['curl', '--fail', '--silent', '--show-error', '--proto', '=https', '--tlsv1.2', '--connect-timeout', '5', '--max-time', '10', '--resolve', domain + ':443:' + address]
        if self.contract.get('tls_ca_bundle'): arguments += ['--cacert', self.contract['tls_ca_bundle']]
        self.command(arguments + ['https://' + domain + '/up'], timeout=15)
        raw = self.command(arguments + ['https://' + domain + '/build/release.json'], timeout=15)
        if len(raw) > 8_000_000 or contract_hash(json.loads(raw)) != selected['assets_sha256']:
            raise ValueError('Recovered HTTPS asset manifest differs from the retained release')


def private_input_hash(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        private_descriptor(descriptor)
        data = os.read(descriptor, 65537)
        if len(data) > 65536: raise ValueError('Private runtime input exceeds the selected bound')
        return hashlib.sha256(data).digest()
    finally: os.close(descriptor)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('contract', 'request', 'compose'): parser.add_argument('--' + name, type=Path, required=True)
    parser.add_argument('--execute', action='store_true')
    args = parser.parse_args()
    try:
        contract, request = json.loads(args.contract.read_bytes()), json.loads(args.request.read_bytes())
        validate_rollback_request(request)
        if not args.execute:
            print('Rollback identity preview validated; no host, schema, Docker or AWS operation performed')
            return 0
        if os.geteuid() != 0: raise ValueError('Only the owner-approved SSM host operation may execute recovery')
        if hashlib.sha256(Path(__file__).read_bytes()).hexdigest() != contract.get('rollback_runner_sha256'):
            raise ValueError('Recovery runner differs from the approved document')
        if hashlib.sha256(Path(__file__).with_name('deploy_release.py').read_bytes()).hexdigest() != contract['runner_sha256']:
            raise ValueError('Shared deployment runner differs from the approved document')
        store = HostState(Path(contract['runtime_directory']) / 'releases')
        # Preview selection is repeated under the exclusive lock by rollback.
        target = select_target(request, store.read())
        retained = target['contract']
        if hashlib.sha256(args.compose.read_bytes()).hexdigest() != retained['compose_sha256']: raise ValueError('Retained Compose configuration is unavailable; manual recovery required')
        before = private_input_hash(retained['runtime_env_file'])
        uploads = Path(retained['upload_directory'])
        if not uploads.is_mount(): raise ValueError('Uploads must remain the selected persistent EBS mount')
        source = subprocess.check_output(['findmnt', '-n', '-o', 'SOURCE', str(uploads)], text=True).strip()
        serial = subprocess.check_output(['lsblk', '-ndo', 'SERIAL', source], text=True).strip()
        if serial != retained['upload_volume_id'].replace('-', ''): raise ValueError('Persistent upload EBS identity differs')
        with tempfile.TemporaryDirectory(prefix='rollback-auth-', dir=contract['runtime_directory']) as temporary:
            runtime = RollbackRuntime(retained, args.compose, temporary)
            runtime.input_fingerprint = before
            runtime.login()
            receipt = rollback(request, store, runtime)
        print(json.dumps({key: receipt['release'][key] for key in ('source_revision', 'version', 'manifest_sha256')} | {'completed_at': receipt['completed_at']}, sort_keys=True))
        return 0
    except (ValueError, KeyError, TypeError, OSError, RuntimeError, subprocess.SubprocessError):
        print('Controlled rollback failed; private state and command diagnostics withheld. Inspect the retained pending phase before any manual retry.', file=sys.stderr)
        return 1


if __name__ == '__main__': sys.exit(main())
