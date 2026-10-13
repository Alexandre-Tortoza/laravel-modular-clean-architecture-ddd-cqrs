"""Prepare a bound release request offline; SSM invocation requires explicit activation."""

import argparse
import base64
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'infra/aws/scripts'))
from deploy_release import contract_hash, validate_request
from image_release import file_hash
from image_release_source import prepare_publication


def validate_target(target, identity):
    required = {'schema_version', 'enabled', 'environment', 'role_arn', 'instance_id', 'document_arn', 'document_version', 'document_sha256', 'contract_sha256', 'contract'}
    if not isinstance(target, dict) or set(target) != required or target['schema_version'] != 1 or target['enabled'] is not True:
        raise ValueError('Owner-approved deployment target activation is missing')
    if target['environment'] != identity['environment'] or target['role_arn'] != identity['role_arn']:
        raise ValueError('Deployment target differs from protected GitHub identity')
    role = re.fullmatch(r'arn:aws:iam::([0-9]{12}):role/[A-Za-z0-9_+=,.@/-]+', target['role_arn'])
    if not role or not re.fullmatch(r'arn:aws:ssm:' + re.escape(identity['aws_region']) + ':' + role[1] + r':document/[A-Za-z0-9_.-]+', target['document_arn']):
        raise ValueError('Require one account-owned regional document')
    if not re.fullmatch(r'i-(?:[a-f0-9]{8}|[a-f0-9]{17})', target['instance_id']) or not re.fullmatch('[1-9][0-9]*', target['document_version']):
        raise ValueError('Require one concrete EC2 host and numeric document version')
    if not re.fullmatch('[a-f0-9]{64}', target['document_sha256']) or target['contract_sha256'] != contract_hash(target['contract']):
        raise ValueError('Require the exact exported SSM document and Terraform contract hashes')
    registries = [target['contract'].get(image_role + '_repository', '') for image_role in ('php', 'nginx')]
    prefix = re.escape(role[1] + '.dkr.ecr.' + identity['aws_region'] + '.amazonaws.com/')
    if registries[0] == registries[1] or any(not re.fullmatch(prefix + r'[a-z0-9]+(?:[._/-][a-z0-9]+)*', value) for value in registries):
        raise ValueError('Require distinct private repositories in the exact approved account/region')
    if target['contract'].get('instance_id') != target['instance_id'] or target['contract'].get('aws_region') != identity['aws_region']:
        raise ValueError('The reviewed Terraform host/region differs from the identity/target')


def release_record(path, target):
    manifest = json.loads((path / 'image-release.json').read_bytes())
    contract = target['contract']
    return dict(source_revision=manifest['source_revision'], version=manifest['version'],
                php_image=contract['php_repository'] + '@' + manifest['images']['php']['digest'],
                nginx_image=contract['nginx_repository'] + '@' + manifest['images']['nginx']['digest'],
                manifest_sha256=file_hash(path / 'image-release.json'), assets_sha256=contract_hash(manifest['assets']))


def prepare(target, identity, directory, run_id, revision, version, previous_run_id, initial):
    validate_target(target, identity)
    contract = target['contract']
    expected_files = {'runner_sha256': Path('infra/aws/scripts/deploy_release.py'), 'compose_sha256': Path('compose.production.release.yaml')}
    if any(file_hash(path) != contract.get(key) for key, path in expected_files.items()):
        raise ValueError('Pipeline runtime sources differ from the reviewed Terraform document; reconcile infrastructure before deploying')
    prepare_publication(os.environ['GITHUB_REPOSITORY'], run_id, directory, revision, version, previous_run_id, initial)
    directory = Path(directory)
    request = dict(schema_version=1, contract_sha256=target['contract_sha256'], release=release_record(directory / 'current', target),
                   previous=release_record(directory / 'previous', target) if (directory / 'previous').exists() else None)
    validate_request(request, contract)
    return request


