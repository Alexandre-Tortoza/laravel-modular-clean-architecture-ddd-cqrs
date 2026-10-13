"""Offline guardrails for reviewed Terraform mock plans and Compose config."""

import argparse
import json
import pathlib
import re
import sys


SECRET_NAMES = re.compile(r"(?:password|secret_string|secret_key|access_key|app_key|auth_token)$", re.I)
TOKENS = re.compile(r'#[^\n]*|//[^\n]*|/\*.*?\*/|"(?:\\.|[^"\\])*"|[A-Za-z_][A-Za-z_0-9]*|[^\s]', re.S)
GLOBAL_ACTIONS = {'ecr:GetAuthorizationToken', 'ssm:UpdateInstanceInformation',
                  'ec2:DescribeVolumes', 'ec2:DescribeSnapshots',
                  'ssmmessages:CreateControlChannel', 'ssmmessages:CreateDataChannel',
                  'ssmmessages:OpenControlChannel', 'ssmmessages:OpenDataChannel'}


def validate_iam(policy):
    errors = []
    for statement in policy.get('Statement', []):
        if statement.get('Effect') != 'Allow':
            continue
        actions, resources = statement.get('Action', []), statement.get('Resource', [])
        actions = [actions] if isinstance(actions, str) else actions
        resources = [resources] if isinstance(resources, str) else resources
        conditions = statement.get('Condition', {}).get('StringEquals', {})
        metric_namespace = conditions.get('cloudwatch:namespace')
        metric_region = conditions.get('aws:RequestedRegion')
        scoped_metric = (
            actions == ['cloudwatch:PutMetricData'] and resources == ['*'] and
            isinstance(metric_namespace, str) and re.fullmatch(r'Template/[a-z][a-z0-9-]{0,31}/Runtime', metric_namespace) and
            isinstance(metric_region, str) and re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)+-[0-9]+', metric_region)
        )
        if statement.get('NotResource') or not resources:
            errors.append('Allow IAM policy must name resources')
        for action in actions:
            if ('*' in action or '?' in action) or (any(resource == '*' for resource in resources) and action not in GLOBAL_ACTIONS and not scoped_metric):
                errors.append('Excessive wildcard IAM permission is forbidden')
            if action.startswith('secretsmanager:') or action == 'kms:Decrypt':
                if any('*' in resource or '?' in resource for resource in resources):
                    errors.append('Runtime secret access requires exact resource ARNs')
    return errors


def blocks(source):
    """Read block boundaries without counting braces inside strings/comments."""
    tokens = [token for token in TOKENS.findall(source) if not token.startswith(("#", "//", "/*"))]
    for index, token in enumerate(tokens):
        if token not in {"run", "resource", "provider", "mock_provider"}:
            continue
        cursor = index + 1
        labels = []
        while cursor < len(tokens) and tokens[cursor].startswith('"'):
            labels.append(json.loads(tokens[cursor]))
            cursor += 1
        if cursor >= len(tokens) or tokens[cursor] != "{":
            continue
        depth, end = 1, cursor + 1
        while end < len(tokens) and depth:
            depth += (tokens[end] == "{") - (tokens[end] == "}")
            end += 1
        if depth:
            raise ValueError("Unbalanced infrastructure block")
        yield token, labels, tokens[cursor + 1:end - 1]


def validate_plan(plan):
    errors = []
    for resource in plan.get("resource_changes", []):
        change = resource.get("change", {})
        values = change.get("after")
        if values is None:
            continue
        unknown = change.get("after_unknown", {})
        resource_type = resource["type"]
        address = resource["address"]
        if resource_type in {'aws_iam_role_policy', 'aws_iam_policy'} and isinstance(values.get('policy'), str):
            errors.extend(f'{address}: {error}' for error in validate_iam(json.loads(values['policy'])))
        if resource_type.startswith("aws_ecs_") or resource_type in {"aws_lb", "aws_nat_gateway", "aws_security_group_rule"}:
            errors.append(f"{address}: resource outside the reviewed EC2 topology")
        if resource_type == "aws_db_instance" and values.get("publicly_accessible") is not False:
            errors.append(f"{address}: RDS must explicitly remain private")
        if resource_type in {"aws_security_group", "aws_default_security_group"} and any(values.get(direction) for direction in ["ingress", "egress"]):
            errors.append(f"{address}: inline SG rules are forbidden; use dedicated rule resources")
        if resource_type in {"aws_vpc_security_group_ingress_rule", "aws_vpc_security_group_egress_rule"}:
            public_source = any(values.get(field) or unknown.get(field) for field in ["cidr_ipv4", "cidr_ipv6", "prefix_list_id"])
            private_source = values.get("referenced_security_group_id") or unknown.get("referenced_security_group_id")
            ports = (values.get("from_port"), values.get("to_port"))
            if values.get("ip_protocol") != "tcp" or ports[0] != ports[1]:
                errors.append(f"{address}: only explicit single TCP ports are allowed")
            elif public_source and ports[0] not in {80, 443}:
                errors.append(f"{address}: CIDR/prefix-list access is limited to HTTP/HTTPS")
            elif not public_source and (not private_source or ports[0] != 5432):
                errors.append(f"{address}: private PostgreSQL must reference a security group")
        for name, value in values.items():
            if SECRET_NAMES.search(name) and isinstance(value, str) and value:
                errors.append(f"{address}: literal sensitive attribute {name} is forbidden")
    return errors


