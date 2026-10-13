"""Read protected producer evidence and download retained OCI archives; never publish."""

import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

from image_release import file_hash, predecessor, verify_bundle
from template_release import api, gate_evidence


def producer(repository, run_id, revision=None):
    if not str(run_id).isdigit() or int(run_id) <= 0:
        raise ValueError('Select a numeric completed producer run ID.')
    run = api(f'repos/{repository}/actions/runs/{run_id}')
    if (run.get('repository', {}).get('full_name') != repository
            or run.get('event') != 'workflow_dispatch' or run.get('head_branch') != 'main'
            or run.get('path') != '.github/workflows/immutable-images.yml'
            or run.get('status') != 'completed' or run.get('conclusion') != 'success'
            or (revision is not None and run.get('head_sha') != revision)):
        raise ValueError('Require a successful same-repository producer dispatched from main at the selected SHA.')
    return run


def check_source(repository, manifest):
    revision = manifest['source_revision']
    for name, expected in manifest['assets']['lockfiles'].items():
        content = api(f'repos/{repository}/contents/{name}?ref={revision}')
        import base64
        import hashlib
        if content.get('encoding') != 'base64' or hashlib.sha256(base64.b64decode(content['content'])).hexdigest() != expected:
            raise ValueError('Retained dependency locks differ from the producer source.')


def download(repository, run_id, destination, revision=None):
    run = producer(repository, run_id, revision)
    artifacts = api(f'repos/{repository}/actions/runs/{run_id}/artifacts?per_page=100')['artifacts']
    matches = [item for item in artifacts if item['name'] == 'image-release' and not item['expired']]
    if len(matches) != 1:
        raise ValueError('The producer must retain one unexpired image-release artifact.')
    subprocess.run(['gh', 'run', 'download', str(run_id), '--repo', repository, '--name', 'image-release', '--dir', str(destination)], check=True)
    current, previous = Path(destination) / 'current', Path(destination) / 'previous'
    manifest = verify_bundle(current, previous if previous.exists() else None)
    if manifest['source_revision'] != run['head_sha']:
        raise ValueError('Producer artifact source SHA differs from its actual workflow source.')
    check_source(repository, manifest)
    if previous.exists():
        check_source(repository, json.loads((previous / 'image-release.json').read_text()))
    return manifest


def prepare_publication(repository, run_id, directory, revision, version, previous_run_id=None, initial=False):
    manifest = download(repository, run_id, directory, revision)
    if manifest['version'] != version:
        raise ValueError('The requested version differs from the built release.')
    quality, coverage, _ = gate_evidence(repository, revision)
    if not quality or not coverage:
        raise ValueError('Successful quality and exact coverage gates at the source SHA on main are required.')
    if initial:
        if previous_run_id or manifest['previous'] is not None:
            raise ValueError('Initial release must not overwrite an existing predecessor contract.')
    else:
        if not previous_run_id or manifest['previous'] is None:
            raise ValueError('A separately verified recoverable predecessor producer is required.')
        with tempfile.TemporaryDirectory(prefix='previous-evidence-') as temporary:
            previous_manifest = download(repository, previous_run_id, temporary)
            if manifest['previous'] != predecessor(Path(temporary) / 'current'):
                raise ValueError('The predecessor differs from its completed protected producer run.')
            if previous_manifest['version'] == version:
                raise ValueError('Predecessor version must be distinct.')
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['fetch-previous', 'prepare'])
    parser.add_argument('--run-id', required=True)
    parser.add_argument('--directory', type=Path, required=True)
    parser.add_argument('--source-revision')
    parser.add_argument('--version')
    parser.add_argument('--previous-run-id')
    parser.add_argument('--initial', action='store_true')
    args = parser.parse_args()
    if os.environ.get('GITHUB_EVENT_NAME') != 'workflow_dispatch' or os.environ.get('GITHUB_REF') != 'refs/heads/main':
        raise ValueError('Protected producer evidence is accepted only from a main workflow dispatch.')
    repository = os.environ['GITHUB_REPOSITORY']
    if args.command == 'fetch-previous':
        with tempfile.TemporaryDirectory(prefix='previous-producer-') as temporary:
            manifest = download(repository, args.run_id, temporary)
            shutil.copytree(Path(temporary) / 'current', args.directory)
    else:
        manifest = prepare_publication(repository, args.run_id, args.directory, args.source_revision, args.version, args.previous_run_id, args.initial)
    print(f"Verified protected producer for {manifest['version']} at {manifest['source_revision']}; no publication occurred.")


if __name__ == '__main__':
    main()
