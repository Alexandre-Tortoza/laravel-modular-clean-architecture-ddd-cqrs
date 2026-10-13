import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from template_release import gate_evidence, main, tag_revision, validate_release


class TemplateReleaseTest(unittest.TestCase):
    def setUp(self):
        self.sha = 'a' * 40
        self.state = {
            'main_sha': self.sha,
            'tag_sha': None,
            'release_exists': False,
            'quality': True,
            'coverage': True,
            'accepted_initial_issues': [48, 49, 51, 55, 56, 58],
        }

    def test_prepares_a_tested_main_revision_without_creating_a_tag(self):
        validate_release('v0.1.0', self.sha, self.state)
        self.assertIsNone(self.state['tag_sha'])

    def test_accepts_an_existing_tag_only_at_the_selected_revision(self):
        self.state['tag_sha'] = self.sha
        validate_release('v1.2.3', self.sha, self.state)

    def test_rejects_invalid_semver_and_revision_input(self):
        for version in ['1.2.3', 'v01.2.3', 'v1.2', 'v1.2.3;echo bad', 'v1.2.3\n']:
            with self.subTest(version=version), self.assertRaises(ValueError):
                validate_release(version, self.sha, self.state)
        for sha in ['main', 'a' * 39, 'g' * 40]:
            with self.subTest(sha=sha), self.assertRaises(ValueError):
                validate_release('v1.2.3', sha, self.state)

    def test_rejects_untested_non_main_duplicate_or_moved_releases(self):
        for override in [
            {'main_sha': 'b' * 40},
            {'quality': False},
            {'coverage': False},
            {'release_exists': True},
            {'tag_sha': 'b' * 40},
        ]:
            with self.subTest(override=override), self.assertRaises(ValueError):
                validate_release('v1.2.3', self.sha, {**self.state, **override})

    def test_initial_release_requires_acceptance_and_essential_documentation(self):
        for issue in self.state['accepted_initial_issues']:
            state = {**self.state, 'accepted_initial_issues': [n for n in self.state['accepted_initial_issues'] if n != issue]}
            with self.subTest(issue=issue), self.assertRaises(ValueError):
                validate_release('v0.1.0', self.sha, state)

    def test_resolves_annotated_tags_to_the_original_commit(self):
        responses = [{'object': {'type': 'tag', 'sha': 'b' * 40}}, {'object': {'type': 'commit', 'sha': self.sha}}]
        with patch('template_release.api', side_effect=responses):
            self.assertEqual(tag_revision('owner/repo', 'v1.2.3'), self.sha)

    def test_requires_latest_quality_and_coverage_results_on_main(self):
        run = dict(id=5, head_sha=self.sha, head_branch='main', status='completed', conclusion='success', html_url='https://example.invalid/run/5')
        jobs = {'jobs': [dict(name=name, conclusion='success') for name in ['ci', 'minimum-php', 'postgres-concurrency', 'sail', 'coverage']]}
        with patch('template_release.api', side_effect=[{'workflow_runs': [run]}, jobs]):
            self.assertEqual(gate_evidence('owner/repo', self.sha), (True, True, [run['html_url']]))
        for override in [dict(head_branch='dev'), dict(conclusion='failure'), dict(status='in_progress')]:
            with self.subTest(override=override), patch('template_release.api', return_value={'workflow_runs': [{**run, **override}]}):
                self.assertEqual(gate_evidence('owner/repo', self.sha), (False, False, []))
        jobs['jobs'][-1]['conclusion'] = 'failure'
        with patch('template_release.api', side_effect=[{'workflow_runs': [run]}, jobs]):
            self.assertEqual(gate_evidence('owner/repo', self.sha), (True, False, [run['html_url']]))

    def test_creates_a_reviewable_preview_with_get_only_evidence(self):
        environment = dict(GITHUB_REF='refs/heads/main', GITHUB_REPOSITORY='owner/repo', RELEASE_VERSION='v1.2.3', RELEASE_SHA=self.sha, RELEASE_CHANGES='Add an optional CLI generator.', RELEASE_RISKS='No stable contract changes.', RELEASE_UPGRADE='No database migration is needed.')
        previous = os.getcwd()
        with tempfile.TemporaryDirectory() as directory:
            try:
                os.chdir(directory)
                with patch.dict(os.environ, environment), patch('template_release.api', side_effect=[{'object': {'sha': self.sha}}, None]), patch('template_release.tag_revision', return_value=None), patch('template_release.gate_evidence', return_value=(True, True, ['https://example.invalid/run/5'])):
                    main()
                plan = json.loads(Path('release-preview/release-plan.json').read_text())
                self.assertEqual(plan['source_sha'], self.sha)
                self.assertFalse(plan['publish'])
                self.assertFalse(plan['deploy'])
                self.assertIn(environment['RELEASE_CHANGES'], Path('release-preview/release-notes.md').read_text())
            finally:
                os.chdir(previous)

    def test_rejects_dispatch_from_a_feature_branch_before_remote_access(self):
        with patch.dict(os.environ, {'GITHUB_REF': 'refs/heads/feature'}), patch('template_release.api') as remote:
            with self.assertRaises(ValueError):
                main()
            remote.assert_not_called()


if __name__ == '__main__':
    unittest.main()
