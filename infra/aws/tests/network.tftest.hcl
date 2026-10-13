mock_provider "aws" {}

# Synthetic interface inputs are not capacity or region recommendations.
variables {
  aws_region  = "fixture-region"
  environment = "fixture"
  domain_name = "fixture.invalid"
  network = {
    vpc_cidr           = "10.123.0.0/16"
    public_subnet_cidr = "10.123.1.0/24"
    public_subnet_az   = "fixture-az-a"
    private_subnets = [
      { cidr = "10.123.2.0/24", availability_zone = "fixture-az-a" },
      { cidr = "10.123.3.0/24", availability_zone = "fixture-az-b" },
    ]
  }
  compute                = { instance_type = "fixture.instance", root_volume_gib = 1 }
  database               = { instance_class = "fixture.database", allocated_storage_gib = 1, backup_retention_days = 1 }
  recovery               = { rpo_minutes = 1, rto_minutes = 1, restore_test_interval_days = 1 }
  cost_review            = { owner = "fixture-owner", monthly_budget = null }
  web_ingress_ipv4_cidrs = ["203.0.113.0/24"]
}

run "restricted_reference_network" {
  command = plan
  assert {
    condition = (
      aws_vpc.reference.enable_dns_support && aws_vpc.reference.enable_dns_hostnames &&
      !aws_subnet.web.map_public_ip_on_launch &&
      alltrue([for subnet in aws_subnet.database : !subnet.map_public_ip_on_launch]) &&
      length(aws_subnet.database) == 2 &&
      length(aws_vpc_security_group_ingress_rule.https) == 1 &&
      length(aws_vpc_security_group_ingress_rule.http) == 0 &&
      alltrue([for rule in aws_vpc_security_group_ingress_rule.https : rule.ip_protocol == "tcp" && rule.from_port == 443 && rule.to_port == 443 && rule.cidr_ipv4 == "203.0.113.0/24"])
    )
    error_message = "Only explicit HTTPS clients may enter the web host; address assignment is reserved to EC2 and database subnets remain private."
  }
  assert {
    condition = (
      aws_vpc_security_group_ingress_rule.postgresql.from_port == 5432 &&
      aws_vpc_security_group_ingress_rule.postgresql.to_port == 5432 &&
      aws_vpc_security_group_ingress_rule.postgresql.ip_protocol == "tcp" &&
      aws_vpc_security_group_ingress_rule.postgresql.cidr_ipv4 == null &&
      aws_vpc_security_group_ingress_rule.postgresql.cidr_ipv6 == null &&
      length(aws_default_security_group.deny_default.ingress) == 0 && length(aws_default_security_group.deny_default.egress) == 0
    )
    error_message = "PostgreSQL must accept only the web SG, with no inline broad rule, public database CIDR or administrative ingress."
  }
  assert {
    condition = (
      aws_route.web_internet.destination_cidr_block == "0.0.0.0/0" &&
      length(aws_route_table.database.route) == 0 &&
      aws_vpc_security_group_egress_rule.postgresql.from_port == 5432 &&
      alltrue([for rule in aws_vpc_security_group_egress_rule.updates : rule.ip_protocol == "tcp" && contains([80, 443], rule.from_port) && rule.to_port == rule.from_port])
    )
    error_message = "Only web receives internet routing; its explicit HTTP/HTTPS updates and private PostgreSQL egress must remain bounded."
  }
}

run "explicit_http_for_redirect_or_challenge" {
  command = plan
  variables { enable_http_ingress = true }
  assert {
    condition = length(aws_vpc_security_group_ingress_rule.http) == 1 && alltrue([
      for rule in aws_vpc_security_group_ingress_rule.http : rule.from_port == 80 && rule.to_port == 80 && rule.cidr_ipv4 == "203.0.113.0/24"
    ])
    error_message = "HTTP requires explicit opt-in and shares the selected client scope; its runtime must redirect or serve a certificate challenge."
  }
}

run "reject_unselected_web_clients" {
  command = plan
  variables { web_ingress_ipv4_cidrs = [] }
  expect_failures = [var.web_ingress_ipv4_cidrs]
}

run "reject_ipv6_in_ipv4_clients" {
  command = plan
  variables { web_ingress_ipv4_cidrs = ["::/0"] }
  expect_failures = [var.web_ingress_ipv4_cidrs]
}

run "reject_subnet_outside_vpc" {
  command = plan
  variables {
    network = {
      vpc_cidr = "10.123.0.0/16", public_subnet_cidr = "10.124.1.0/24", public_subnet_az = "fixture-az-a"
      private_subnets = [
        { cidr = "10.123.2.0/24", availability_zone = "fixture-az-a" },
        { cidr = "10.123.3.0/24", availability_zone = "fixture-az-b" },
      ]
    }
  }
  expect_failures = [var.network]
}

run "reject_overlapping_subnets" {
  command = plan
  variables {
    network = {
      vpc_cidr = "10.123.0.0/16", public_subnet_cidr = "10.123.1.0/24", public_subnet_az = "fixture-az-a"
      private_subnets = [
        { cidr = "10.123.1.0/25", availability_zone = "fixture-az-a" },
        { cidr = "10.123.3.0/24", availability_zone = "fixture-az-b" },
      ]
    }
  }
  expect_failures = [var.network]
}

# Resource IDs remain unknown in plan-only Terraform 1.10 mocks. Verify the
# source relationships statically instead of applying even mocked resources.
run "restricted_resource_relationships" {
  command = plan
  assert {
    condition = (
      can(regex("(?s)resource \\\"aws_vpc_security_group_ingress_rule\\\" \\\"postgresql\\\" \\{[^}]*security_group_id\\s*=\\s*aws_security_group.database.id[^}]*referenced_security_group_id\\s*=\\s*aws_security_group.web.id", file("${path.module}/network.tf"))) &&
      can(regex("(?s)resource \\\"aws_vpc_security_group_egress_rule\\\" \\\"postgresql\\\" \\{[^}]*security_group_id\\s*=\\s*aws_security_group.web.id[^}]*referenced_security_group_id\\s*=\\s*aws_security_group.database.id", file("${path.module}/network.tf"))) &&
      can(regex("(?s)resource \\\"aws_route\\\" \\\"web_internet\\\" \\{[^}]*gateway_id\\s*=\\s*aws_internet_gateway.public.id", file("${path.module}/network.tf")))
    )
    error_message = "Database ingress/egress must reference their dedicated SGs and web internet routing must reference the declared IGW."
  }
  assert {
    condition = alltrue([
      for tf in fileset(path.module, "*.tf") : length(regexall("resource\\s+\\\"aws_(ecs_[^\\\"]*|lb|lb_[^\\\"]*|nat_gateway|elasticache_[^\\\"]*)\\\"", file("${path.module}/${tf}"))) == 0
    ])
    error_message = "The reference network must not require ECS/Fargate, ALB, NAT or optional Redis resources."
  }
}
