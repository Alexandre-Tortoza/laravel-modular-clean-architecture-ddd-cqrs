import copy
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

from infrastructure_policy import validate_compose, validate_plan, validate_sources


def planned(resource_type, values, unknown=None):
    return {"resource_changes": [{"address": f"{resource_type}.fixture", "type": resource_type,
                                  "change": {"actions": ["create"], "after": values, "after_unknown": unknown or {}}}]}


class InfrastructurePolicyTest(unittest.TestCase):
    def test_health_metric_global_permission_requires_one_exact_namespace_and_region(self):
        action = {'Effect': 'Allow', 'Action': ['cloudwatch:PutMetricData'], 'Resource': ['*'],
                  'Condition': {'StringEquals': {'cloudwatch:namespace': 'Template/fixture/Runtime', 'aws:RequestedRegion': 'us-east-1'}}}
        self.assertEqual(validate_plan(planned('aws_iam_role_policy', {'policy': json.dumps({'Statement': [action]})})), [])
        for changed in [dict(action, Condition={}),
                        dict(action, Condition={'StringEquals': {'cloudwatch:namespace': '*', 'aws:RequestedRegion': 'us-east-1'}}),
                        dict(action, Action=['cloudwatch:PutMetricData', 'iam:CreateRole'])]:
            self.assertTrue(validate_plan(planned('aws_iam_role_policy', {'policy': json.dumps({'Statement': [changed]})})))

    def test_json_and_hcl_tfvars_cannot_hide_runtime_secrets(self):
        for filename, source in [('terraform.tfvars.json', '{"nested":{"DB_PASSWORD":"canary-secret"}}'),
                                 ('fixture.tfvars', 'APP_KEY = "canary-secret"'),
                                 ('fixture.tfvars.json.example', '{"APP_KEY":"canary-secret"}')]:
            with tempfile.TemporaryDirectory() as directory:
                (pathlib.Path(directory) / filename).write_text(source)
                errors = validate_sources(directory)
                self.assertTrue(errors)
                self.assertNotIn('canary-secret', str(errors))

    def test_get_command_invocation_requires_one_exact_requested_region(self):
        allowed = {'Statement': [{'Effect': 'Allow', 'Action': ['ssm:GetCommandInvocation'], 'Resource': ['*'],
                                 'Condition': {'StringEquals': {'aws:RequestedRegion': 'fixture-region'}}}]}
        self.assertEqual(validate_plan(planned('aws_iam_role_policy', {'policy': json.dumps(allowed)})), [])
        for region in [None, '', '*', 'us-*-1', 'us-?-1', ['fixture-region'], '${var.region}']:
            rejected = {'Statement': [{'Effect': 'Allow', 'Action': ['ssm:GetCommandInvocation'], 'Resource': ['*'],
                                      'Condition': {'StringEquals': {'aws:RequestedRegion': region}}}]}
            with self.subTest(region=region):
                self.assertTrue(validate_plan(planned('aws_iam_role_policy', {'policy': json.dumps(rejected)})))
        for condition in [{}, {'StringLike': {'aws:RequestedRegion': 'fixture-region'}}]:
            rejected = {'Statement': [{'Effect': 'Allow', 'Action': ['ssm:GetCommandInvocation'], 'Resource': ['*'], 'Condition': condition}]}
            self.assertTrue(validate_plan(planned('aws_iam_role_policy', {'policy': json.dumps(rejected)})))

    def test_regional_invocation_exception_cannot_grant_other_actions(self):
        for actions in [['ssm:GetCommandInvocation', 'ssm:SendCommand'], ['ssm:GetCommandInvocation', 'ssm:ListCommands'], ['ssm:*']]:
            rejected = {'Statement': [{'Effect': 'Allow', 'Action': actions, 'Resource': ['*'],
                                      'Condition': {'StringEquals': {'aws:RequestedRegion': 'fixture-region'}}}]}
            with self.subTest(actions=actions):
                self.assertTrue(validate_plan(planned('aws_iam_role_policy', {'policy': json.dumps(rejected)})))

    def test_iam_secret_wildcards_and_administration_are_rejected(self):
        for action, resource in [('secretsmanager:GetSecretValue', '*'), ('kms:Decrypt', '*'),
                                 ('secretsmanager:GetSecretValue', 'arn:aws:secretsmanager:us-east-1:123456789012:secret:*'),
                                 ('iam:*', '*')]:
            policy = {'Statement': [{'Effect': 'Allow', 'Action': [action], 'Resource': [resource]}]}
            self.assertTrue(validate_plan(planned('aws_iam_role_policy', {'policy': json.dumps(policy)})))

    def test_required_global_channel_and_registry_token_actions_remain_allowed(self):
        policy = {'Statement': [{'Effect': 'Allow', 'Action': ['ecr:GetAuthorizationToken', 'ssm:UpdateInstanceInformation'], 'Resource': ['*']},
                                {'Effect': 'Deny', 'Action': ['secretsmanager:GetSecretValue'], 'NotResource': ['arn:fixture:exact']} ]}
        self.assertEqual(validate_plan(planned('aws_iam_role_policy', {'policy': json.dumps(policy)})), [])

    def test_explicit_https_ingress_is_allowed(self):
        self.assertEqual(validate_plan(planned("aws_vpc_security_group_ingress_rule", {
            "cidr_ipv4": "0.0.0.0/0", "ip_protocol": "tcp", "from_port": 443, "to_port": 443})), [])

    def test_public_administrative_database_and_fpm_ports_are_rejected(self):
        for port in [22, 5432, 6379, 9000]:
            with self.subTest(port=port):
                self.assertTrue(validate_plan(planned("aws_vpc_security_group_ingress_rule", {
                    "cidr_ipv4": "203.0.113.0/24", "ip_protocol": "tcp", "from_port": port, "to_port": port})))

    def test_all_protocols_and_port_ranges_are_rejected(self):
        for values in [{"cidr_ipv4": "0.0.0.0/0", "ip_protocol": "-1"},
                       {"cidr_ipv4": "0.0.0.0/0", "ip_protocol": "tcp", "from_port": 80, "to_port": 443}]:
            self.assertTrue(validate_plan(planned("aws_vpc_security_group_ingress_rule", values)))

    def test_private_postgresql_uses_a_security_group_reference(self):
        values = {"ip_protocol": "tcp", "from_port": 5432, "to_port": 5432}
        self.assertEqual(validate_plan(planned("aws_vpc_security_group_ingress_rule", values, {"referenced_security_group_id": True})), [])
        self.assertTrue(validate_plan(planned("aws_vpc_security_group_ingress_rule", values)))

    def test_unrestricted_egress_and_inline_rules_are_rejected(self):
        self.assertTrue(validate_plan(planned("aws_vpc_security_group_egress_rule", {"cidr_ipv4": "0.0.0.0/0", "ip_protocol": "-1"})))
        self.assertTrue(validate_plan(planned("aws_security_group", {"ingress": [{"from_port": 22}]})))
        self.assertTrue(validate_plan(planned("aws_default_security_group", {"egress": [{"protocol": "-1"}]})))

    def test_public_rds_and_unapproved_topologies_are_rejected(self):
        self.assertTrue(validate_plan(planned("aws_db_instance", {"publicly_accessible": True})))
        for resource_type in ["aws_ecs_cluster", "aws_ecs_service", "aws_lb", "aws_nat_gateway"]:
            self.assertTrue(validate_plan(planned(resource_type, {})))

    def test_secret_diagnostic_is_redacted(self):
        canary = "do-not-print-secret-canary"
        result = validate_plan(planned("aws_db_instance", {"password": canary, "publicly_accessible": False}))
        self.assertTrue(result)
        self.assertNotIn(canary, str(result))

    def test_literal_secrets_and_unmocked_or_apply_tests_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            (root / "unsafe.tf").write_text('password = "do-not-print-secret-canary"\n')
            (root / "unsafe.tftest.hcl").write_text('run "unsafe" { command = apply }\n')
            result = validate_sources(root)
            self.assertTrue(any("literal sensitive attribute" in message for message in result))
            self.assertTrue(any("mocked provider" in message for message in result))
            self.assertTrue(any("command = plan" in message for message in result))
            self.assertNotIn("do-not-print-secret-canary", str(result))

    def test_sources_allow_secret_references_and_plan_only_mock_tests(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            (root / "safe.tf").write_text('password = var.password\n')
            (root / "safe.tftest.hcl").write_text('mock_provider "aws" {}\nrun "safe" { command = plan }\n')
            self.assertEqual(validate_sources(root), [])

    def test_default_apply_and_real_provider_overrides_are_rejected(self):
        for source in ['mock_provider "aws" {}\nrun "unsafe" {}',
                       'mock_provider "aws" {}\nprovider "aws" {}\nrun "unsafe" { command = plan }']:
            with tempfile.TemporaryDirectory() as directory:
                (pathlib.Path(directory) / "unsafe.tftest.hcl").write_text(source)
                self.assertTrue(validate_sources(pathlib.Path(directory)))

    def test_compose_private_services_have_no_published_ports(self):
        document = {"services": {"php": {"image": "fixture"}, "pgsql": {"ports": [{"target": 5432}]}}}
        self.assertTrue(validate_compose(document))

    def test_development_redis_profile_off_and_on(self):
        document = {"services": {"laravel.test": {}, "pgsql": {"ports": [{"host_ip": "127.0.0.1", "target": 5432}]}}}
        self.assertEqual(validate_compose(document, development=True), [])
        enabled = copy.deepcopy(document)
        enabled["services"]["redis"] = {"profiles": ["redis"], "ports": [{"host_ip": "127.0.0.1", "target": 6379}]}
        self.assertEqual(validate_compose(enabled, development=True, redis=True), [])
        self.assertTrue(validate_compose(enabled, development=True))
        enabled["services"]["redis"]["ports"][0]["host_ip"] = "0.0.0.0"
        self.assertTrue(validate_compose(enabled, development=True, redis=True))

    def test_plan_stream_requires_actual_plans_and_redacts_diagnostics(self):
        script = pathlib.Path(__file__).with_name("infrastructure_policy.py")
        for source in ["", json.dumps({"type": "diagnostic", "diagnostic": {"severity": "error", "summary": "do-not-print-secret-canary"}}) + "\n"]:
            result = subprocess.run([sys.executable, str(script), "plan"], input=source, text=True, capture_output=True)
            self.assertEqual(result.returncode, 1)
            self.assertNotIn("do-not-print-secret-canary", result.stdout + result.stderr)
        source = json.dumps({"test_plan": planned("aws_vpc_security_group_ingress_rule", {
            "cidr_ipv4": "0.0.0.0/0", "ip_protocol": "tcp", "from_port": 443, "to_port": 443})}) + "\n"
        source += json.dumps({"type": "diagnostic", "diagnostic": {"severity": "error", "summary": "expected variable rejection"}}) + "\n"
        source += json.dumps({"type": "test_summary", "test_summary": {"status": "pass", "passed": 1, "failed": 0, "errored": 0, "skipped": 0}}) + "\n"
        result = subprocess.run([sys.executable, str(script), "plan"], input=source, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
