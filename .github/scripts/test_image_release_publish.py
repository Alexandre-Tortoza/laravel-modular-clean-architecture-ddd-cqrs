import json
import unittest
from unittest.mock import patch

from image_release_publish import EcrRegistry, publication_plan, promote, repository
import test_image_release as fixtures
from image_release import create_manifest


class RegistryDouble:
    def __init__(self, existing=None):
        self.images = existing or {}
        self.copies = []
        self.logins = 0

    def lookup(self, target, tag):
        return self.images.get((target['name'], tag))

    def login(self, target):
        self.logins += 1

    def copy(self, archive, target, tag, digest):
        self.copies.append((archive, target, tag, digest))
        self.images[(target['name'], tag)] = digest


class ImagePromotionTest(unittest.TestCase):
    def setUp(self):
        self.repositories = {role: 'arn:aws:ecr:us-east-1:123456789012:repository/fixture/' + role for role in ('php', 'nginx')}
        self.fixture = fixtures.ImageReleaseTest()
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        self.manifest = create_manifest(self.fixture.bundle, self.fixture.source, self.fixture.sha, self.fixture.version)

    def test_promotes_identical_archive_digests_with_sha_and_semver_tags(self):
        registry = RegistryDouble()
        result = promote(self.fixture.bundle, self.repositories, registry)
        self.assertEqual(registry.logins, 1)
        self.assertEqual(len(registry.copies), 4)
        self.assertEqual({item[2] for item in registry.copies}, set(self.manifest['tags']))
        for role in self.repositories:
            self.assertTrue(result[role].endswith('@' + self.manifest['images'][role]['digest']))
        promote(self.fixture.bundle, self.repositories, registry)
        self.assertEqual(registry.logins, 1)
        self.assertEqual(len(registry.copies), 4)

    def test_conflicting_existing_tag_fails_before_any_login_or_write(self):
        registry = RegistryDouble({('fixture/nginx', self.manifest['tags'][1]): 'sha256:' + 'f' * 64})
        with self.assertRaises(ValueError):
            promote(self.fixture.bundle, self.repositories, registry)
        self.assertEqual((registry.logins, registry.copies), (0, []))

    def test_predecessor_must_remain_recoverable_in_the_exact_registry_before_publication(self):
        previous = self.fixture.root / 'previous'
        previous.mkdir()
        for role in ('php', 'nginx'):
            self.fixture.oci(previous, role, sha='b' * 40, version='v1.2.2')
        create_manifest(previous, self.fixture.source, 'b' * 40, 'v1.2.2')
        create_manifest(self.fixture.bundle, self.fixture.source, self.fixture.sha, self.fixture.version, previous)
        registry = RegistryDouble()
        with self.assertRaises(ValueError):
            promote(self.fixture.bundle, self.repositories, registry, previous)
        self.assertEqual((registry.logins, registry.copies), (0, []))
        promote(previous, self.repositories, registry)
        recovered = promote(self.fixture.bundle, self.repositories, registry, previous)
        self.assertEqual(set(recovered), {'php', 'nginx'})

    def test_rejects_foreign_account_region_or_duplicate_repository(self):
        for arn in [self.repositories['php'], self.repositories['nginx'].replace('123456789012', '999999999999'), self.repositories['nginx'].replace('us-east-1', 'eu-west-1')]:
            with self.subTest(arn=arn), self.assertRaises(ValueError):
                publication_plan(self.manifest, self.repositories | {'nginx': arn}, lambda target, tag: None)

    def test_only_documented_image_not_found_is_treated_as_absent(self):
        target = repository(self.repositories['php'])
        client = EcrRegistry(self.fixture.root / 'auth.json')
        absent = dict(images=[], failures=[dict(failureCode='ImageNotFound', imageId={'imageTag': 'v1.2.3'})])
        with patch.object(client, 'execute', return_value=json.dumps(absent).encode()):
            self.assertIsNone(client.lookup(target, 'v1.2.3'))
        for response in [dict(images=[], failures=[]), absent | {'failures': [dict(failureCode='AccessDenied')]}, dict(images=[{'imageId': {'imageDigest': 'config-id'}}], failures=[])]:
            with self.subTest(response=response), patch.object(client, 'execute', return_value=json.dumps(response).encode()), self.assertRaises(ValueError):
                client.lookup(target, 'v1.2.3')

    def test_registry_failure_does_not_expose_credentials_in_error(self):
        client = EcrRegistry(self.fixture.root / 'auth.json')
        class Failed:
            returncode = 1
            stdout = b'PRIVATE_PASSWORD_CANARY'
            stderr = b'PRIVATE_PASSWORD_CANARY'
        with patch('image_release_publish.subprocess.run', return_value=Failed()), self.assertRaises(RuntimeError) as failure:
            client.execute(['skopeo', 'login'], b'PRIVATE_PASSWORD_CANARY')
        self.assertNotIn('PRIVATE_PASSWORD_CANARY', str(failure.exception))


if __name__ == '__main__':
    unittest.main()
