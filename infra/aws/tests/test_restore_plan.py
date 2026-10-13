"""Pure recovery planning: metadata fixtures only, no AWS or production data."""

import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SCRIPT = Path(__file__).parents[1] / 'scripts/restore_plan.py'


def fixture():
    account, region = '111122223333', 'us-east-1'
    kms = f'arn:aws:kms:{region}:{account}:key/00000000-0000-0000-0000-000000000001'
    subnet = lambda number, zone: dict(id='subnet-' + number * 17, availability_zone=region + zone, public=False, vpc_id='vpc-' + '1' * 17)
    return dict(schema_version=1, account_id=account, region=region,
                source=dict(database_identifier='fixture-source', upload_volume_id='vol-' + '1' * 17, host_instance_id='i-' + '1' * 17,
                            admin_secret_arn=f'arn:aws:secretsmanager:{region}:{account}:secret:source-admin-ABCDEF',
                            application_secret_arn=f'arn:aws:secretsmanager:{region}:{account}:secret:source-app-ABCDEF', app_key_sha256='a' * 64,
                            database_storage_kms_key_arn=kms),
                target=dict(database_identifier='fixture-recovered', upload_volume_label='fixture-recovered-uploads', host_label='fixture-recovered-host',
                            application_secret_arn=f'arn:aws:secretsmanager:{region}:{account}:secret:recovered-app-ABCDEF',
                            administrator_secret_kms_key_arn=kms, upload_kms_key_arn=kms, ca_cert_identifier='rds-ca-rsa2048-g1',
                            parameter_group_name='fixture-postgres16-tls', force_ssl=True, tls_mode='verify-full', ca_file='/approved/rds-ca.pem'),
                network=dict(vpc_id='vpc-' + '1' * 17, private_subnets=[subnet('2', 'a'), subnet('3', 'b')],
                             database_subnet_group_name='fixture-private-database', database_subnet_group_ids=['subnet-' + '2' * 17, 'subnet-' + '3' * 17],
                             database_security_group_id='sg-' + '4' * 17, database_ingress_source_security_group_id='sg-' + '5' * 17,
                             database_ingress_port=5432, database_public_ingress=False, host_security_group_id='sg-' + '5' * 17, host_subnet_id='subnet-' + '2' * 17),
                database_restore=dict(method='snapshot', snapshot_arn=f'arn:aws:rds:{region}:{account}:snapshot:fixture-snapshot', source_identifier='fixture-source'),
                upload_restore=dict(snapshot_id='snap-' + '6' * 17, source_volume_id='vol-' + '1' * 17, availability_zone=region + 'a'))