def send_and_wait(target, request, execute, sleep=time.sleep):
    payload = base64.b64encode(json.dumps(request, sort_keys=True, separators=(',', ':')).encode()).decode()
    if len(payload) > 8192: raise ValueError('The public deployment request exceeds the approved document limit')
    region = target['document_arn'].split(':')[3]
    arguments = ['ssm', 'send-command', '--region', region, '--instance-ids', target['instance_id'], '--document-name', target['document_arn'],
                 '--document-version', target['document_version'], '--document-hash', target['document_sha256'], '--document-hash-type', 'Sha256',
                 '--max-concurrency', '1', '--max-errors', '0', '--timeout-seconds', '120', '--comment', 'Approved immutable Laravel release',
                 '--parameters', json.dumps({'Release': [payload]}), '--output', 'json']
    response = execute(arguments)
    command_id = response.get('Command', {}).get('CommandId', '')
    if not re.fullmatch('[a-f0-9-]{36}', command_id): raise ValueError('SSM did not acknowledge a concrete command identifier')
    for _ in range(450):
        result = execute(['ssm', 'get-command-invocation', '--region', region, '--command-id', command_id, '--instance-id', target['instance_id'], '--output', 'json'])
        if result.get('CommandId') != command_id or result.get('InstanceId') != target['instance_id']:
            raise ValueError('SSM observation differs from the acknowledged command/host')
        status = result.get('Status')
        if status in {'Pending', 'InProgress', 'Delayed'}:
            sleep(2)
            continue
        if status != 'Success' or result.get('ResponseCode') != 0:
            raise RuntimeError('The approved host command did not succeed; private SSM diagnostics withheld')
        try:
            receipt = json.loads(result.get('StandardOutputContent', ''))
            selected = request['release']
            if set(receipt) != {'source_revision', 'version', 'manifest_sha256', 'completed_at'} or any(receipt[key] != selected[key] for key in ('source_revision', 'version', 'manifest_sha256')):
                raise ValueError('wrong public receipt')
            if datetime.fromisoformat(receipt['completed_at']).tzinfo is None: raise ValueError('missing UTC receipt')
        except (ValueError, KeyError, TypeError):
            raise ValueError('The host did not return the exact validated public release receipt') from None
        return receipt
    raise RuntimeError('SSM observation timed out; inspect the known command before retrying, without printing its private output')


def execute_aws(arguments):
    if any(key.startswith('AWS_ENDPOINT_URL') for key in os.environ): raise ValueError('Custom AWS endpoints are not approved')
    result = subprocess.run(['aws', *arguments, '--no-cli-pager', '--cli-connect-timeout', '5', '--cli-read-timeout', '15'], capture_output=True, timeout=30, check=False)
    if result.returncode:
        # Eventual visibility of the known command is safe to retry, never SendCommand itself.
        if arguments[1] == 'get-command-invocation' and b'(InvocationDoesNotExist)' in result.stderr:
            return dict(CommandId=arguments[arguments.index('--command-id') + 1], InstanceId=arguments[arguments.index('--instance-id') + 1], Status='Pending')
        raise RuntimeError('SSM operation failed; private AWS diagnostics withheld')
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['prepare', 'execute'])
    parser.add_argument('--target', type=Path, default=Path('.github/aws-deployment.json'))
    parser.add_argument('--identity', type=Path, required=True)
    parser.add_argument('--request', type=Path, required=True)
    parser.add_argument('--directory', type=Path)
    parser.add_argument('--run-id')
    parser.add_argument('--source-revision')
    parser.add_argument('--version')
    parser.add_argument('--previous-run-id')
    parser.add_argument('--initial', action='store_true')
    args = parser.parse_args()
    try:
        if os.environ.get('GITHUB_EVENT_NAME') != 'workflow_dispatch' or os.environ.get('GITHUB_REF') != 'refs/heads/main': raise ValueError('Only a reviewed main workflow dispatch may prepare deployment')
        target, identity = json.loads(args.target.read_bytes()), json.loads(args.identity.read_bytes())
        validate_target(target, identity)
        if args.command == 'prepare':
            request = prepare(target, identity, args.directory, args.run_id, args.source_revision, args.version, args.previous_run_id, args.initial)
            args.request.write_text(json.dumps(request, sort_keys=True) + '\n')
            print('Bound deployment request verified; no JWT, AWS or host execution performed')
        else:
            if os.environ.get('IMAGE_DEPLOY_AUTHORIZED') != 'true': raise ValueError('Deployment execution requires separate explicit owner activation')
            request = json.loads(args.request.read_bytes())
            validate_request(request, target['contract'])
            print(json.dumps(send_and_wait(target, request, execute_aws), sort_keys=True))
        return 0
    except (ValueError, KeyError, TypeError, OSError, RuntimeError, subprocess.SubprocessError):
        print('Controlled deployment failed; private artifact, credential and SSM diagnostics withheld', file=sys.stderr)
        return 1


if __name__ == '__main__': sys.exit(main())
