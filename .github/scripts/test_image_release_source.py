import unittest
from unittest.mock import patch
import hashlib
from pathlib import Path
import tempfile
import zipfile

from image_release_source import producer, prepare_publication, extract_artifact


class ProducerEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.repository = 'fixture-owner/fixture-repo'
        self.sha = 'a' * 40
        self.run = dict(repository={'full_name': self.repository}, event='workflow_dispatch', head_branch='main',
                        path='.github/workflows/immutable-images.yml', status='completed', conclusion='success', head_sha=self.sha)

    def test_accepts_successful_main_producer_at_exact_source(self):
        with patch('image_release_source.api', return_value=self.run):
            self.assertEqual(producer(self.repository, '123', self.sha), self.run)

    def test_rejects_pr_fork_wrong_workflow_failed_or_different_source(self):
        changes = [dict(event='pull_request'), dict(repository={'full_name': 'fork/repo'}), dict(head_branch='dev'),
                   dict(path='.github/workflows/other.yml'), dict(status='in_progress'), dict(conclusion='failure'), dict(head_sha='b' * 40)]
        for change in changes:
            with self.subTest(change=change), patch('image_release_source.api', return_value=self.run | change), self.assertRaises(ValueError):
                producer(self.repository, '123', self.sha)

    def test_refuses_publication_without_exact_main_quality_and_coverage(self):
        manifest = dict(version='v1.0.0', previous=None)
        for gates in [(False, True, []), (True, False, [])]:
            with self.subTest(gates=gates), patch('image_release_source.download', return_value=manifest), patch('image_release_source.gate_evidence', return_value=gates), self.assertRaises(ValueError):
                prepare_publication(self.repository, 123, '.', self.sha, 'v1.0.0', initial=True)

    def test_requires_exact_version_and_explicit_initial_or_predecessor(self):
        manifest = dict(version='v1.0.0', previous=None)
        with patch('image_release_source.download', return_value=manifest), patch('image_release_source.gate_evidence', return_value=(True, True, [])):
            with self.assertRaises(ValueError):
                prepare_publication(self.repository, 123, '.', self.sha, 'v1.0.1', initial=True)
            with self.assertRaises(ValueError):
                prepare_publication(self.repository, 123, '.', self.sha, 'v1.0.0')
            with self.assertRaises(ValueError):
                prepare_publication(self.repository, 123, '.', self.sha, 'v1.0.0', previous_run_id=122, initial=True)
            self.assertEqual(prepare_publication(self.repository, 123, '.', self.sha, 'v1.0.0', initial=True), manifest)

    def test_archive_digest_is_verified_before_safe_extraction(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            archive = root / 'artifact.zip'
            files = {f'current/{name}': b'fixture' for name in ('image-release.json', 'php.oci.tar', 'nginx.oci.tar')}
            with zipfile.ZipFile(archive, 'w') as contents:
                for name, value in files.items():
                    contents.writestr(name, value)
            expected = 'sha256:' + hashlib.sha256(archive.read_bytes()).hexdigest()
            extract_artifact(archive, expected, root / 'safe')
            self.assertEqual((root / 'safe/current/php.oci.tar').read_bytes(), b'fixture')
            with self.assertRaises(ValueError):
                extract_artifact(archive, 'sha256:' + 'f' * 64, root / 'tampered')
            self.assertFalse((root / 'tampered').exists())
            with zipfile.ZipFile(archive, 'w') as contents:
                contents.writestr('../escape', b'private')
            expected = 'sha256:' + hashlib.sha256(archive.read_bytes()).hexdigest()
            with self.assertRaises(ValueError):
                extract_artifact(archive, expected, root / 'unsafe')
            self.assertFalse((root / 'escape').exists())


if __name__ == '__main__':
    unittest.main()
