"""Bind a public rollback request to the protected identity and exact reviewed SSM document."""

import argparse
import base64
import json
import os
from pathlib import Path
import sys

from deployment_source import execute_aws, send_and_wait, validate_target
from image_release import file_hash
from deploy_release import validate_release
from rollback_release import validate_rollback_request


def prepare(target, identity, request):
    validate_target(target, identity)
    validate_rollback_request(request)
    selected = request['expected_previous'] if request['target'] == 'previous' else request['expected_current']
    if not selected or selected['source_revision'] != identity['source_revision']:
        raise ValueError('Protected source identity must match the retained rollback target')
    for release in (request['expected_current'], request['expected_previous'], request['expected_pending']):
        if release is not None: validate_release(release, target['contract'])
    if request['target_contract_sha256'] != target['contract_sha256']:
        raise ValueError('Require the reviewed retained configuration, not an inferred current config')
    files = {'runner_sha256': 'infra/aws/scripts/deploy_release.py', 'rollback_runner_sha256': 'infra/aws/scripts/rollback_release.py', 'compose_sha256': 'compose.production.release.yaml'}
    if any(file_hash(path) != target['contract'].get(key) for key, path in files.items()):
        raise ValueError('Reviewed SSM sources differ; owner must reconcile the exact document/configuration before rollback')
    return selected


def recover(target, request, execute):
    selected = request['expected_previous'] if request['target'] == 'previous' else request['expected_current']
    def explicit_operation(arguments):
        if arguments[1] == 'send-command':
            arguments = list(arguments)
            index = arguments.index('--parameters') + 1
            payload = json.loads(arguments[index])
            bound = json.loads(base64.b64decode(payload['Release'][0]))
            bound.pop('release', None)
            payload['Release'] = [base64.b64encode(json.dumps(bound, sort_keys=True, separators=(',', ':')).encode()).decode()]
            payload['Operation'] = ['rollback']
            arguments[index] = json.dumps(payload)
            arguments[arguments.index('--comment') + 1] = 'Approved retained Laravel application rollback'
        return execute(arguments)
    # The same polling/receipt boundary as deploy; payload remains the full bound rollback request.
    return send_and_wait(target, request | {'release': selected}, explicit_operation)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['prepare', 'execute'])
    parser.add_argument('--target', type=Path, default=Path('.github/aws-deployment.json'))
    parser.add_argument('--identity', type=Path, required=True)
    parser.add_argument('--request', type=Path, required=True)
    args = parser.parse_args()
    try:
        if os.environ.get('GITHUB_EVENT_NAME') != 'workflow_dispatch' or os.environ.get('GITHUB_REF') != 'refs/heads/main': raise ValueError('Recovery requires an approved main dispatch')
        target, identity = json.loads(args.target.read_bytes()), json.loads(args.identity.read_bytes())
        request = json.loads(os.environ['ROLLBACK_REQUEST']) if args.command == 'prepare' else json.loads(args.request.read_bytes())
        selected = prepare(target, identity, request)
        if args.command == 'prepare':
            args.request.write_text(json.dumps(request, sort_keys=True) + '\n')
            print('Rollback target and retained config verified; no JWT/AWS/host operation performed')
        else:
            if os.environ.get('IMAGE_ROLLBACK_AUTHORIZED') != 'true': raise ValueError('Rollback requires explicit owner activation')
            print(json.dumps(recover(target, request, execute_aws), sort_keys=True))
        return 0
    except (ValueError, KeyError, TypeError, OSError, RuntimeError):
        print('Controlled rollback failed; private artifact, credential and SSM diagnostics withheld', file=sys.stderr)
        return 1


if __name__ == '__main__': sys.exit(main())