def validate_sources(directory):
    errors = []
    for path in sorted(pathlib.Path(directory).rglob("*")):
        tfvars_json = path.name.endswith(('.tfvars.json', '.tfvars.json.example'))
        if ".terraform" in path.parts or not path.is_file() or (path.suffix not in {".tf", ".hcl", ".sh", '.tfvars'} and not tfvars_json):
            continue
        source = path.read_text()
        label = str(path.relative_to(directory))
        if tfvars_json:
            def inspect(value):
                if isinstance(value, dict):
                    for name, child in value.items():
                        if SECRET_NAMES.search(name) and isinstance(child, str) and child:
                            errors.append(f'{label}: literal sensitive attribute {name} is forbidden')
                        inspect(child)
                elif isinstance(value, list):
                    for child in value:
                        inspect(child)
            try:
                inspect(json.loads(source))
            except ValueError:
                errors.append(f'{label}: invalid tfvars JSON')
        for match in re.finditer(r'\b([A-Za-z_][A-Za-z_0-9]*)\s*=\s*"((?:\\.|[^"\\])*)"', source):
            if SECRET_NAMES.search(match[1]) and match[2] and "${" not in match[2]:
                errors.append(f"{label}: literal sensitive attribute {match[1]} is forbidden")
        if re.search(r"\b(?:AKIA|ASIA)[A-Z0-9]{16}\b|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----", source):
            errors.append(f"{label}: credential-shaped literal is forbidden")
        if path.name.endswith(".tftest.hcl"):
            parsed = list(blocks(source))
            if not any(kind == "mock_provider" and labels == ["aws"] for kind, labels, _ in parsed):
                errors.append(f"{label}: AWS tests require the mocked provider")
            if any(kind == "provider" and labels == ["aws"] for kind, labels, _ in parsed):
                errors.append(f"{label}: real AWS provider is forbidden in offline tests")
            for kind, labels, tokens in parsed:
                if kind == "run" and not any(tokens[i:i + 3] == ["command", "=", "plan"] for i in range(len(tokens))):
                    errors.append(f"{label}: every test run must explicitly use command = plan")
        elif path.suffix == ".tf":
            for kind, labels, tokens in blocks(source):
                if kind == "resource" and labels and labels[0] == "aws_security_group" and any(token in {"ingress", "egress"} for token in tokens):
                    errors.append(f"{label}: inline security group rule configuration is forbidden")
    return errors


def validate_compose(document, development=False, redis=False):
    errors = []
    services = document.get("services", {})
    if ("redis" in services) != redis:
        errors.append("Redis must be absent by default and present only with the explicit profile")
    if redis and services.get("redis", {}).get("profiles") != ["redis"]:
        errors.append("Redis must retain its explicit optional profile")
    for name, service in services.items():
        private = name in {"php", "pgsql", "redis", "worker", "scheduler", "migration"}
        ports = service.get("ports", [])
        if private and ports and not (development and name in {"pgsql", "redis"}):
            errors.append(f"{name}: production internal service must not publish a port")
        if development and name in {"pgsql", "redis"} and any(port.get("host_ip") != "127.0.0.1" for port in ports):
            errors.append(f"{name}: development persistence ports must bind loopback only")
        if not development and (service.get("privileged") or service.get("network_mode") == "host"):
            errors.append(f"{name}: privileged or host-network runtime is forbidden")
    return errors


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["sources", "plan", "compose"])
    parser.add_argument("--directory", type=pathlib.Path, default=pathlib.Path("infra/aws"))
    parser.add_argument("--development", action="store_true")
    parser.add_argument("--redis", action="store_true")
    args = parser.parse_args()
    errors, plans, completed = [], 0, False
    try:
        if args.mode == "sources":
            if not args.directory.is_dir():
                raise ValueError("Infrastructure source directory is missing")
            errors = validate_sources(args.directory)
        elif args.mode == "compose":
            errors = validate_compose(json.load(sys.stdin), args.development, args.redis)
        else:
            for line in sys.stdin:
                event = json.loads(line)
                if "test_plan" in event:
                    plans += 1
                    errors.extend(validate_plan(event["test_plan"]))
                # expect_failures emits error diagnostics even for a passing
                # rejection test. Use Terraform's final run/summary outcome.
                if event.get("type") == "test_run":
                    run = event.get("test_run", {})
                    if run.get("progress") == "complete" and run.get("status") != "pass":
                        errors.append("Terraform test run did not pass")
                if event.get("type") == "test_summary":
                    summary = event.get("test_summary", {})
                    completed = True
                    if summary.get("status") != "pass" or summary.get("failed", 0) or summary.get("errored", 0) or summary.get("skipped", 0):
                        errors.append("Terraform test contract failed")
            if not plans:
                errors.append("No Terraform mock plans were received")
            if not completed:
                errors.append("Terraform test did not report a complete summary")
    except (ValueError, OSError, KeyError, TypeError):
        errors.append("Invalid or missing infrastructure validation input")
    for error in errors:
        print(error, file=sys.stderr)
    if not errors:
        print(f"Offline infrastructure {args.mode} policy passed" + (f": {plans} mock plans" if plans else ""))
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
