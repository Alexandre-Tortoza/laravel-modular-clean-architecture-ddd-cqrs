"""Controlled digest promotion on the selected EC2 host; execution is opt-in."""

import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import sys
import tempfile
import time


RELEASE_KEYS = {'source_revision', 'version', 'php_image', 'nginx_image', 'manifest_sha256', 'assets_sha256'}


def contract_hash(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def validate_release(value, contract):
    if not isinstance(value, dict) or set(value) != RELEASE_KEYS:
        raise ValueError('Invalid release identity schema')
    if not re.fullmatch('[a-f0-9]{40}', value['source_revision']) or not re.fullmatch(r'v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', value['version']):
        raise ValueError('Select an immutable full source SHA and stable SemVer')
    for key in ('manifest_sha256', 'assets_sha256'):
        if not re.fullmatch('[a-f0-9]{64}', value[key]):
            raise ValueError('Invalid release fingerprint')
    for role in ('php', 'nginx'):
        if not re.fullmatch(re.escape(contract[role + '_repository']) + '@sha256:[a-f0-9]{64}', value[role + '_image']):
            raise ValueError('Use a digest in the exact approved registry repository')


def validate_request(request, contract):
    if not isinstance(request, dict) or set(request) != {'schema_version', 'contract_sha256', 'release', 'previous'} or request['schema_version'] != 1:
        raise ValueError('Invalid deployment request schema')
    if request['contract_sha256'] != contract_hash(contract):
        raise ValueError('Reviewed Terraform/host deployment contract differs')
    validate_release(request['release'], contract)
    if request['previous'] is not None:
        validate_release(request['previous'], contract)
        if request['previous']['source_revision'] == request['release']['source_revision'] or request['previous']['version'] == request['release']['version']:
            raise ValueError('The retained predecessor must be a distinct release')


def private_descriptor(descriptor, mode=0o600):
    information = os.fstat(descriptor)
    if not stat.S_ISREG(information.st_mode) or stat.S_IMODE(information.st_mode) != mode or information.st_uid != os.geteuid() or information.st_nlink != 1:
        raise ValueError('Operational file must be private, regular and owned by this host identity')


class HostState:
    def __init__(self, root):
        self.root = Path(root)
        self.root.mkdir(mode=0o700, parents=True, exist_ok=True)
        information = self.root.lstat()
        if not stat.S_ISDIR(information.st_mode) or information.st_uid != os.geteuid() or stat.S_IMODE(information.st_mode) != 0o700:
            raise ValueError('Release state directory must be private and owned')

    @contextmanager
    def lock(self):
        descriptor = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            yield
        finally:
            os.close(descriptor)

    def read(self):
        try:
            descriptor = os.open(self.root / 'state.json', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        except FileNotFoundError:
            return dict(schema_version=1, current=None, previous=None, pending=None)
        except OSError:
            raise ValueError('Private state file is unsafe') from None
        with os.fdopen(descriptor) as stream:
            private_descriptor(stream.fileno())
            content = stream.read(32769)
        if len(content.encode()) > 32768:
            raise ValueError('Release state is oversized')
        state = json.loads(content)
        if set(state) != {'schema_version', 'current', 'previous', 'pending'} or state['schema_version'] != 1:
            raise ValueError('Invalid private release state')
        return state

    def write(self, state):
        temporary = self.root / ('.state-' + secrets.token_hex(12))
        descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(descriptor, 'w') as stream:
                os.fchmod(stream.fileno(), 0o600)
                json.dump(state, stream, sort_keys=True)
                stream.write('\n')
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary, self.root / 'state.json')
            directory = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try: os.fsync(directory)
            finally: os.close(directory)
        finally:
            temporary.unlink(missing_ok=True)


def deploy(request, contract, store, runtime, clock=lambda: datetime.now(timezone.utc)):
    validate_request(request, contract)
    selected = request['release']
    with store.lock():
        state = store.read()
        current = state['current']
        if current is not None and current['contract_sha256'] != request['contract_sha256']:
            raise ValueError('The running release belongs to a different host contract')
        if state['pending'] is not None:
            raise ValueError('An interrupted promotion requires explicit recovery before a new deployment')
        if current is not None and current['release'] == selected:
            runtime.ready(selected)
            runtime.processes_ready(selected)
            return current
        if (current['release'] if current else None) != request['previous']:
            raise ValueError('The completed host release differs from the approved predecessor')
        if current is not None:
            runtime.pull(current['release'])
        runtime.pull(selected)
        runtime.migrate(selected)
        pending = dict(release=selected, contract_sha256=request['contract_sha256'], contract=contract, schema_policy='expand-contract', started_at=clock().isoformat())
        state['pending'] = pending
        store.write(state)
        try:
            for phase, action in [('stopping-processes', runtime.stop_processes), ('starting-web', lambda: runtime.start_web(selected)), ('checking-web-readiness', lambda: runtime.ready(selected)), ('starting-processes', lambda: runtime.start_processes(selected)), ('checking-process-readiness', lambda: runtime.ready(selected)), ('checking-process-status', lambda: runtime.processes_ready(selected))]:
                pending['phase'] = phase
                store.write(state)
                action()
        except Exception:
            runtime.stop_candidate(selected)
            raise
        receipt = pending | {'phase': 'completed', 'completed_at': clock().isoformat()}
        state.update(current=receipt, previous=current, pending=None)
        store.write(state)
        return receipt


class DockerRuntime:
    def __init__(self, contract, compose, private_directory):
        self.contract, self.compose = contract, Path(compose)
        self.auth_directory = Path(private_directory)
        self.auth_directory.mkdir(mode=0o700, exist_ok=True)
        self.environment = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': str(self.auth_directory), 'DOCKER_CONFIG': str(self.auth_directory)}
        self.environment.update({key: str(value) for key, value in contract['compose_environment'].items()})

    def command(self, arguments, input_bytes=None, timeout=600):
        result = subprocess.run(arguments, env=self.environment, input=input_bytes, capture_output=True, timeout=timeout, check=False)
        if result.returncode:
            raise RuntimeError('Controlled release operation failed; private command diagnostics were withheld')
        return result.stdout

    def login(self):
        environment = self.environment | {'AWS_CONFIG_FILE': '/dev/null', 'AWS_SHARED_CREDENTIALS_FILE': '/dev/null', 'BOTO_CONFIG': '/dev/null', 'AWS_EC2_METADATA_DISABLED': 'false', 'AWS_PAGER': '', 'AWS_CLI_AUTO_PROMPT': 'off'}
        result = subprocess.run(['/usr/local/bin/aws', 'ecr', 'get-login-password', '--region', self.contract['aws_region']], env=environment, capture_output=True, timeout=30, check=False)
        if result.returncode: raise RuntimeError('Host registry authentication failed')
        host = self.contract['php_repository'].split('/')[0]
        self.command(['docker', 'login', '--username', 'AWS', '--password-stdin', host], result.stdout, timeout=30)

    def pull(self, selected):
        for role in ('php', 'nginx'):
            image = selected[role + '_image']
            self.command(['docker', 'pull', image])
            metadata = json.loads(self.command(['docker', 'image', 'inspect', image]))[0]
            labels = metadata['Config'].get('Labels', {})
            if image not in metadata.get('RepoDigests', []) or labels.get('org.opencontainers.image.revision') != selected['source_revision'] or labels.get('org.opencontainers.image.version') != selected['version']:
                raise ValueError('Pulled image digest or source/version differs from the approved release')
            raw = self.command(['docker', 'run', '--rm', '--network', 'none', '--entrypoint', '/bin/cat', image, '/app/public/build/release.json'])
            if len(raw) > 8 * 1024**2 or contract_hash(json.loads(raw)) != selected['assets_sha256']:
                raise ValueError('PHP/Nginx asset provenance differs from the retained source manifest')

    def compose_command(self, selected, arguments):
        self.environment.update(PHP_IMAGE=selected['php_image'], NGINX_IMAGE=selected['nginx_image'], SOURCE_REVISION=selected['source_revision'])
        return self.command(['docker', 'compose', '--project-name', self.contract['project'], '--file', str(self.compose), *arguments])

    def migrate(self, selected):
        self.compose_command(selected, ['run', '--rm', '--no-deps', '-T', 'migration'])

    def stop_processes(self):
        self.command(['docker', 'compose', '--project-name', self.contract['project'], '--file', str(self.compose), 'stop', '--timeout', '90', 'worker', 'scheduler'])

    def start_web(self, selected):
        self.compose_command(selected, ['up', '-d', '--no-build', '--pull', 'never', 'php', 'nginx'])

    def start_processes(self, selected):
        self.compose_command(selected, ['up', '-d', '--no-build', '--pull', 'never', '--scale', 'scheduler=1', 'worker', 'scheduler'])

    def processes_ready(self, selected):
        output = self.compose_command(selected, ['ps', '--all', '--format', 'json', 'worker', 'scheduler']).decode()
        information = [json.loads(line) for line in output.splitlines() if line.strip()]
        if len(information) == 1 and isinstance(information[0], list): information = information[0]
        if len(information) != 2 or {item.get('Service') for item in information} != {'worker', 'scheduler'} or any(item.get('State') != 'running' or item.get('Health') == 'unhealthy' for item in information):
            raise RuntimeError('Exactly one running worker and scheduler must accompany the release')

    def ready(self, selected):
        domain = self.contract['compose_environment']['TLS_DOMAIN']
        bind = str(ipaddress.IPv4Address(self.contract['compose_environment']['TLS_BIND_IPV4']))
        address = '127.0.0.1' if bind == '0.0.0.0' else bind
        arguments = ['curl', '--fail', '--silent', '--show-error', '--proto', '=https', '--tlsv1.2', '--connect-timeout', '5', '--max-time', '10', '--resolve', domain + ':443:' + address]
        if self.contract['tls_ca_bundle']:
            arguments += ['--cacert', self.contract['tls_ca_bundle']]
        for _ in range(30):
            try:
                self.command([*arguments, 'https://' + domain + '/ready'], timeout=15)
                output = self.compose_command(selected, ['ps', '--format', 'json', 'php', 'nginx']).decode()
                information = [json.loads(line) for line in output.splitlines() if line.strip()]
                if len(information) == 1 and isinstance(information[0], list): information = information[0]
                if len(information) == 2 and all(item.get('State') == 'running' for item in information):
                    return
            except (RuntimeError, ValueError, subprocess.TimeoutExpired):
                pass
            time.sleep(2)
        raise RuntimeError('Candidate readiness failed; release was not committed')

    def stop_candidate(self, selected):
        self.compose_command(selected, ['stop', '--timeout', '90', 'php', 'nginx', 'worker', 'scheduler'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--contract', type=Path, required=True)
    parser.add_argument('--request', type=Path, required=True)
    parser.add_argument('--compose', type=Path, required=True)
    parser.add_argument('--execute', action='store_true')
    arguments = parser.parse_args()
    try:
        contract, request = json.loads(arguments.contract.read_bytes()), json.loads(arguments.request.read_bytes())
        validate_request(request, contract)
        if not arguments.execute:
            print('Deployment request valid; no AWS, Docker or host mutation performed')
            return 0
        if os.geteuid() != 0: raise ValueError('Only the reviewed SSM host identity may execute deployment')
        descriptor = os.open(contract['runtime_env_file'], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try: private_descriptor(descriptor)
        finally: os.close(descriptor)
        if not os.path.ismount(contract['upload_directory']): raise ValueError('The retained EBS upload mount is absent')
        source = subprocess.run(['findmnt', '--noheadings', '--output', 'SOURCE', '--target', contract['upload_directory']], capture_output=True, text=True, check=True).stdout.strip()
        if not re.fullmatch('/dev/[A-Za-z0-9/._-]+', source): raise ValueError('The retained upload filesystem has an unexpected device')
        serial = subprocess.run(['lsblk', '--nodeps', '--noheadings', '--output', 'SERIAL', source], capture_output=True, text=True, check=True).stdout.strip()
        if serial != contract['upload_volume_id'].replace('-', ''): raise ValueError('The mounted upload device differs from the selected retained EBS volume')
        if hashlib.sha256(arguments.compose.read_bytes()).hexdigest() != contract['compose_sha256']: raise ValueError('Compose differs from reviewed Terraform content')
        if hashlib.sha256(Path(__file__).read_bytes()).hexdigest() != contract['runner_sha256']: raise ValueError('Host runner differs from reviewed Terraform content')
        with tempfile.TemporaryDirectory(prefix='registry-auth-', dir=contract['runtime_directory']) as private:
            runtime = DockerRuntime(contract, arguments.compose, private)
            runtime.login()
            receipt = deploy(request, contract, HostState(Path(contract['runtime_directory']) / 'releases'), runtime)
        print(json.dumps({'source_revision': receipt['release']['source_revision'], 'version': receipt['release']['version'], 'manifest_sha256': receipt['release']['manifest_sha256'], 'completed_at': receipt['completed_at']}, sort_keys=True))
        return 0
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError, RuntimeError):
        print('Controlled deployment failed; review the preserved receipt and approved recovery procedure', file=sys.stderr)
        return 1


if __name__ == '__main__': sys.exit(main())
