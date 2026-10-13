"""Exercise application contracts through real disposable promotion/recovery; no AWS."""

import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.parse
import uuid

ROOT = Path(__file__).resolve().parents[2]
CYCLE_STAGE = 'disposable-runner-guard'
spec = importlib.util.spec_from_file_location('retained_rollback_fixture', Path(__file__).with_name('rollback-smoke.py'))
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)
from deploy_release import HostState, contract_hash, deploy
from rollback_release import rollback
from image_release import read_current


def command(arguments):
    result = subprocess.run(arguments, capture_output=True, timeout=600, check=False)
    if result.returncode:
        raise RuntimeError('Disposable cycle operation failed; private output withheld')
    return result.stdout


def require_disposable_runner(arguments):
    if arguments or os.environ.get('GITHUB_ACTIONS') != 'true' or os.environ.get('RUNNER_ENVIRONMENT') != 'github-hosted' or os.environ.get('RUNNER_OS') != 'Linux':
        raise ValueError('The cycle accepts only an argument-free disposable hosted Linux runner')
    if os.environ.get('DOCKER_HOST') or os.environ.get('DOCKER_CONTEXT'):
        raise ValueError('Shared or remote Docker targets are forbidden')
    if command(['docker', 'context', 'show']).strip() != b'default':
        raise ValueError('Only the disposable default Docker context is supported')


class Browser:
    def __init__(self, runtime, directory):
        self.runtime, self.directory = runtime, directory
        self.cookie = directory / 'cookies'
        self.original_cookie = directory / 'original-session'
        self.asset_version = 'cycle-stale-version'

    def request(self, path, fields=None, inertia=False):
        domain = self.runtime.contract['compose_environment']['TLS_DOMAIN']
        body, headers = self.directory / 'http-body', self.directory / 'http-headers'
        cookie = self.original_cookie if self.original_cookie.exists() else self.cookie
        args = ['curl', '--silent', '--show-error', '--proto', '=https', '--tlsv1.2', '--max-time', '15', '--cacert', self.runtime.contract['tls_ca_bundle'], '--resolve', domain + ':443:127.0.0.1', '--cookie', str(cookie), '--cookie-jar', str(self.cookie), '--header', 'Accept: application/json', '--output', str(body), '--dump-header', str(headers), '--write-out', '%{http_code}']
        if inertia:
            args += ['--header', 'X-Inertia: true', '--header', 'X-Inertia-Version: ' + self.asset_version]
        if fields is not None:
            args += ['--header', 'X-XSRF-TOKEN: ' + self.csrf()]
            for key, value in fields.items():
                args += ['--data-urlencode', key + '=' + value]
        status = int(self.runtime.command(args + ['https://' + domain + path]))
        return status, body.read_bytes(), headers.read_text()

    def csrf(self):
        for line in self.cookie.read_text().splitlines():
            fields = line.split('\t')
            if len(fields) == 7 and fields[5] == 'XSRF-TOKEN':
                return urllib.parse.unquote(fields[6])
        raise ValueError('Native encrypted CSRF cookie is absent')

    def initialize(self, password):
        status, body, headers = self.request('/login')
        if status != 200 or b'/build/assets/' not in body or 'secure' not in headers.lower() or 'httponly' not in headers.lower():
            raise ValueError('Secure native login page/cookies/assets failed')
        status, _, _ = self.request('/login', dict(email='owner@smoke.example.invalid', password='wrong-password'))
        if status != 422:
            raise ValueError('Native invalid credentials were accepted')
        status, body, _ = self.request('/login', dict(email='owner@smoke.example.invalid', password=password))
        if status != 200 or json.loads(body) != {'two_factor': False}:
            raise ValueError('Native Fortify login failed')
        shutil.copyfile(self.cookie, self.original_cookie)
        status, _, _ = self.request('/settings/teams', dict(name='Release Cycle Team'))
        if status != 302:
            raise ValueError('Native authenticated Teams write failed')

    def verify(self, owner_id, selected):
        status, body, headers = self.request('/settings/teams', inertia=True)
        if status == 409:
            versions = [line.split(':', 1)[1].strip() for line in headers.splitlines() if line.lower().startswith('x-inertia-version:')]
            if len(versions) != 1 or not versions[0] or len(versions[0]) > 512:
                raise ValueError('Native Inertia version conflict omitted its current version')
            self.asset_version = versions[0]
            status, body, _ = self.request('/settings/teams', inertia=True)
        if status != 200:
            raise ValueError('The original session did not recover the authenticated Inertia page')
        page = json.loads(body)
        if status != 200 or page['component'] != 'teams/Index' or page['props']['auth']['user']['id'] != owner_id or len(page['props']['teams']) != 2:
            raise ValueError('Original authenticated session/Teams data did not survive cutover')
        status, raw, _ = self.request('/build/release.json')
        release = json.loads(raw)
        if status != 200 or release['source_revision'] != selected['source_revision'] or contract_hash(release) != selected['assets_sha256']:
            raise ValueError('HTTP assets do not match the retained release')
        for path, digest in release['files'].items():
            status, content, _ = self.request('/build/' + path)
            if status != 200 or hashlib.sha256(content).hexdigest() != digest:
                raise ValueError('Recovered native HTTP asset bytes differ')


