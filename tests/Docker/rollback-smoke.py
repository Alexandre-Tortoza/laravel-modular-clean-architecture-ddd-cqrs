"""Restore distinct retained OCI releases on disposable TLS/Pg/volumes, never AWS."""

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
sys.path.insert(0, str(ROOT / '.github/scripts'))
from deploy_release import HostState, contract_hash, deploy
from rollback_release import RollbackRuntime, rollback
from image_release import read_current as verify


def command(arguments):
    result = subprocess.run(arguments, capture_output=True, check=False, timeout=600)
    if result.returncode: raise RuntimeError('Disposable rollback fixture failed; command output withheld')
    return result.stdout


class FixtureRuntime(RollbackRuntime):
    def __init__(self, contract, compose, directory, bundles, overlay):
        super().__init__(contract, compose, directory)
        self.bundles, self.overlay, self.migrations = bundles, overlay, 0
        self.break_database = False
    def command(self, arguments, **options):
        if arguments[:2] == ['docker', 'compose']:
            arguments = arguments[:6] + ['--file', str(self.overlay)] + arguments[6:]
        return super().command(arguments, **options)
    def pull(self, selected):
        # Registry transport alone is replaced with verified locally retained OCI archives.
        bundle = self.bundles[selected['source_revision']]
        manifest = verify(bundle['directory'])
        if manifest['source_revision'] != selected['source_revision'] or manifest['version'] != selected['version']:
            raise ValueError('Retained archive source/version differs')
        if hashlib.sha256((bundle['directory'] / 'image-release.json').read_bytes()).hexdigest() != selected['manifest_sha256']:
            raise ValueError('Retained manifest bytes differ')
        for role in ('php', 'nginx'):
            if selected[role + '_image'].split('@')[1] != manifest['images'][role]['digest']: raise ValueError('Actual OCI manifest digest differs')
            info = json.loads(self.command(['docker', 'image', 'inspect', bundle['images'][role]]))[0]
            if info['Id'] != manifest['images'][role]['config_digest']: raise ValueError('Loaded image config differs from verified retained OCI bytes')
            if info['Config']['Labels']['org.opencontainers.image.revision'] != selected['source_revision']: raise ValueError('Loaded source differs')
            if info['Config']['Labels']['org.opencontainers.image.version'] != selected['version']: raise ValueError('Loaded version differs')
            raw = self.command(['docker', 'run', '--rm', '--network', 'none', '--entrypoint', '/bin/cat', bundle['images'][role], '/app/public/build/release.json'])
            if contract_hash(json.loads(raw)) != selected['assets_sha256']: raise ValueError('Loaded PHP/Nginx assets differ')
    def compose_command(self, selected, arguments):
        images = self.bundles[selected['source_revision']]['images']
        self.environment.update(PHP_IMAGE=images['php'], NGINX_IMAGE=images['nginx'], SOURCE_REVISION=selected['source_revision'])
        return self.command(['docker', 'compose', '--project-name', self.contract['project'], '--file', str(self.compose), *arguments])
    def migrate(self, selected):
        self.migrations += 1
        return super().migrate(selected)
    def start_web(self, selected):
        result = super().start_web(selected)
        if self.break_database: self.compose_command(selected, ['stop', 'pgsql'])
        return result
    def login(self): raise RuntimeError('AWS login forbidden in fixture')


