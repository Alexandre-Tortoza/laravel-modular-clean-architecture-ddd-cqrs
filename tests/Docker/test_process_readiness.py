"""Exercise the exact smoke scanner against Linux proc snapshots, without Laravel/AWS."""

import argparse
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
IMAGE = None


def php(arguments, directory=None):
    if IMAGE:
        command = ['docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'php']
        if directory is not None:
            command += ['--mount', f'type=bind,source={directory},target=/fixture,readonly']
        command += [IMAGE, *arguments]
    else:
        command = ['php', *arguments]
    return subprocess.run(command, capture_output=True, check=False)


class ArtisanChildReadinessTest(unittest.TestCase):
    def setUp(self):
        source = (ROOT / 'tests/Docker/processes-smoke.sh').read_text()
        match = re.search(r'if docker exec "\$container" php -r \'(.*?)\' "\$command"', source, re.S)
        if match is None:
            raise ValueError('The real readiness scanner is missing')
        self.scanner = match[1]
        result = php(['-r', 'echo readlink("/proc/self/exe");'])
        if result.returncode:
            raise RuntimeError('The selected PHP probe could not start')
        self.php_executable = result.stdout.decode()

    def check(self, command, peers, self_executable=True):
        with tempfile.TemporaryDirectory(prefix='artisan-readiness-') as temporary:
            directory = Path(temporary)
            directory.chmod(0o755)
            snapshot = directory / 'proc'
            (snapshot / 'self').mkdir(parents=True)
            if self_executable:
                (snapshot / 'self/exe').symlink_to(self.php_executable)
            for index, (executable, arguments) in enumerate(peers):
                # These cannot match a real Linux PID, including the scanner itself.
                process = snapshot / str(2_147_483_647 + index)
                process.mkdir()
                if executable is not None:
                    (process / 'exe').symlink_to(executable)
                (process / 'cmdline').write_bytes(b'\0'.join(value.encode() for value in arguments) + b'\0')
            target = '/fixture/proc/' if IMAGE else str(snapshot) + '/'
            code = self.scanner.replace('/proc/', target)
            result = php(['-r', code, command], directory)
            self.assertIn(result.returncode, (0, 1), 'Scanner must return only a readiness status, without leaking diagnostics')
            self.assertEqual(result.stdout, b'')
            self.assertEqual(result.stderr, b'')
            return result.returncode == 0

    def test_tini_and_entrypoint_arguments_do_not_prove_a_php_child_is_ready(self):
        wrappers = [('/usr/bin/tini', ['tini', '--', '/usr/local/bin/app-entrypoint', 'php', 'artisan', 'queue:work']),
                    ('/usr/bin/dash', ['sh', '/usr/local/bin/app-entrypoint', 'php', 'artisan', 'schedule:work'])]
        for executable, arguments in wrappers:
            with self.subTest(wrapper=arguments[0]):
                self.assertFalse(self.check(arguments[-1], [(executable, arguments)]))

    def test_php_wrapper_script_with_artisan_arguments_is_not_the_artisan_child(self):
        self.assertFalse(self.check('queue:work', [(self.php_executable, ['php', 'wrapper.php', 'artisan', 'queue:work'])]))

    def test_actual_php_artisan_child_is_required_and_detected_beside_its_wrapper(self):
        for command in ('queue:work', 'schedule:work'):
            wrapper = ('/usr/bin/tini', ['tini', '--', 'php', 'artisan', command])
            child = (self.php_executable, ['php', 'artisan', command, '--no-interaction'])
            with self.subTest(command=command):
                self.assertFalse(self.check(command, [wrapper]))
                self.assertTrue(self.check(command, [wrapper, child]))

    def test_missing_executable_evidence_fails_closed(self):
        self.assertFalse(self.check('queue:work', [(None, ['php', 'artisan', 'queue:work'])], self_executable=False))

    def test_other_commands_or_missing_processes_do_not_match(self):
        self.assertFalse(self.check('queue:work', []))
        self.assertFalse(self.check('schedule:work', [(self.php_executable, ['php', 'artisan', 'queue:work'])]))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--image')
    options, remaining = parser.parse_known_args()
    IMAGE = options.image
    unittest.main(argv=['test_process_readiness.py', *remaining])
