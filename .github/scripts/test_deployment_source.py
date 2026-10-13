import json
import unittest
from unittest.mock import patch

from deployment_source import validate_target, send_and_wait
from deploy_release import contract_hash


class DeploymentSourceTest(unittest.TestCase):
    def setUp(self):
        self.target = dict(schema_version=1, enabled=True, environment='deploy', role_arn='arn:aws:iam::123456789012:role/fixture-deploy', instance_id='i-0123456789abcdef0', document_arn='arn:aws:ssm:us-east-1:123456789012:document/fixture-deploy', document_version='1', document_sha256='a'*64, contract_sha256='b'*64, contract={})
        self.target['contract'] = {'instance_id': self.target['instance_id'], 'aws_region': 'us-east-1', 'php_repository': '123456789012.dkr.ecr.us-east-1.amazonaws.com/fixture/php', 'nginx_repository': '123456789012.dkr.ecr.us-east-1.amazonaws.com/fixture/nginx'}
        self.target['contract_sha256'] = contract_hash(self.target['contract'])
        self.identity = dict(environment='deploy', role_arn=self.target['role_arn'], aws_region='us-east-1')
        self.request = dict(release=dict(source_revision='a'*40, version='v1.0.0', manifest_sha256='c'*64))
    def test_accepts_only_exact_exported_host_contract(self):
        validate_target(self.target, self.identity)
        changed = self.target | {'contract': self.target['contract'] | {'instance_id': 'i-11111111111111111'}}
        changed['contract_sha256'] = contract_hash(changed['contract'])
        with self.assertRaises(ValueError): validate_target(changed, self.identity)
    def test_requires_owner_activation_and_exact_target_document_version_hash(self):
        for changes in [dict(enabled=False), dict(instance_id='*'), dict(document_version='$LATEST'), dict(document_sha256='bad'), dict(document_arn=self.target['document_arn'].replace('123456789012','999999999999')), dict(role_arn=self.target['role_arn'].replace('123456789012','999999999999')), dict(environment='publisher')]:
            with self.subTest(changes=changes), self.assertRaises(ValueError): validate_target(self.target | changes, self.identity)
    def test_rejects_foreign_or_duplicate_registry_in_approved_target_before_execution(self):
        for repository in [self.target['contract']['php_repository'], self.target['contract']['nginx_repository'].replace('123456789012','999999999999'), self.target['contract']['nginx_repository'].replace('us-east-1','eu-west-1')]:
            changed = self.target | {'contract': self.target['contract'] | {'nginx_repository': repository}}
            changed['contract_sha256'] = contract_hash(changed['contract'])
            with self.subTest(repository=repository), self.assertRaises(ValueError): validate_target(changed, self.identity)
    def test_sends_exact_hash_bound_document_to_one_instance_and_observes_sanitized_receipt(self):
        calls=[]
        receipt = self.request['release'] | dict(completed_at='2026-01-01T00:00:00+00:00')
        def execute(arguments):
            calls.append(arguments)
            if arguments[1] == 'send-command': return {'Command': {'CommandId': '00000000-0000-0000-0000-000000000001'}}
            return dict(CommandId='00000000-0000-0000-0000-000000000001', InstanceId=self.target['instance_id'], Status='Success', ResponseCode=0, StandardOutputContent=json.dumps(receipt))
        acknowledged=[]
        self.assertEqual(send_and_wait(self.target, self.request, execute, lambda _: None, acknowledged.append), receipt)
        self.assertEqual(acknowledged, [{'command_id':'00000000-0000-0000-0000-000000000001', 'instance_id':self.target['instance_id']}])
        self.assertEqual(calls[0][calls[0].index('--document-version')+1], '1')
        self.assertEqual(calls[0][calls[0].index('--document-hash')+1], 'a'*64)
        self.assertEqual(calls[0][calls[0].index('--instance-ids')+1], self.target['instance_id'])
        self.assertNotIn('--targets', calls[0])
    def test_failed_wrong_host_or_invalid_public_receipt_never_report_success(self):
        for changes in [dict(Status='Failed',StandardErrorContent='PRIVATE_SQL_CANARY'),dict(InstanceId='i-11111111111111111'),dict(StandardOutputContent='PRIVATE_SQL_CANARY'),dict(StandardOutputContent=json.dumps(self.request['release'] | dict(source_revision='b'*40,completed_at='2026-01-01T00:00:00+00:00')))]:
            response=dict(CommandId='00000000-0000-0000-0000-000000000001',InstanceId=self.target['instance_id'],Status='Success',ResponseCode=0,StandardOutputContent=json.dumps(self.request['release'] | dict(completed_at='2026-01-01T00:00:00+00:00')))|changes
            with self.subTest(changes=changes), self.assertRaises((ValueError,RuntimeError)) as error:
                send_and_wait(self.target,self.request,lambda a: {'Command':{'CommandId':'00000000-0000-0000-0000-000000000001'}} if a[1]=='send-command' else response,lambda _:None)
            self.assertNotIn('PRIVATE_SQL_CANARY',str(error.exception))


if __name__ == '__main__': unittest.main()
