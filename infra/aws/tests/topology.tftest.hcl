# Synthetic values exercise the interface offline; they are not deployment recommendations.
mock_provider "aws" {}

variables {
  aws_region             = "fixture-region"
  environment            = "fixture"
  domain_name            = "fixture.invalid"
  web_ingress_ipv4_cidrs = ["203.0.113.0/24"]
  network = {
    vpc_cidr           = "10.123.0.0/16"
    public_subnet_cidr = "10.123.1.0/24"
    public_subnet_az   = "fixture-az-a"
    private_subnets = [
      { cidr = "10.123.2.0/24", availability_zone = "fixture-az-a" },
      { cidr = "10.123.3.0/24", availability_zone = "fixture-az-b" },
    ]
  }
  compute = { instance_type = "fixture.instance", root_volume_gib = 1 }
  database = {
    instance_class        = "fixture.database"
    allocated_storage_gib = 1
    backup_retention_days = 1
  }
  recovery    = { rpo_minutes = 1, rto_minutes = 1, restore_test_interval_days = 1 }
  cost_review = { owner = "fixture-owner", monthly_budget = null }
}

run "reference_topology" {
  command = plan

  assert {
    condition = (
      output.reference_topology.compute.host_count == 1 &&
      output.reference_topology.compute.orchestrator == "docker-compose" &&
      output.reference_topology.compute.roles == tolist(["web", "worker", "scheduler"]) &&
      !output.reference_topology.database.publicly_accessible &&
      !output.reference_topology.database.multi_az &&
      !output.reference_topology.network.nat_gateway_required &&
      !output.reference_topology.network.load_balancer_required
    )
    error_message = "The reference must remain one Compose EC2 host with a private Single-AZ RDS and no mandatory NAT or ALB."
  }

  assert {
    condition = (
      output.reference_topology.region == var.aws_region &&
      output.reference_topology.compute.capacity == var.compute &&
      output.reference_topology.database.capacity == var.database &&
      output.reference_topology.recovery == var.recovery &&
      output.reference_topology.cost_review.monthly_budget == null
    )
    error_message = "The interface must retain explicit operator choices without inventing capacity, region, recovery targets or a budget."
  }
}

run "reject_blank_region" {
  command = plan
  variables { aws_region = " " }
  expect_failures = [var.aws_region]
}

run "reject_single_private_az" {
  command = plan
  variables {
    network = {
      vpc_cidr = "10.123.0.0/16", public_subnet_cidr = "10.123.1.0/24", public_subnet_az = "fixture-az-a"
      private_subnets = [
        { cidr = "10.123.2.0/24", availability_zone = "fixture-az-a" },
        { cidr = "10.123.3.0/24", availability_zone = "fixture-az-a" },
      ]
    }
  }
  expect_failures = [var.network]
}

run "reject_fractional_compute_capacity" {
  command = plan
  variables { compute = { instance_type = "fixture.instance", root_volume_gib = 0.5 } }
  expect_failures = [var.compute]
}

run "reject_blank_database_class" {
  command = plan
  variables { database = { instance_class = " ", allocated_storage_gib = 1, backup_retention_days = 1 } }
  expect_failures = [var.database]
}

run "reject_disabled_backups" {
  command = plan
  variables { database = { instance_class = "fixture.database", allocated_storage_gib = 1, backup_retention_days = 0 } }
  expect_failures = [var.database]
}

run "reject_unknown_recovery_target" {
  command = plan
  variables { recovery = { rpo_minutes = 0, rto_minutes = 1, restore_test_interval_days = 1 } }
  expect_failures = [var.recovery]
}

run "reject_budget_without_currency" {
  command = plan
  variables { cost_review = { owner = "fixture-owner", monthly_budget = { amount = 1, currency = " " } } }
  expect_failures = [var.cost_review]
}

run "accept_explicit_budget_and_maximum_retention" {
  command = plan
  variables {
    database    = { instance_class = "fixture.database", allocated_storage_gib = 1, backup_retention_days = 35 }
    cost_review = { owner = "fixture-owner", monthly_budget = { amount = 1.5, currency = "FIXTURE" } }
  }
  assert {
    condition     = output.reference_topology.cost_review.monthly_budget.amount == 1.5 && output.reference_topology.database.capacity.backup_retention_days == 35
    error_message = "An explicitly supplied budget and the maximum retention must be retained without substitutes."
  }
}

run "reject_retention_above_maximum" {
  command = plan
  variables { database = { instance_class = "fixture.database", allocated_storage_gib = 1, backup_retention_days = 36 } }
  expect_failures = [var.database]
}

run "reject_url_instead_of_hostname" {
  command = plan
  variables { domain_name = "https://fixture.invalid" }
  expect_failures = [var.domain_name]
}

run "reject_unowned_cost_review" {
  command = plan
  variables { cost_review = { owner = " ", monthly_budget = null } }
  expect_failures = [var.cost_review]
}