class RestorePlanTest(unittest.TestCase):
    def load(self):
        self.assertTrue(SCRIPT.is_file(), 'Recovery planner is missing')
        spec = importlib.util.spec_from_file_location('restore_plan', SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_creates_only_a_private_new_target_plan_with_separate_admin_and_recovered_app_key(self):
        config = fixture()
        before = copy.deepcopy(config)
        plan = self.load().plan_recovery(config)
        self.assertEqual(config, before)
        self.assertEqual(plan['database']['target_identifier'], 'fixture-recovered')
        self.assertFalse(plan['database']['publicly_accessible'])
        self.assertTrue(plan['database']['manage_master_user_password'])
        self.assertEqual(plan['database']['tls_mode'], 'verify-full')
        self.assertEqual(plan['uploads']['operation'], 'create-new-encrypted-volume-from-snapshot')
        self.assertFalse(plan['uploads']['initialize_or_format'])
        self.assertEqual(plan['host']['operation'], 'create-new-private-recovery-host')
        self.assertEqual(plan['application_key']['copy_only_field'], 'APP_KEY')
        self.assertEqual(plan['application_key']['expected_sha256'], config['source']['app_key_sha256'])
        self.assertIn('must-differ-from-source-managed-admin-secret', plan['database']['post_restore_checks'])
        self.assertNotIn(config['source']['admin_secret_arn'], json.dumps(plan['application_key']))

    def test_refuses_source_reuse_and_unrelated_backup_identifiers(self):
        for section, key, value in [('target', 'database_identifier', 'fixture-source'), ('target', 'upload_volume_label', 'vol-' + '1' * 17),
                                    ('target', 'host_label', 'i-' + '1' * 17), ('database_restore', 'source_identifier', 'other-source'),
                                    ('upload_restore', 'source_volume_id', 'vol-' + '9' * 17),
                                    ('target', 'application_secret_arn', fixture()['source']['application_secret_arn'])]:
            config = fixture()
            config[section][key] = value
            with self.subTest(section=section, key=key), self.assertRaises(ValueError):
                self.load().plan_recovery(config)

    def test_refuses_public_unbound_network_or_disabled_tls(self):
        cases = [('network', 'database_public_ingress', True), ('network', 'database_ingress_port', 80),
                 ('network', 'database_ingress_source_security_group_id', 'sg-' + '9' * 17),
                 ('network', 'host_subnet_id', 'subnet-' + '9' * 17), ('network', 'database_subnet_group_ids', ['subnet-' + '9' * 17]),
                 ('target', 'force_ssl', False), ('target', 'tls_mode', 'disable')]
        for section, key, value in cases:
            config = fixture()
            config[section][key] = value
            with self.subTest(section=section, key=key), self.assertRaises(ValueError):
                self.load().plan_recovery(config)
        config = fixture()
        config['network']['private_subnets'][0]['public'] = True
        with self.assertRaises(ValueError):
            self.load().plan_recovery(config)

    def test_pitr_requires_selected_time_within_the_recorded_utc_window(self):
        config = fixture()
        config['database_restore'] = dict(method='pitr', source_identifier='fixture-source', restore_time='2026-10-12T12:00:00Z',
                                          earliest_restorable_time='2026-10-11T12:00:00Z', latest_restorable_time='2026-10-13T12:00:00Z')
        self.assertEqual(self.load().plan_recovery(config)['database']['restore']['restore_time'], '2026-10-12T12:00:00Z')
        for changed in ['2026-10-10T12:00:00Z', '2026-10-14T12:00:00Z', '2026-10-12T12:00:00', 'latest']:
            config['database_restore']['restore_time'] = changed
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                self.load().plan_recovery(config)

    def test_refuses_foreign_account_plaintext_secrets_and_overwrite_fields(self):
        config = fixture()
        config['target']['upload_kms_key_arn'] = config['target']['upload_kms_key_arn'].replace('111122223333', '999988887777')
        with self.assertRaises(ValueError):
            self.load().plan_recovery(config)
        for section, key, value in [('source', 'APP_KEY', 'private-secret-canary'), ('target', 'password', 'private-secret-canary'),
                                    ('target', 'existing_volume_id', 'vol-' + '9' * 17), ('database_restore', 'use_latest_restorable_time', True)]:
            config = fixture()
            config[section][key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.load().plan_recovery(config)

    def test_cli_is_preview_only_and_leaves_metadata_unchanged(self):
        self.load()
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'public-config.json'
            original = json.dumps(fixture())
            path.write_text(original)
            result = subprocess.run([sys.executable, str(SCRIPT), '--config', str(path)], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout)['mode'], 'offline-plan-only')
            self.assertEqual(path.read_text(), original)
            rejected = subprocess.run([sys.executable, str(SCRIPT), '--config', str(path), '--execute'], capture_output=True, text=True)
            self.assertNotEqual(rejected.returncode, 0)
            self.assertEqual(path.read_text(), original)

    def test_database_restore_preserves_the_approved_encrypted_source_storage(self):
        config = fixture()
        plan = self.load().plan_recovery(config)
        self.assertTrue(plan['database']['storage_encrypted'])
        self.assertEqual(plan['database']['storage_kms_key_arn'], config['source']['database_storage_kms_key_arn'])

    def test_accepts_automated_snapshot_arns_but_refuses_other_colon_namespaces(self):
        config = fixture()
        config['database_restore']['snapshot_arn'] = 'arn:aws:rds:us-east-1:111122223333:snapshot:rds:fixture-source-2026-10-12-12-00'
        self.assertEqual(self.load().plan_recovery(config)['database']['restore']['snapshot_arn'], config['database_restore']['snapshot_arn'])
        config['database_restore']['snapshot_arn'] = config['database_restore']['snapshot_arn'].replace('snapshot:rds:', 'snapshot:foreign:')
        with self.assertRaises(ValueError):
            self.load().plan_recovery(config)


if __name__ == '__main__':
    unittest.main()
