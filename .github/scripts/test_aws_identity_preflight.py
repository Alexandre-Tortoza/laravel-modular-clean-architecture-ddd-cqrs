"""Offline fail-closed identity protection tests: no token, AWS or settings mutations."""
import copy
import importlib.util
import pathlib
import unittest

PATH = pathlib.Path(__file__).with_name('aws_identity_preflight.py')
spec = importlib.util.spec_from_file_location('preflight', PATH)
preflight = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preflight)

class IdentityPreflight(unittest.TestCase):
    def setUp(self):
        self.sha = 'a' * 40
        self.prefix = 'repo:owner@123/template@456'
        self.manifest = {
            'schema_version': 1, 'enabled': True,
            'repository': {'full_name': 'owner/template', 'id': 456, 'owner_id': 123},
            'aws': {'account_id': '123456789012', 'region': 'us-fixture-1'},
            'identities': {
                purpose: {'environment': purpose, 'environment_id': 1 if purpose == 'publisher' else 2, 'environment_node_id': 'environment-' + purpose, 'role_arn': f'arn:aws:iam::123456789012:role/{purpose}',
                          'subject': self.prefix + ':environment:' + purpose,
                          'workflow_paths': ['.github/workflows/aws-identity.yml', '.github/workflows/publish-images.yml']}
                for purpose in ['publisher', 'deploy']
            },
        }
        self.manifest['identities']['publisher']['ecr_repository_arns'] = {
            role: f'arn:aws:ecr:us-fixture-1:123456789012:repository/fixture/{role}' for role in ['php', 'nginx']}
        self.context = {'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF': 'refs/heads/main',
                        'GITHUB_REPOSITORY': 'owner/template', 'GITHUB_SHA': self.sha,
                        'GITHUB_WORKFLOW_REF': 'owner/template/.github/workflows/aws-identity.yml@refs/heads/main'}
        self.responses = {
            '': {'full_name': 'owner/template', 'id': 456, 'owner': {'id': 123}, 'default_branch': 'main'},
            '/branches/main': {'name': 'main', 'protected': True, 'commit': {'sha': self.sha}},
            '/actions/oidc/customization/sub': {'use_default': True, 'use_immutable_subject': True, 'sub_claim_prefix': self.prefix},
            '/environments/publisher': {'name': 'publisher', 'id': 1, 'node_id': 'environment-publisher', 'can_admins_bypass': False,
                'protection_rules': [{'type': 'required_reviewers', 'prevent_self_review': True,
                    'reviewers': [{'type': 'User', 'reviewer': {'id': 999}}]}],
                'deployment_branch_policy': {'protected_branches': False, 'custom_branch_policies': True}},
            '/environments/publisher/deployment-branch-policies?per_page=100': {'total_count': 1, 'branch_policies': [{'name': 'main', 'type': 'branch'}]},
            '/compare/' + self.sha + '...main': {'status': 'identical'},
        }
        self.reads = []

    def get(self, path):
        self.reads.append(path)
        return copy.deepcopy(self.responses[path.removeprefix('/repos/owner/template')])

    def exercise(self, **overrides):
        return preflight.check(self.manifest, self.context, self.get, purpose=overrides.get('purpose', 'publisher'),
                               environment=overrides.get('environment', 'publisher'), source_revision=self.sha,
                               role_arn=overrides.get('role_arn', 'arn:aws:iam::123456789012:role/publisher'))

    def test_exact_approved_identity_passes_without_any_aws_request(self):
        result = self.exercise()
        self.assertEqual(result['subject'], self.prefix + ':environment:publisher')
        self.assertEqual(result['audience'], 'sts.amazonaws.com')
        self.assertEqual(result['role_arn'], 'arn:aws:iam::123456789012:role/publisher')
        self.assertTrue(all(path.startswith('/repos/owner/template') for path in self.reads))

    def test_disabled_policy_and_untrusted_events_fail_before_network(self):
        self.manifest['enabled'] = False
        with self.assertRaises(ValueError): self.exercise()
        self.assertEqual(self.reads, [])
        self.manifest['enabled'] = True
        for event, ref in [('pull_request', 'refs/pull/1/merge'), ('pull_request_target', 'refs/heads/main'), ('push', 'refs/heads/main'), ('workflow_dispatch', 'refs/heads/dev'), ('workflow_dispatch', 'refs/tags/main')]:
            self.context.update(GITHUB_EVENT_NAME=event, GITHUB_REF=ref)
            with self.assertRaises(ValueError): self.exercise()
        self.assertEqual(self.reads, [])

    def test_missing_environment_is_rejected_without_creating_it(self):
        del self.responses['/environments/publisher']
        with self.assertRaises(KeyError): self.exercise()
        self.assertTrue(all(path.startswith('/repos/') for path in self.reads))

    def test_missing_or_bypassable_reviews_fail(self):
        original = copy.deepcopy(self.responses['/environments/publisher'])
        for patch in [{'id': 999}, {'protection_rules': []}, {'can_admins_bypass': True}, {'can_admins_bypass': None}, {'protection_rules': [{'type': 'required_reviewers', 'prevent_self_review': False, 'reviewers': [{'type':'User','reviewer':{'id':999}}]}]}, {'protection_rules': [{'type': 'required_reviewers', 'prevent_self_review': True, 'reviewers': []}]}]:
            self.responses['/environments/publisher'] = dict(original, **patch)
            with self.subTest(patch=patch), self.assertRaises(ValueError): self.exercise()

    def test_broad_tag_or_incomplete_branch_policies_fail(self):
        path = '/environments/publisher/deployment-branch-policies?per_page=100'
        for policies in [[{'name':'*','type':'branch'}], [{'name':'main','type':'tag'}], [{'name':'main'}], [{'name':'main','type':'branch'},{'name':'dev','type':'branch'}]]:
            self.responses[path] = {'total_count':len(policies), 'branch_policies':policies}
            with self.subTest(policies=policies), self.assertRaises(ValueError): self.exercise()
        self.responses[path] = {'total_count':2, 'branch_policies':[{'name':'main','type':'branch'}]}
        with self.assertRaises(ValueError): self.exercise()

    def test_foreign_repo_role_environment_subject_and_workflow_fail(self):
        for field, value in [('role_arn','arn:aws:iam::999999999999:role/publisher'),('environment','other')]:
            with self.subTest(field=field), self.assertRaises(ValueError): self.exercise(**{field:value})
        self.manifest['identities']['publisher']['subject'] = 'repo:owner/template:environment:publisher'
        with self.assertRaises(ValueError): self.exercise()
        self.manifest['identities']['publisher']['subject'] = self.prefix + ':environment:publisher'
        self.context['GITHUB_WORKFLOW_REF'] = 'owner/template/.github/workflows/unapproved.yml@refs/heads/main'
        with self.assertRaises(ValueError): self.exercise()

    def test_custom_or_unknown_immutable_template_fails(self):
        path='/actions/oidc/customization/sub'
        for value in [{'use_default':True,'use_immutable_subject':False,'sub_claim_prefix':'repo:owner/template'}, {'use_default':False,'include_claim_keys':['repo','context']}, {'use_default':True}, {'use_default':True,'use_immutable_subject':True,'sub_claim_prefix':'repo:foreign@123/template@456'}]:
            self.responses[path] = value
            with self.subTest(value=value), self.assertRaises(ValueError): self.exercise()

    def test_separate_roles_and_environments_are_mandatory(self):
        self.manifest['identities']['deploy']['role_arn'] = self.manifest['identities']['publisher']['role_arn']
        with self.assertRaises(ValueError): self.exercise()
        self.manifest['identities']['deploy']['role_arn'] = 'arn:aws:iam::123456789012:role/deploy'
        self.manifest['identities']['deploy']['environment'] = 'publisher'
        with self.assertRaises(ValueError): self.exercise()

    def test_unprotected_main_or_non_ancestor_source_fails(self):
        self.responses['/branches/main']['protected'] = False
        with self.assertRaises(ValueError): self.exercise()
        self.responses['/branches/main']['protected'] = True
        for status in ['diverged','behind','unknown']:
            self.responses['/compare/'+self.sha+'...main']['status']=status
            with self.subTest(status=status), self.assertRaises(ValueError): self.exercise()

if __name__ == '__main__': unittest.main()