def main():
    if len(sys.argv) != 1 or os.environ.get('GITHUB_ACTIONS') != 'true' or os.environ.get('RUNNER_ENVIRONMENT') != 'github-hosted' or os.environ.get('RUNNER_OS') != 'Linux':
        raise ValueError('Run only without arguments on a disposable hosted Linux runner')
    if os.environ.get('DOCKER_HOST') or os.environ.get('DOCKER_CONTEXT') or command(['docker', 'context', 'show']).strip() != b'default': raise ValueError('Remote/shared Docker targets forbidden')
    if os.geteuid() != 0: raise ValueError('Disposable fixture needs host file ownership')
    project = 'rollback-' + uuid.uuid4().hex
    bundles = {}
    for name in ('previous', 'current'):
        directory = ROOT / 'image-release' / name
        manifest = verify(directory)
        bundles[manifest['source_revision']] = dict(directory=directory, manifest=manifest, images={role:'laravel-'+role+':oci-'+name for role in ('php','nginx')})
    if len(bundles) != 2: raise ValueError('Require two distinct real Git sources')
    with tempfile.TemporaryDirectory(prefix=project+'-',dir=os.environ.get('RUNNER_TEMP')) as temporary:
        directory=Path(temporary); directory.chmod(0o755)
        for name in ('uploads','acme','tls','auth','state'):
            (directory/name).mkdir(mode=0o700 if name in ('auth','state') else 0o755)
        for name in ('private','public'):
            (directory/'uploads'/name).mkdir(mode=0o750)
            os.chown(directory/'uploads'/name,33,33)
        os.chown(directory/'uploads',33,33)
        domain='rollback.smoke.invalid';ca=directory/'ca.pem';key=directory/'key.pem'
        command(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN='+domain,'-addext','subjectAltName=DNS:'+domain,'-addext','extendedKeyUsage=serverAuth','-keyout',str(key),'-out',str(ca)])
        command(['python3',str(ROOT/'infra/aws/https/install-certificate.py'),'--domain',domain,'--mechanism','provided','--fullchain',str(ca),'--private-key',str(key),'--directory',str(directory/'tls'),'--nginx-gid','101'])
        ca.chmod(0o644)
        password=secrets.token_hex(24);runtime_file=directory/'runtime.env'
        runtime_file.write_text('APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:'+base64.b64encode(os.urandom(32)).decode()+'\nAPP_URL=https://'+domain+'\nDB_CONNECTION=pgsql\nDB_HOST=pgsql\nDB_PORT=5432\nDB_DATABASE=fixture\nDB_USERNAME=fixture\nDB_PASSWORD='+password+'\nDB_SSLMODE=disable\nSESSION_DRIVER=database\nCACHE_STORE=database\nQUEUE_CONNECTION=database\nSESSION_SECURE_COOKIE=true\nLOG_CHANNEL=stderr\n')
        runtime_file.chmod(0o600); input_hash=hashlib.sha256(runtime_file.read_bytes()).hexdigest()
        overlay=directory/'fixture.yaml'
        overlay.write_text('services:\n  pgsql:\n    image: postgres:16.15-bookworm@sha256:0ea6700a3b4f0ae6ce746519073558aed4d88a79d8d07622a9a644946c7319c4\n    user: postgres\n    environment:\n      POSTGRES_DB: fixture\n      POSTGRES_USER: fixture\n      POSTGRES_PASSWORD: '+password+'\n    networks: [app]\n    volumes: ["fixture-db:/var/lib/postgresql/data"]\n    healthcheck:\n      test: ["CMD", "pg_isready", "-h", "127.0.0.1", "-U", "fixture"]\n      interval: 2s\n      timeout: 5s\n      retries: 30\nvolumes:\n  fixture-db:\n')
        overlay.chmod(0o600)
        contract=dict(php_repository='fixture.invalid/php',nginx_repository='fixture.invalid/nginx',project=project,tls_ca_bundle=str(ca),runtime_env_file=str(runtime_file),compose_environment=dict(RUNTIME_ENV_FILE=str(runtime_file),UPLOAD_DIRECTORY=str(directory/'uploads'),DB_CA_BUNDLE=str(ca),TLS_DOMAIN=domain,TLS_CERTIFICATE_MECHANISM='provided',TLS_CERTIFICATE_DIRECTORY=str(directory/'tls'),TLS_ACME_WEBROOT=str(directory/'acme'),TLS_BIND_IPV4='127.0.0.1'))
        runtime=FixtureRuntime(contract,ROOT/'compose.production.release.yaml',directory/'auth',bundles,overlay)
        runtime.input_fingerprint=hashlib.sha256(runtime_file.read_bytes()).digest()
        releases=[]
        for bundle in bundles.values():
            manifest=bundle['manifest']
            releases.append(dict(source_revision=manifest['source_revision'],version=manifest['version'],manifest_sha256=hashlib.sha256((bundle['directory']/'image-release.json').read_bytes()).hexdigest(),assets_sha256=contract_hash(manifest['assets']),**{role+'_image':contract[role+'_repository']+'@'+manifest['images'][role]['digest'] for role in ('php','nginx')}))
        a,b=releases
        store=HostState(directory/'state')
        request=lambda selected,previous:dict(schema_version=1,contract_sha256=contract_hash(contract),release=selected,previous=previous)
        recovery=lambda current,previous,pending,target:dict(schema_version=1,expected_current=current,expected_previous=previous,expected_pending=pending,target=target,target_contract_sha256=contract_hash(contract),schema_compatible=True)
        try:
            runtime.compose_command(a,['up','-d','--wait','--wait-timeout','90','pgsql'])
            deploy(request(a,None),contract,store,runtime)
            # Native SQL/upload persistence independent of image/controller implementations.
            runtime.compose_command(a,['exec','-T','pgsql','psql','-U','fixture','-d','fixture','-v','ON_ERROR_STOP=1','-c',"CREATE TABLE rollback_fixture (value text PRIMARY KEY); INSERT INTO rollback_fixture VALUES ('retained');"])
            marker=directory/'uploads/private/rollback-fixture';marker.write_bytes(os.urandom(64));os.chown(marker,33,33);marker.chmod(0o600);upload_hash=hashlib.sha256(marker.read_bytes()).hexdigest()
            deploy(request(b,a),contract,store,runtime)
            processes_before=runtime.compose_command(b,['ps','-q','worker','scheduler']).decode().splitlines()
            rollback_request=recovery(b,a,None,'previous')
            receipt=rollback(rollback_request,store,runtime)
            if receipt['release']!=a or runtime.migrations!=2: raise ValueError('Rollback changed release or ran migration')
            processes_after=runtime.compose_command(a,['ps','-q','worker','scheduler']).decode().splitlines()
            if len(processes_after)!=2 or set(processes_before)&set(processes_after): raise ValueError('Rollback failed to reload both processes')
            rollback(rollback_request,store,runtime)
            if runtime.compose_command(a,['ps','-q','worker','scheduler']).decode().splitlines()!=processes_after: raise ValueError('Idempotent rollback restarted processes')
            # Forward replay then a real database outage after migration preserves recoverable state.
            deploy(request(b,a),contract,store,runtime)
            runtime.break_database=True
            try: deploy(request(a,b),contract,store,runtime)
            except RuntimeError: pass
            else: raise ValueError('Database loss falsely passed readiness')
            if store.read()['current']['release']!=b or store.read()['pending']['release']!=a: raise ValueError('Failure lost receipts')
            runtime.break_database=False
            runtime.compose_command(b,['up','-d','--wait','--wait-timeout','90','pgsql'])
            rollback(recovery(b,a,a,'current'),store,runtime)
            if runtime.migrations!=4: raise ValueError('Pending recovery reran migration')
            raw=runtime.compose_command(b,['exec','-T','pgsql','psql','-U','fixture','-d','fixture','-At','-c','SELECT value FROM rollback_fixture'])
            if raw.strip()!=b'retained' or hashlib.sha256(marker.read_bytes()).hexdigest()!=upload_hash or hashlib.sha256(runtime_file.read_bytes()).hexdigest()!=input_hash: raise ValueError('Rollback changed database/uploads/APP_KEY inputs')
            evidence=dict(schema_version=1,status='passed',source_revisions=[a['source_revision'],b['source_revision']],oci_digests={selected['version']:{role:selected[role+'_image'].split('@')[1] for role in ('php','nginx')} for selected in releases},checks=['retained-digests','https-assets-readiness','worker-scheduler-reload','read-only-retry','database-upload-private-input-persistence','failed-promotion-recovery'],unproven=['AWS-SSM','production-cutover','auth-session-roundtrip','zero-downtime','schema-reversal'])
        finally:
            runtime.compose_command(a,['down','--volumes','--timeout','30'])
            if command(['docker','ps','-aq','--filter','label=com.docker.compose.project='+project]).strip() or command(['docker','volume','ls','-q','--filter','label=com.docker.compose.project='+project]).strip(): raise ValueError('Owned disposable resources remain')
        (ROOT/'rollback-evidence.json').write_text(json.dumps(evidence,sort_keys=True)+'\n')
        print('Disposable rollback passed with retained true OCI digests, HTTPS smoke, process reload and persistent data; no AWS operation.')


if __name__=='__main__': main()
