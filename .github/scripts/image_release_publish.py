"""Promote verified OCI bytes to approved immutable ECR repositories. No build operation."""

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

from image_release import verify_bundle


def repository(arn):
    match = re.fullmatch(r'arn:aws:ecr:([a-z]{2}-[a-z-]+-[0-9]+):([0-9]{12}):repository/([a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)*)', arn)
    if match is None:
        raise ValueError('Require an explicitly approved private ECR repository ARN.')
    region, account, name = match.groups()
    host = f'{account}.dkr.ecr.{region}.amazonaws.com'
    return dict(region=region, account=account, name=name, host=host, uri=host + '/' + name)


def publication_plan(manifest, repositories, lookup):
    if set(repositories) != {'php', 'nginx'}:
        raise ValueError('Approve exactly the PHP and HTTPS Nginx repositories.')
    parsed = {role: repository(arn) for role, arn in repositories.items()}
    if parsed['php']['uri'] == parsed['nginx']['uri'] or any(parsed['php'][key] != parsed['nginx'][key] for key in ('region', 'account')):
        raise ValueError('Require distinct repositories in the same approved region/account.')
    plan = []
    for role, target in parsed.items():
        digest = manifest['images'][role]['digest']
        for tag in manifest['tags']:
            existing = lookup(target, tag)
            if existing is not None and existing != digest:
                raise ValueError('An immutable release tag already identifies different OCI bytes; refuse overwrite.')
            plan.append(dict(role=role, target=target, tag=tag, digest=digest, exists=existing is not None))
    return plan


def promote(directory, repositories, registry, previous_directory=None):
    manifest = verify_bundle(directory, previous_directory)
    previous = manifest['previous']
    if previous is not None:
        retained = dict(images=previous['images'], tags=['release-' + previous['source_revision'], previous['version']])
        if any(not item['exists'] for item in publication_plan(retained, repositories, registry.lookup)):
            raise ValueError('The approved predecessor must remain published at its exact immutable digests before promoting a new release.')
    plan = publication_plan(manifest, repositories, registry.lookup)
    # Check every immutable tag before any login/write to avoid a known partial conflict.
    if any(not item['exists'] for item in plan):
        registry.login(plan[0]['target'])
    for item in plan:
        if not item['exists']:
            registry.copy(Path(directory) / manifest['images'][item['role']]['archive'], item['target'], item['tag'], item['digest'])
        if registry.lookup(item['target'], item['tag']) != item['digest']:
            raise ValueError('ECR manifest digest differs after promotion.')
    verify_bundle(directory, previous_directory)
    return {role: repository(arn)['uri'] + '@' + manifest['images'][role]['digest'] for role, arn in repositories.items()}


class EcrRegistry:
    def __init__(self, auth_file):
        self.auth_file = auth_file

    def execute(self, arguments, input_bytes=None):
        result = subprocess.run(arguments, input=input_bytes, capture_output=True, check=False)
        if result.returncode != 0:
            raise RuntimeError('Registry operation failed; credentials and tool diagnostics were withheld.')
        return result.stdout

    def lookup(self, target, tag):
        raw = self.execute(['aws', 'ecr', 'batch-get-image', '--region', target['region'], '--registry-id', target['account'],
                            '--repository-name', target['name'], '--image-ids', 'imageTag=' + tag, '--output', 'json'])
        response = json.loads(raw)
        images, failures = response.get('images', []), response.get('failures', [])
        if not images and len(failures) == 1 and failures[0].get('failureCode') == 'ImageNotFound' and failures[0].get('imageId', {}).get('imageTag') == tag:
            return None
        if failures or len(images) != 1:
            raise ValueError('ECR lookup failed; only a documented missing image may be created.')
        image = images[0]
        digest = image.get('imageId', {}).get('imageDigest', '')
        if image.get('registryId') != target['account'] or image.get('repositoryName') != target['name'] or image.get('imageId', {}).get('imageTag') != tag or not re.fullmatch(r'sha256:[a-f0-9]{64}', digest):
            raise ValueError('ECR lookup returned an unexpected registry/repository/tag/digest.')
        return digest

    def login(self, target):
        password = self.execute(['aws', 'ecr', 'get-login-password', '--region', target['region']])
        self.execute(['skopeo', 'login', '--authfile', str(self.auth_file), '--username', 'AWS', '--password-stdin', target['host']], password)

    def copy(self, archive, target, tag, digest):
        receipt = self.auth_file.parent / 'copied-digest'
        self.execute(['skopeo', 'copy', '--all', '--preserve-digests', '--dest-authfile', str(self.auth_file), '--digestfile', str(receipt),
                      'oci-archive:' + str(archive), 'docker://' + target['uri'] + ':' + tag])
        if receipt.read_text().strip() != digest:
            raise ValueError('Registry transport changed the original OCI manifest digest.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, required=True)
    parser.add_argument('--previous', type=Path)
    parser.add_argument('--php-repository-arn', required=True)
    parser.add_argument('--nginx-repository-arn', required=True)
    arguments = parser.parse_args()
    if (os.environ.get('GITHUB_EVENT_NAME') != 'workflow_dispatch' or os.environ.get('GITHUB_REF') != 'refs/heads/main'
            or os.environ.get('IMAGE_PUBLISH_AUTHORIZED') != 'true'):
        raise ValueError('Publication requires an explicitly activated protected main workflow dispatch.')
    if any(name.startswith('AWS_ENDPOINT_URL') for name in os.environ):
        raise ValueError('Custom AWS endpoints are not approved for production publication.')
    with tempfile.TemporaryDirectory(prefix='private-ecr-auth-', dir=os.environ['RUNNER_TEMP']) as temporary:
        auth = Path(temporary) / 'auth.json'
        auth.write_text('{}')
        auth.chmod(0o600)
        result = promote(arguments.directory, {'php': arguments.php_repository_arn, 'nginx': arguments.nginx_repository_arn}, EcrRegistry(auth), arguments.previous)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    main()