def php_fixture(runtime, selected, action, filename='release-cycle.php'):
    variable = 'CYCLE_ACTION' if filename == 'release-cycle.php' else 'SMOKE_ACTION'
    raw = runtime.command(['docker', 'compose', '--project-name', runtime.contract['project'], '--file', str(runtime.compose), 'exec', '-T', '-e', variable + '=' + action, 'php', 'php'], input_bytes=(ROOT / 'tests/Docker' / filename).read_bytes())
    return json.loads(raw)


def verify_application(runtime, browser, selected, owner_id, expanded):
    runtime.ready(selected)
    runtime.processes_ready(selected)
    runtime.smoke(selected)
    manifest = runtime.bundles[selected['source_revision']]['manifest']
    for service in ('php', 'nginx', 'worker', 'scheduler'):
        container = runtime.compose_command(selected, ['ps', '-q', service]).decode().strip()
        information = json.loads(runtime.command(['docker', 'inspect', container]))[0]
        role = 'nginx' if service == 'nginx' else 'php'
        if information['Image'] != manifest['images'][role]['config_digest']:
            raise ValueError('A running process does not use the exact retained OCI configuration')
        if service != 'nginx':
            uploads = [mount for mount in information['Mounts'] if mount['Destination'] == '/app/storage/app']
            if len(uploads) != 1 or uploads[0]['Source'] != runtime.contract['compose_environment']['UPLOAD_DIRECTORY']:
                raise ValueError('A process lost the shared persistent upload fixture')
    browser.verify(owner_id, selected)
    state = php_fixture(runtime, selected, 'verify')
    if any(state[key] != value for key, value in dict(users=2, teams=3, memberships=3, owner_id=owner_id, persisted=True, session_exists=True, expanded=expanded, failed_jobs=0).items()):
        raise ValueError('Native preserved account/team/session/schema contract differs')
    if expanded and state['expanded_value'] != 'retained-after-rollback':
        raise ValueError('The additive schema/data were implicitly reversed')
    runtime.stop_processes()
    queued = php_fixture(runtime, selected, 'enqueue')
    if queued['jobs'] != 1:
        raise ValueError('Native notification did not enqueue exactly one job')
    runtime.start_processes(selected)
    runtime.processes_ready(selected)
    for _ in range(30):
        processed = php_fixture(runtime, selected, 'verify')
        if processed['jobs'] == 0:
            break
        time.sleep(1)
    if processed['jobs'] != 0 or processed['failed_jobs'] != 0:
        raise ValueError('The reloaded native worker did not successfully consume the job')
    return processed


