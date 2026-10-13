"""Read-only GitHub protection preflight. No environment creation, JWT or AWS calls."""
import argparse
import json
import os
import pathlib
import re
import sys
import urllib.request


def require(condition, message):
    if not condition:
        raise ValueError(message)


def check(manifest, context, get, *, purpose, environment, source_revision, role_arn):
    require(manifest.get('schema_version') == 1 and manifest.get('enabled') is True, 'Identity activation has not been approved in the tracked manifest.')
    require(context.get('GITHUB_EVENT_NAME') == 'workflow_dispatch' and context.get('GITHUB_REF') == 'refs/heads/main', 'Only an explicit main workflow dispatch may enter the identity preflight.')
    require(re.fullmatch('[0-9a-f]{40}', source_revision) is not None, 'Use a complete source revision.')
    repository = manifest['repository']
    name = repository['full_name']
    require(re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', name) is not None and context.get('GITHUB_REPOSITORY') == name, 'Repository identity does not match the approved manifest.')
    identities = manifest['identities']
    require(purpose in {'publisher', 'deploy'}, 'Select one approved identity purpose.')
    require(identities['publisher']['role_arn'] != identities['deploy']['role_arn'] and identities['publisher']['environment'] != identities['deploy']['environment'] and identities['publisher']['environment_id'] != identities['deploy']['environment_id'], 'Publication and deployment require separate roles and environments.')
    identity = identities[purpose]
    require(environment == identity['environment'] and re.fullmatch(r'[A-Za-z0-9_.-]{1,255}', environment) is not None, 'Environment does not match the approved manifest.')
    account = manifest['aws']['account_id']
    require(re.fullmatch('[0-9]{12}', account) is not None, 'Use the approved AWS account ID.')
    require(all(isinstance(item.get('environment_id'), int) and item['environment_id'] > 0 and isinstance(item.get('environment_node_id'), str) and re.fullmatch(r'[A-Za-z0-9_=-]{1,200}', item['environment_node_id']) for item in identities.values()), 'Both protected environment IDs must be recorded after explicit owner activation.')
    require(role_arn == identity['role_arn'] and re.fullmatch(r'arn:aws:iam::' + account + r':role/[A-Za-z0-9_+=,.@/-]+', role_arn) is not None, 'Role does not match the approved identity/account.')
    region = manifest['aws']['region']
    require(re.fullmatch(r'[a-z]{2}-[a-z-]+-[0-9]+', region) is not None, 'Use an explicitly approved AWS region.')
    repositories = identities['publisher']['ecr_repository_arns']
    require(set(repositories) == {'php', 'nginx'} and len(set(repositories.values())) == 2, 'Approve two distinct PHP/Nginx ECR repository ARNs.')
    require(all(re.fullmatch('arn:aws:ecr:' + region + ':' + account + r':repository/[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)*', arn) for arn in repositories.values()), 'ECR repository scope does not match the approved account/region.')
    paths = identity['workflow_paths']
    require(isinstance(paths, list) and paths and all(re.fullmatch(r'\.github/workflows/[A-Za-z0-9_-]+\.yml', path) for path in paths), 'Approved workflow paths are required.')
    require(context.get('GITHUB_WORKFLOW_REF') in [name + '/' + path + '@refs/heads/main' for path in paths], 'The workflow is not approved for this purpose.')
    prefix = '/repos/' + name
    metadata = get(prefix)
    require(metadata['full_name'] == name and metadata['id'] == repository['id'] and metadata['owner']['id'] == repository['owner_id'] and metadata['default_branch'] == 'main', 'Immutable repository/owner identifiers or the default branch changed.')
    main = get(prefix + '/branches/main')
    require(main['name'] == 'main' and main.get('protected') is True, 'The approved main branch must be protected.')
    for revision in {source_revision, context.get('GITHUB_SHA', '')}:
        require(re.fullmatch('[0-9a-f]{40}', revision) is not None, 'Workflow/source revision is incomplete.')
        comparison = get(prefix + '/compare/' + revision + '...main')
        require(comparison.get('status') in {'identical', 'ahead'}, 'Workflow/source revision is outside approved main history.')
    customization = get(prefix + '/actions/oidc/customization/sub')
    require(customization.get('use_default') is True and customization.get('use_immutable_subject') is True, 'The effective OIDC template is custom or unknown; do not infer a legacy subject.')
    owner, repo = name.split('/')
    verified_prefix = 'repo:' + owner + '@' + str(repository['owner_id']) + '/' + repo + '@' + str(repository['id'])
    require(customization.get('sub_claim_prefix') == verified_prefix, 'Effective OIDC subject prefix does not match the immutable repository identity.')
    subject = verified_prefix + ':environment:' + environment
    require(identity['subject'] == subject, 'Approved trust subject does not match the effective GitHub environment subject.')
    protected = get(prefix + '/environments/' + environment)
    require(protected.get('id') == identity['environment_id'] and protected.get('node_id') == identity['environment_node_id'] and protected.get('name') == environment and protected.get('can_admins_bypass') is False, 'A pre-existing environment without administrator bypass is required.')
    reviewers = [rule for rule in protected.get('protection_rules', []) if rule.get('type') == 'required_reviewers']
    require(len(reviewers) == 1 and reviewers[0].get('prevent_self_review') is True and reviewers[0].get('reviewers'), 'Required human reviewers and prevention of self approval are mandatory.')
    require(all(item.get('type') in {'User', 'Team'} and isinstance(item.get('reviewer', {}).get('id'), int) and item['reviewer']['id'] > 0 for item in reviewers[0]['reviewers']), 'Reviewer identities must be concrete users or teams.')
    policy = protected.get('deployment_branch_policy')
    require(policy == {'protected_branches': False, 'custom_branch_policies': True}, 'Only the explicit custom main branch policy is supported.')
    branches = get(prefix + '/environments/' + environment + '/deployment-branch-policies?per_page=100')
    selected = branches.get('branch_policies', [])
    require(branches.get('total_count') == 1 and len(selected) == 1 and selected[0].get('name') == 'main' and selected[0].get('type') == 'branch', 'The environment must allow only main, with no tags, wildcard or hidden policies.')
    return {'environment': environment, 'environment_id': str(identity['environment_id']), 'environment_node_id': identity['environment_node_id'], 'workflow_ref': context['GITHUB_WORKFLOW_REF'], 'role_arn': role_arn, 'subject': subject, 'audience': 'sts.amazonaws.com',
            'aws_region': region, 'repository': name, 'repository_id': str(repository['id']), 'owner_id': str(repository['owner_id']),
            'source_revision': source_revision, 'workflow_revision': context['GITHUB_SHA'],
            'ecr_php_repository_arn': repositories['php'], 'ecr_nginx_repository_arn': repositories['nginx']}


class GitHubReadOnly:
    def __init__(self, token):
        self.token = token

    def __call__(self, path):
        request = urllib.request.Request('https://api.github.com' + path, headers={
            'Authorization': 'Bearer ' + self.token, 'Accept': 'application/vnd.github+json',
            'X-GitHub-Api-Version': '2026-03-10', 'User-Agent': 'laravel-template-identity-preflight'}, method='GET')
        with urllib.request.urlopen(request, timeout=15) as response:
            return json.load(response)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest', type=pathlib.Path, default=pathlib.Path('.github/aws-identities.json'))
    parser.add_argument('--purpose', required=True, choices=['publisher', 'deploy'])
    parser.add_argument('--environment', required=True)
    parser.add_argument('--source-revision', required=True)
    parser.add_argument('--role-arn', required=True)
    arguments = parser.parse_args()
    try:
        token = os.environ.get('GH_TOKEN', '')
        require(bool(token), 'A read-only GitHub token is required for protection inspection.')
        result = check(json.loads(arguments.manifest.read_text()), os.environ, GitHubReadOnly(token), purpose=arguments.purpose,
                       environment=arguments.environment, source_revision=arguments.source_revision, role_arn=arguments.role_arn)
        if os.environ.get('GITHUB_OUTPUT'):
            with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
                for key, value in result.items(): output.write(key + '=' + value + '\n')
        print(json.dumps(result, sort_keys=True))
    except (ValueError, KeyError, TypeError, OSError):
        print('Identity preflight rejected missing activation, untrusted source, unavailable metadata or insufficient environment/OIDC protection. No OIDC request or AWS operation performed.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__': sys.exit(main())
