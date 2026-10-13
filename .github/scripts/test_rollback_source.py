import base64
import json
import contextlib
import io
import subprocess
import tempfile
from pathlib import Path
import unittest
from unittest.mock import patch
from rollback_source import prepare, recover
from deploy_release import contract_hash
import test_deployment_source


class RollbackSourceTest(unittest.TestCase):
    def setUp(self):
        fixture = test_deployment_source.DeploymentSourceTest()
        fixture.setUp()
        self.target, self.identity = fixture.target, fixture.identity | {'source_revision': 'a'*40}
        self.target['contract'].update(runner_sha256='c'*64,rollback_runner_sha256='c'*64,compose_sha256='c'*64)
        self.target['contract_sha256']=contract_hash(self.target['contract'])
        def release(char):
            return dict(source_revision=char*40, version='v1.0.' + ('0' if char=='a' else '1'), php_image=self.target['contract']['php_repository']+'@sha256:'+char*64, nginx_image=self.target['contract']['nginx_repository']+'@sha256:'+char*64, manifest_sha256=char*64, assets_sha256=char*64)
        self.request=dict(schema_version=1, expected_current=release('b'), expected_previous=release('a'), expected_pending=None, target='previous', target_contract_sha256=self.target['contract_sha256'], schema_compatible=True)
    def test_prepare_binds_source_receipt_config_and_requires_owner_activation(self):
        with patch('rollback_source.file_hash', return_value='c'*64):
            self.assertEqual(prepare(self.target,self.identity,self.request),self.request['expected_previous'])
            for change in [dict(enabled=False),dict(document_version='$LATEST')]:
                with self.assertRaises(ValueError): prepare(self.target|change,self.identity,self.request)
            with self.assertRaises(ValueError): prepare(self.target,self.identity|dict(source_revision='b'*40),self.request)
            with self.assertRaises(ValueError): prepare(self.target,self.identity,self.request|dict(target_contract_sha256='f'*64))
        with patch('rollback_source.file_hash',return_value='f'*64),self.assertRaises(ValueError): prepare(self.target,self.identity,self.request)
    def test_rollback_operation_sends_only_bound_request_and_validates_target_receipt(self):
        calls=[]
        selected=self.request['expected_previous']
        receipt={key:selected[key] for key in ('source_revision','version','manifest_sha256')}|dict(completed_at='2026-01-01T00:00:00+00:00')
        def execute(args):
            calls.append(args)
            if args[1]=='send-command': return {'Command':{'CommandId':'00000000-0000-0000-0000-000000000001'}}
            return dict(CommandId='00000000-0000-0000-0000-000000000001',InstanceId=self.target['instance_id'],Status='Success',ResponseCode=0,StandardOutputContent=json.dumps(receipt))
        self.assertEqual(recover(self.target,self.request,execute),receipt)
        parameters=json.loads(calls[0][calls[0].index('--parameters')+1])
        self.assertEqual(parameters['Operation'],['rollback'])
        self.assertEqual(json.loads(base64.b64decode(parameters['Release'][0])),self.request)
        self.assertEqual(calls[0][calls[0].index('--document-hash')+1],self.target['document_sha256'])

    def test_acknowledged_host_command_is_preserved_when_polling_fails(self):
        acknowledged=[]
        command_id='00000000-0000-0000-0000-000000000001'
        def execute(args):
            if args[1]=='send-command': return {'Command':{'CommandId':command_id}}
            raise RuntimeError('PRIVATE_SQL_CANARY')
        with self.assertRaises(RuntimeError): recover(self.target,self.request,execute,acknowledged.append)
        self.assertEqual(acknowledged,[dict(command_id=command_id,instance_id=self.target['instance_id'])])

    def test_cli_logs_only_public_ack_before_success_or_poll_timeout(self):
        from rollback_source import main
        with tempfile.TemporaryDirectory() as temporary:
            directory=Path(temporary)
            for name,value in [('target',self.target),('identity',self.identity),('request',self.request)]: (directory/name).write_text(json.dumps(value))
            arguments=['rollback_source.py','execute','--target',str(directory/'target'),'--identity',str(directory/'identity'),'--request',str(directory/'request')]
            command_id='00000000-0000-0000-0000-000000000001'
            receipt={key:self.request['expected_previous'][key] for key in ('source_revision','version','manifest_sha256')}|dict(completed_at='2026-01-01T00:00:00+00:00')
            for fail in (False,True):
                def execute(args):
                    if args[1]=='send-command': return {'Command':{'CommandId':command_id}}
                    if fail: raise subprocess.TimeoutExpired(['PRIVATE_CANARY'],30)
                    return dict(CommandId=command_id,InstanceId=self.target['instance_id'],Status='Success',ResponseCode=0,StandardOutputContent=json.dumps(receipt))
                output,error=io.StringIO(),io.StringIO()
                with self.subTest(fail=fail),patch('sys.argv',arguments),patch.dict('os.environ',GITHUB_EVENT_NAME='workflow_dispatch',GITHUB_REF='refs/heads/main',IMAGE_ROLLBACK_AUTHORIZED='true'),patch('rollback_source.prepare',return_value=self.request['expected_previous']),patch('rollback_source.execute_aws',side_effect=execute),contextlib.redirect_stdout(output),contextlib.redirect_stderr(error):
                    self.assertEqual(main(),1 if fail else 0)
                records=[json.loads(line) for line in output.getvalue().splitlines()]
                self.assertEqual(records[0],dict(command_id=command_id,instance_id=self.target['instance_id']))
                self.assertEqual(records[1:],[] if fail else [receipt])
                self.assertNotIn('PRIVATE_CANARY',output.getvalue()+error.getvalue())

    def test_cli_timeout_is_nonzero_and_withholds_private_command_diagnostics(self):
        from rollback_source import main
        with tempfile.TemporaryDirectory() as temporary:
            directory=Path(temporary)
            for name,value in [('target',self.target),('identity',self.identity),('request',self.request)]:
                (directory/name).write_text(json.dumps(value))
            arguments=['rollback_source.py','execute','--target',str(directory/'target'),'--identity',str(directory/'identity'),'--request',str(directory/'request')]
            output=io.StringIO()
            with patch('sys.argv',arguments),patch.dict('os.environ',GITHUB_EVENT_NAME='workflow_dispatch',GITHUB_REF='refs/heads/main',IMAGE_ROLLBACK_AUTHORIZED='true'),patch('rollback_source.prepare',return_value=self.request['expected_previous']),patch('rollback_source.recover',side_effect=subprocess.TimeoutExpired(['PRIVATE_CANARY'],30)),contextlib.redirect_stderr(output):
                self.assertEqual(main(),1)
            self.assertNotIn('PRIVATE_CANARY',output.getvalue())
            self.assertIn('diagnostics withheld',output.getvalue())


if __name__=='__main__': unittest.main()
