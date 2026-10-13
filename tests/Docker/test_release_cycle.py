import importlib.util
import contextlib
import io
import os
import json
from pathlib import Path
import tempfile
import subprocess
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).with_name('release-cycle.py')
spec = importlib.util.spec_from_file_location('release_cycle', SCRIPT)
cycle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cycle)


class DisposableCycleBoundary(unittest.TestCase):
    def test_rejects_local_shared_or_remote_execution_before_any_docker_operation(self):
        approved = dict(GITHUB_ACTIONS='true', RUNNER_ENVIRONMENT='github-hosted', RUNNER_OS='Linux')
        for changes in [dict(GITHUB_ACTIONS='false'), dict(RUNNER_ENVIRONMENT='self-hosted'), dict(RUNNER_OS='Windows'), dict(DOCKER_HOST='ssh://production'), dict(DOCKER_CONTEXT='shared')]:
            with self.subTest(changes=changes), patch.dict(os.environ, approved | changes, clear=True), patch.object(cycle, 'command') as command:
                with self.assertRaises(ValueError):
                    cycle.require_disposable_runner([])
                command.assert_not_called()

    def test_rejects_arguments_and_non_default_native_context(self):
        with patch.dict(os.environ, dict(GITHUB_ACTIONS='true', RUNNER_ENVIRONMENT='github-hosted', RUNNER_OS='Linux'), clear=True), patch.object(cycle, 'command', return_value=b'production\n'):
            with self.assertRaises(ValueError):
                cycle.require_disposable_runner([])
            with self.assertRaises(ValueError):
                cycle.require_disposable_runner(['--project=production'])

    def test_accepts_only_argument_free_hosted_linux_with_default_context(self):
        with patch.dict(os.environ, dict(GITHUB_ACTIONS='true', RUNNER_ENVIRONMENT='github-hosted', RUNNER_OS='Linux'), clear=True), patch.object(cycle, 'command', return_value=b'default\n') as command:
            cycle.require_disposable_runner([])
            command.assert_called_once_with(['docker', 'context', 'show'])


class NativeInertiaClient(unittest.TestCase):
    def test_version_conflict_negotiates_current_header_before_checking_preserved_session(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            browser = cycle.Browser(None, directory)
            release = dict(source_revision='a' * 40, files={})
            selected = dict(source_revision='a' * 40, assets_sha256=cycle.contract_hash(release))
            page = dict(component='teams/Index', props=dict(auth=dict(user=dict(id=7)), teams=[{}, {}]))
            responses = [(409, b'', 'HTTP/2 409\nX-Inertia-Version: current-version\n'), (200, json.dumps(page).encode(), ''), (200, json.dumps(release).encode(), '')]
            with patch.object(browser, 'request', side_effect=responses) as request:
                browser.verify(7, selected)
            self.assertEqual(browser.asset_version, 'current-version')
            self.assertEqual(request.call_args_list[1].args, ('/settings/teams',))
            self.assertEqual(request.call_args_list[1].kwargs, dict(inertia=True))

    def test_version_conflict_without_current_header_is_not_blindly_retried(self):
        with tempfile.TemporaryDirectory() as temporary:
            browser = cycle.Browser(None, Path(temporary))
            with patch.object(browser, 'request', return_value=(409, b'', 'HTTP/2 409\n')) as request:
                with self.assertRaises(ValueError):
                    browser.verify(7, {})
                request.assert_called_once_with('/settings/teams', inertia=True)


class PrivateCliBoundary(unittest.TestCase):
    def test_subprocess_timeout_keeps_nonzero_status_without_printing_password_arguments(self):
        output = io.StringIO()
        failure = subprocess.TimeoutExpired(['curl', '--data-urlencode', 'password=privatefixturecanary'], 15)
        with patch.object(cycle, 'main', side_effect=failure), contextlib.redirect_stderr(output):
            self.assertEqual(cycle.cli(), 1)
        self.assertEqual(output.getvalue(), 'Disposable release cycle failed; private command diagnostics withheld.\nFailed stage: disposable-runner-guard\n')
        self.assertNotIn('privatefixturecanary', output.getvalue())
        self.assertNotIn('--data-urlencode', output.getvalue())


if __name__ == '__main__':
    unittest.main()
