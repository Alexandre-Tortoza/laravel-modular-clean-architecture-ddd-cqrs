"""Prepare a reviewed template release; never create tags or publish releases."""

import json
import os
from pathlib import Path
import re
import subprocess


INITIAL_ACCEPTANCE = [48, 49, 51, 55, 56, 58]


def validate_release(version, sha, state):
    if not re.fullmatch(r'v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', version):
        raise ValueError('Use a stable SemVer tag, for example v0.1.0.')
    if not re.fullmatch(r'[a-f0-9]{40}', sha):
        raise ValueError('Select a full commit SHA.')
    if state['main_sha'] != sha:
        raise ValueError('The selected SHA must match the tested main revision.')
    if not state['quality'] or not state['coverage']:
        raise ValueError('Successful quality and exact coverage gates are required on main at this SHA.')
    if state['release_exists']:
        raise ValueError('This release already exists; versions cannot be overwritten.')
    if state['tag_sha'] is not None and state['tag_sha'] != sha:
        raise ValueError('The existing tag points elsewhere; tags cannot be moved.')
    if version == 'v0.1.0' and not set(INITIAL_ACCEPTANCE).issubset(state['accepted_initial_issues']):
        raise ValueError('Initial release requires template acceptance, release readiness and essential documentation.')


def api(path, missing_allowed=False):
    result = subprocess.run(['gh', 'api', '--method', 'GET', path], capture_output=True, text=True, check=False)
    if result.returncode != 0:
        if missing_allowed and '(HTTP 404)' in result.stderr:
            return None
        raise RuntimeError('GitHub release evidence could not be read.')
    return json.loads(result.stdout)


def tag_revision(repository, version):
    reference = api(f'repos/{repository}/git/ref/tags/{version}', missing_allowed=True)
    if reference is None:
        return None
    target = reference['object']
    for _ in range(10):
        if target['type'] == 'commit':
            return target['sha']
        if target['type'] != 'tag':
            break
        target = api(f'repos/{repository}/git/tags/{target["sha"]}')['object']
    raise ValueError('The tag must resolve to a commit.')


def gate_evidence(repository, sha):
    runs = api(f'repos/{repository}/actions/workflows/tests.yml/runs?head_sha={sha}&per_page=100')['workflow_runs']
    runs = sorted((run for run in runs if run['head_sha'] == sha and run['head_branch'] == 'main'), key=lambda run: run['id'], reverse=True)
    if not runs or runs[0]['status'] != 'completed' or runs[0]['conclusion'] != 'success':
        return False, False, []
    required = {'ci', 'minimum-php', 'postgres-concurrency', 'sail'}
    quality = False
    coverage = False
    evidence = []
    coverage_seen = False
    for run in runs:
        jobs = api(f'repos/{repository}/actions/runs/{run["id"]}/jobs?per_page=100')['jobs']
        conclusions = {job['name']: job['conclusion'] for job in jobs}
        if not quality and required.issubset(conclusions) and all(conclusions[name] == 'success' for name in required):
            quality = run['status'] == 'completed' and run['conclusion'] == 'success'
            if quality:
                evidence.append(run['html_url'])
        if not coverage_seen and conclusions.get('coverage') not in [None, 'skipped']:
            coverage_seen = True
            coverage = conclusions['coverage'] == 'success' and run['conclusion'] == 'success'
            if coverage:
                evidence.append(run['html_url'])
        if quality and coverage_seen:
            break
    return quality, coverage, list(dict.fromkeys(evidence))


def main():
    if os.environ.get('GITHUB_REF') != 'refs/heads/main':
        raise ValueError('Release preparation must be dispatched from main.')
    repository = os.environ['GITHUB_REPOSITORY']
    version = os.environ['RELEASE_VERSION']
    sha = os.environ['RELEASE_SHA']
    # Validate input syntax before constructing remote API paths.
    validate_release(version, sha, dict(main_sha=sha, tag_sha=None, release_exists=False, quality=True, coverage=True, accepted_initial_issues=INITIAL_ACCEPTANCE))
    main_sha = api(f'repos/{repository}/git/ref/heads/main')['object']['sha']
    quality, coverage, evidence = gate_evidence(repository, sha)
    accepted = []
    if version == 'v0.1.0':
        accepted = [issue for issue in INITIAL_ACCEPTANCE if api(f'repos/{repository}/issues/{issue}')['state'] == 'closed']
    state = dict(main_sha=main_sha, tag_sha=tag_revision(repository, version), release_exists=api(f'repos/{repository}/releases/tags/{version}', missing_allowed=True) is not None, quality=quality, coverage=coverage, accepted_initial_issues=accepted)
    validate_release(version, sha, state)
    sections = {name: os.environ.get(variable, '').strip() for name, variable in [('Changes', 'RELEASE_CHANGES'), ('Compatibility and risks', 'RELEASE_RISKS'), ('Upgrade and migration', 'RELEASE_UPGRADE')]}
    if any(not text for text in sections.values()):
        raise ValueError('Provide reviewed changes, compatibility risks and upgrade instructions.')
    destination = Path('release-preview')
    destination.mkdir(exist_ok=True)
    notes = f'# Template {version}\n\nSource: [{sha}](https://github.com/{repository}/commit/{sha})\n'
    for heading, text in sections.items():
        notes += f'\n## {heading}\n\n{text}\n'
    notes += '\nValidation: '+', '.join(evidence)+'\n\nPublication requires separate human authorization and revalidation of this SHA/tag. This preview does not deploy derived applications.\n'
    (destination/'release-notes.md').write_text(notes, encoding='utf-8')
    (destination/'release-plan.json').write_text(json.dumps(dict(version=version, source_sha=sha, repository=repository, checks=evidence, tag_exists=state['tag_sha'] is not None, publish=False, deploy=False), indent=2)+'\n', encoding='utf-8')
    print(f'Prepared {version} at {sha}. No tag or public release was created.')


if __name__ == '__main__':
    main()
