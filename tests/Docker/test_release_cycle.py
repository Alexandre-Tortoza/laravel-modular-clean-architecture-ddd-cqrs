import importlib.util
import os
from pathlib import Path
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


if __name__ == '__main__':
    unittest.main()