def main():
    global CYCLE_STAGE
    require_disposable_runner(sys.argv[1:])
    if os.geteuid() != 0:
        raise ValueError('The disposable fixture requires native host file ownership')
    bundles = {}
    for name in ('previous', 'current'):
        directory = ROOT / 'image-release' / name
        manifest = read_current(directory)
        bundles[manifest['source_revision']] = dict(directory=directory, manifest=manifest, images={role: 'laravel-' + role + ':oci-' + name for role in ('php', 'nginx')})
    if len(bundles) != 2 or any(len({bundle['manifest']['images'][role]['digest'] for bundle in bundles.values()}) != 2 for role in ('php', 'nginx')):
        raise ValueError('Both versions require distinct verified real OCI manifest digests')
    project = 'release-cycle-' + uuid.uuid4().hex
    with tempfile.TemporaryDirectory(prefix=project + '-', dir=os.environ.get('RUNNER_TEMP')) as temporary:
        directory = Path(temporary)
        directory.chmod(0o755)
        for name in ('uploads', 'acme', 'tls', 'auth', 'state'):
            (directory / name).mkdir(mode=0o700 if name in ('auth', 'state') else 0o755)
        for name in ('private', 'public'):
            (directory / 'uploads' / name).mkdir(mode=0o750)
            os.chown(directory / 'uploads' / name, 33, 33)
        os.chown(directory / 'uploads', 33, 33)
        domain = 'cycle.smoke.invalid'
        ca, key = directory / 'ca.pem', directory / 'key.pem'
        command(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-subj', '/CN=' + domain, '-addext', 'subjectAltName=DNS:' + domain, '-addext', 'extendedKeyUsage=serverAuth', '-keyout', str(key), '-out', str(ca)])
        command(['python3', str(ROOT / 'infra/aws/https/install-certificate.py'), '--domain', domain, '--mechanism', 'provided', '--fullchain', str(ca), '--private-key', str(key), '--directory', str(directory / 'tls'), '--nginx-gid', '101'])
        ca.chmod(0o644)
        password, database_password = secrets.token_hex(24), secrets.token_hex(24)
        database = 'smoke_cycle_' + uuid.uuid4().hex[:16]
        runtime_file = directory / 'runtime.env'
        runtime_file.write_text('APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:' + base64.b64encode(os.urandom(32)).decode() + '\nAPP_URL=https://' + domain + '\nDB_CONNECTION=pgsql\nDB_HOST=pgsql\nDB_PORT=5432\nDB_DATABASE=' + database + '\nDB_USERNAME=fixture\nDB_PASSWORD=' + database_password + '\nDB_SSLMODE=disable\nSESSION_DRIVER=database\nCACHE_STORE=database\nQUEUE_CONNECTION=database\nSESSION_SECURE_COOKIE=true\nLOG_CHANNEL=stderr\nMAIL_MAILER=array\nSMOKE_TEST_PASSWORD=' + password + '\n')
        runtime_file.chmod(0o600)
        input_hash = hashlib.sha256(runtime_file.read_bytes()).hexdigest()
        upload = directory / 'uploads/private/retained-cycle-data'
        upload.write_bytes(os.urandom(64))
        upload.chmod(0o600)
        os.chown(upload, 33, 33)
        upload_hash = hashlib.sha256(upload.read_bytes()).hexdigest()
        overlay = directory / 'fixture.yaml'
        overlay.write_text('services:\n  pgsql:\n    image: postgres:16.15-bookworm@sha256:0ea6700a3b4f0ae6ce746519073558aed4d88a79d8d07622a9a644946c7319c4\n    user: postgres\n    environment:\n      POSTGRES_DB: ' + database + '\n      POSTGRES_USER: fixture\n      POSTGRES_PASSWORD: ' + database_password + '\n    networks: [app]\n    volumes: ["fixture-db:/var/lib/postgresql/data"]\n    healthcheck:\n      test: ["CMD", "pg_isready", "-h", "127.0.0.1", "-U", "fixture"]\n      interval: 2s\n      timeout: 5s\n      retries: 30\nvolumes:\n  fixture-db:\n')
        overlay.chmod(0o600)
        contract = dict(php_repository='fixture.invalid/php', nginx_repository='fixture.invalid/nginx', project=project, tls_ca_bundle=str(ca), runtime_env_file=str(runtime_file), compose_environment=dict(RUNTIME_ENV_FILE=str(runtime_file), UPLOAD_DIRECTORY=str(directory / 'uploads'), DB_CA_BUNDLE=str(ca), TLS_DOMAIN=domain, TLS_CERTIFICATE_MECHANISM='provided', TLS_CERTIFICATE_DIRECTORY=str(directory / 'tls'), TLS_ACME_WEBROOT=str(directory / 'acme'), TLS_BIND_IPV4='127.0.0.1'))
        runtime = fixture.FixtureRuntime(contract, ROOT / 'compose.production.release.yaml', directory / 'auth', bundles, overlay)
        runtime.input_fingerprint = hashlib.sha256(runtime_file.read_bytes()).digest()
        releases = []
        for bundle in bundles.values():
            manifest = bundle['manifest']
            releases.append(dict(source_revision=manifest['source_revision'], version=manifest['version'], manifest_sha256=hashlib.sha256((bundle['directory'] / 'image-release.json').read_bytes()).hexdigest(), assets_sha256=contract_hash(manifest['assets']), **{role + '_image': contract[role + '_repository'] + '@' + manifest['images'][role]['digest'] for role in ('php', 'nginx')}))
        a, b = releases
        store = HostState(directory / 'state')
        browser = Browser(runtime, directory)
        request = lambda selected, previous: dict(schema_version=1, contract_sha256=contract_hash(contract), release=selected, previous=previous)
        recovery = lambda current, previous, pending, target: dict(schema_version=1, expected_current=current, expected_previous=previous, expected_pending=pending, target=target, target_contract_sha256=contract_hash(contract), schema_compatible=True)
        checkpoints = []
        try:
            CYCLE_STAGE = 'initial-N-promotion'
            runtime.compose_command(a, ['up', '-d', '--wait', '--wait-timeout', '90', 'pgsql'])
            deploy(request(a, None), contract, store, runtime)
            CYCLE_STAGE = 'N-authentication-and-team-initialization'
            initialized = php_fixture(runtime, a, 'initialize', 'production-fixture.php')
            browser.initialize(password)
            owner_id = initialized['owner_id']
            CYCLE_STAGE = 'N-application-assets-and-worker-verification'
            verify_application(runtime, browser, a, owner_id, False)
            checkpoints.append(dict(phase='N', release=a, migrations=runtime.migrations))
            CYCLE_STAGE = 'N-plus-one-promotion'
            deploy(request(b, a), contract, store, runtime)
            php_fixture(runtime, b, 'expand')
            CYCLE_STAGE = 'N-plus-one-application-assets-and-worker-verification'
            verify_application(runtime, browser, b, owner_id, True)
            checkpoints.append(dict(phase='N+1', release=b, migrations=runtime.migrations))
            CYCLE_STAGE = 'rollback-to-N'
            receipt = rollback(recovery(b, a, None, 'previous'), store, runtime)
            if receipt['release'] != a or runtime.migrations != 2 or store.read()['pending'] is not None:
                raise ValueError('Rollback reran migration or committed an incorrect release')
            CYCLE_STAGE = 'restored-N-application-assets-and-worker-verification'
            verify_application(runtime, browser, a, owner_id, True)
            checkpoints.append(dict(phase='N restored', release=a, migrations=runtime.migrations, completed_at=receipt['completed_at']))
            CYCLE_STAGE = 'forward-replay-and-readiness-failure'
            deploy(request(b, a), contract, store, runtime)
            runtime.break_database = True
            try:
                deploy(request(a, b), contract, store, runtime)
            except RuntimeError:
                pass
            else:
                raise ValueError('Native failed readiness was accepted')
            failed = store.read()
            if failed['current']['release'] != b or failed['pending']['release'] != a or failed['pending']['phase'] != 'checking-web-readiness':
                raise ValueError('Readiness failure did not retain its exact diagnostic phase')
            active = runtime.compose_command(b, ['ps', '--status', 'running', '--services']).decode().splitlines()
            if active:
                raise ValueError('Unready candidate or background processes kept running')
            runtime.break_database = False
            runtime.compose_command(b, ['up', '-d', '--wait', '--wait-timeout', '90', 'pgsql'])
            CYCLE_STAGE = 'failed-promotion-recovery'
            recovered = rollback(recovery(b, a, a, 'current'), store, runtime)
            if recovered['release'] != b or runtime.migrations != 4:
                raise ValueError('Failed-promotion recovery reran migration or chose pending')
            CYCLE_STAGE = 'recovered-current-application-verification'
            final_state = verify_application(runtime, browser, b, owner_id, True)
            if final_state['migration_receipts'] != 2 or hashlib.sha256(runtime_file.read_bytes()).hexdigest() != input_hash or hashlib.sha256(upload.read_bytes()).hexdigest() != upload_hash:
                raise ValueError('Recovery changed completed migrations, uploads or stable private inputs')
            checkpoints.append(dict(phase='failed readiness recovered', release=b, migrations=runtime.migrations, failed_phase=failed['pending']['phase'], completed_at=recovered['completed_at']))
            evidence = dict(schema_version=1, status='passed', checkpoints=checkpoints, checks=['true-distinct-OCI-digests', 'running-web-worker-scheduler-exact-image-configs', 'additive-schema-preserved', 'native-HTTPS-login-original-session', 'Teams-data-membership-upload-persistence', 'all-HTTP-asset-hashes', 'actual-worker-job-consumption', 'singleton-scheduler', 'exact-readiness-failure-phase', 'opt-in-recovery-no-DDL', 'stable-APP_KEY-private-inputs', 'owned-cleanup'], limitations=['disposable-local-simulated-versions', 'no-AWS-ECR-SSM', 'no-production-cutover', 'no-zero-downtime-proof', 'no-general-schema-reversibility', 'mail-array-no-external-delivery'])
            evidence['context_overlays'] = [dict(base_git_revision=b['source_revision'], release_version=b['version'], fixture_file='tests/Docker/migration-fixtures/2030_01_01_000001_expand_release_probe.php', fixture_sha256=hashlib.sha256((ROOT / 'tests/Docker/migration-fixtures/2030_01_01_000001_expand_release_probe.php').read_bytes()).hexdigest(), destination='/app/database/migrations/2030_01_01_000001_expand_release_probe.php', derived_build_target='cycle-runtime')]
            evidence['limitations'].append('N+1-base-Git-plus-test-overlay-not-production-source-attestation')
        finally:
            runtime.compose_command(a, ['down', '--volumes', '--timeout', '30'])
            for arguments in [['docker', 'ps', '-aq'], ['docker', 'volume', 'ls', '-q'], ['docker', 'network', 'ls', '-q']]:
                if command(arguments + ['--filter', 'label=com.docker.compose.project=' + project]).strip():
                    raise ValueError('Owned disposable cycle resources remain')
        (ROOT / 'release-cycle-evidence.json').write_text(json.dumps(evidence, sort_keys=True) + '\n')
        print('Native N -> N+1 -> N application cycle and readiness-failure recovery passed; no AWS operation.')


def cli():
    try:
        main()
        return 0
    except Exception:
        print('Disposable release cycle failed; private command diagnostics withheld.', file=sys.stderr)
        print('Failed stage: ' + CYCLE_STAGE, file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(cli())
