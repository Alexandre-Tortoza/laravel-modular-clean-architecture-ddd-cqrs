"""Generate a reviewable recovery plan from public metadata. No execution/API option."""

import argparse
import datetime
import hashlib
import json
from pathlib import Path
import re
import sys


def require(condition):
    if not condition:
        raise ValueError('Recovery metadata violates the selected private/new-target contract')


def fields(value, names):
    require(isinstance(value, dict) and set(value) == set(names.split()))


def matches(pattern, value):
    return isinstance(value, str) and re.fullmatch(pattern, value) is not None


def identifier(value):
    return matches(r'[a-z][a-z0-9-]{0,62}', value) and not value.endswith('-') and '--' not in value


def utc(value):
    require(isinstance(value, str) and matches(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z', value))
    return datetime.datetime.fromisoformat(value.replace('Z', '+00:00'))


def plan_recovery(config):
    fields(config, 'schema_version account_id region source target network database_restore upload_restore')
    require(type(config['schema_version']) is int and config['schema_version'] == 1)
    require(matches(r'[0-9]{12}', config['account_id']) and matches(r'[a-z]{2}-[a-z-]+-[0-9]+', config['region']))
    region, account = config['region'], config['account_id']
    prefix = re.escape(f'{region}:{account}:')
    secret = lambda value: matches(r'arn:aws:secretsmanager:' + prefix + r'secret:[A-Za-z0-9/_+=.@!-]+-[A-Za-z0-9]{6}', value)
    kms = lambda value: matches(r'arn:aws:kms:' + prefix + r'key/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', value)
    source, target, network = config['source'], config['target'], config['network']
    fields(source, 'database_identifier upload_volume_id host_instance_id admin_secret_arn application_secret_arn app_key_sha256 database_storage_kms_key_arn')
    require(identifier(source['database_identifier']) and matches(r'vol-(?:[a-f0-9]{8}|[a-f0-9]{17})', source['upload_volume_id'])
            and matches(r'i-(?:[a-f0-9]{8}|[a-f0-9]{17})', source['host_instance_id']))
    require(secret(source['admin_secret_arn']) and secret(source['application_secret_arn'])
            and source['admin_secret_arn'] != source['application_secret_arn'] and matches(r'[a-f0-9]{64}', source['app_key_sha256']) and kms(source['database_storage_kms_key_arn']))
    fields(target, 'database_identifier upload_volume_label host_label application_secret_arn administrator_secret_kms_key_arn upload_kms_key_arn ca_cert_identifier parameter_group_name force_ssl tls_mode ca_file')
    old_identifiers = {source[key] for key in ('database_identifier', 'upload_volume_id', 'host_instance_id')}
    new_identifiers = [target[key] for key in ('database_identifier', 'upload_volume_label', 'host_label')]
    require(all(identifier(value) and value not in old_identifiers for value in new_identifiers) and len(set(new_identifiers)) == 3)
    require(secret(target['application_secret_arn']) and target['application_secret_arn'] not in (source['application_secret_arn'], source['admin_secret_arn'])
            and kms(target['administrator_secret_kms_key_arn']) and kms(target['upload_kms_key_arn']))
    require(target['force_ssl'] is True and target['tls_mode'] == 'verify-full' and matches(r'rds-ca-[a-z0-9-]+', target['ca_cert_identifier'])
            and identifier(target['parameter_group_name']) and isinstance(target['ca_file'], str) and Path(target['ca_file']).is_absolute())
    fields(network, 'vpc_id private_subnets database_subnet_group_name database_subnet_group_ids database_security_group_id database_ingress_source_security_group_id database_ingress_port database_public_ingress host_security_group_id host_subnet_id')
    require(matches(r'vpc-(?:[a-f0-9]{8}|[a-f0-9]{17})', network['vpc_id']) and identifier(network['database_subnet_group_name']))
    subnets = network['private_subnets']
    require(isinstance(subnets, list) and len(subnets) >= 2)
    for subnet in subnets:
        fields(subnet, 'id availability_zone public vpc_id')
        require(matches(r'subnet-(?:[a-f0-9]{8}|[a-f0-9]{17})', subnet['id']) and subnet['public'] is False
                and subnet['vpc_id'] == network['vpc_id'] and matches(re.escape(region) + r'[a-z]', subnet['availability_zone']))
    ids = {subnet['id'] for subnet in subnets}
    require(len(ids) == len(subnets) and len({subnet['availability_zone'] for subnet in subnets}) >= 2
            and isinstance(network['database_subnet_group_ids'], list) and set(network['database_subnet_group_ids']) == ids
            and len(network['database_subnet_group_ids']) == len(ids) and network['host_subnet_id'] in ids)
    require(all(matches(r'sg-(?:[a-f0-9]{8}|[a-f0-9]{17})', network[key]) for key in ('database_security_group_id', 'host_security_group_id'))
            and network['database_security_group_id'] != network['host_security_group_id']
            and network['database_ingress_source_security_group_id'] == network['host_security_group_id']
            and type(network['database_ingress_port']) is int and network['database_ingress_port'] == 5432 and network['database_public_ingress'] is False)
    restore, uploads = config['database_restore'], config['upload_restore']
    require(isinstance(restore, dict) and restore.get('source_identifier') == source['database_identifier'])
    if restore.get('method') == 'snapshot':
        fields(restore, 'method source_identifier snapshot_arn')
        require(matches(r'arn:aws:rds:' + prefix + r'snapshot:(?:rds:)?[a-z][a-z0-9-]{0,254}', restore['snapshot_arn']))
    else:
        fields(restore, 'method source_identifier restore_time earliest_restorable_time latest_restorable_time')
        require(restore['method'] == 'pitr' and utc(restore['earliest_restorable_time']) <= utc(restore['restore_time']) <= utc(restore['latest_restorable_time']))
    fields(uploads, 'snapshot_id source_volume_id availability_zone')
    require(matches(r'snap-(?:[a-f0-9]{8}|[a-f0-9]{17})', uploads['snapshot_id']) and uploads['source_volume_id'] == source['upload_volume_id'])
    host_subnet = next(subnet for subnet in subnets if subnet['id'] == network['host_subnet_id'])
    require(uploads['availability_zone'] == host_subnet['availability_zone'])
    metadata_hash = hashlib.sha256(json.dumps(config, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    return dict(schema_version=1, mode='offline-plan-only', metadata_sha256=metadata_hash,
                account_id=account, region=region, source=dict(source),
                database=dict(operation='restore-to-new-private-instance', target_identifier=target['database_identifier'], restore=dict(restore),
                              publicly_accessible=False, subnet_group_name=network['database_subnet_group_name'], security_group_ids=[network['database_security_group_id']],
                              parameter_group_name=target['parameter_group_name'], force_ssl=True, tls_mode='verify-full', ca_cert_identifier=target['ca_cert_identifier'],
                              ca_file=target['ca_file'], manage_master_user_password=True, administrator_secret_kms_key_arn=target['administrator_secret_kms_key_arn'],
                              storage_encrypted=True, storage_kms_key_arn=source['database_storage_kms_key_arn'],
                              post_restore_checks=['must-differ-from-source-managed-admin-secret', 'verify-private-endpoint-and-restored-non-admin-application-role']),
                uploads=dict(operation='create-new-encrypted-volume-from-snapshot', snapshot_id=uploads['snapshot_id'], label=target['upload_volume_label'],
                             availability_zone=uploads['availability_zone'], kms_key_arn=target['upload_kms_key_arn'], initialize_or_format=False,
                             post_restore_checks=['verify-filesystem-before-mount', 'verify-upload-hashes-and-owner-33']),
                host=dict(operation='create-new-private-recovery-host', label=target['host_label'], subnet_id=network['host_subnet_id'],
                          security_group_id=network['host_security_group_id'], associate_public_ip_address=False, traffic_cutover=False),
                application_key=dict(copy_only_field='APP_KEY', source_application_secret_arn=source['application_secret_arn'],
                                     target_application_secret_arn=target['application_secret_arn'], expected_sha256=source['app_key_sha256'],
                                     database_credentials='new-target-credentials; never-copy-source-admin-or-DB-password'),
                required_operator_checks=['verify-actual-snapshot-source-and-PITR-window', 'verify-VPC-subnet-group-SG-TLS-and-KMS-bindings',
                                          'approve-private-host-egress-SSM-registry-and-secret-access', 'restore-DB-and-uploads-to-a-consistent-approved-recovery-point',
                                          'preserve-source-and-APP_KEY; validate-data-receipts-sessions-uploads', 'measure-actual-RPO-RTO-before-any-approved-cutover'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, required=True, help='Approved public recovery metadata; no credential values')
    args = parser.parse_args()
    try:
        print(json.dumps(plan_recovery(json.loads(args.config.read_text())), indent=2, sort_keys=True))
    except (ValueError, TypeError, KeyError, OSError, StopIteration):
        print('Recovery plan refused unsafe or incomplete metadata; no HTTP/AWS operation performed', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
