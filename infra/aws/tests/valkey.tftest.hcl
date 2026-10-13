mock_provider "aws" {
  mock_data "aws_caller_identity" { defaults = { account_id = "111122223333" } }
  mock_data "aws_ami" { defaults = { architecture = "x86_64", name = "ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-fixture", root_device_type = "ebs", virtualization_type = "hvm" } }
  mock_data "aws_ec2_instance_type" { defaults = { hypervisor = "nitro", supported_architectures = ["x86_64"] } }

  mock_data "aws_rds_engine_version" {
    defaults = { version_actual = "16.15" }
  }
  mock_data "aws_rds_orderable_db_instance" {
    defaults = { min_storage_size = 20, max_storage_size = 65536, availability_zones = ["fixture-az-a", "fixture-az-b"] }
  }
  mock_data "aws_rds_certificate" {
    defaults = { certificate_type = "CA" }
  }
}


variables {
  aws_region             = "us-east-1"
  environment            = "fixture"
  domain_name            = "fixture.invalid"
  web_ingress_ipv4_cidrs = ["203.0.113.0/24"]
  network = {
    vpc_cidr = "10.123.0.0/16", public_subnet_cidr = "10.123.1.0/24", public_subnet_az = "fixture-az-a"
    private_subnets = [
      { cidr = "10.123.2.0/24", availability_zone = "fixture-az-a" },
      { cidr = "10.123.3.0/24", availability_zone = "fixture-az-b" },
    ]
  }
  compute     = { instance_type = "fixture.instance", root_volume_gib = 1 }
  database    = { instance_class = "fixture.database", allocated_storage_gib = 20, backup_retention_days = 7 }
  recovery    = { rpo_minutes = 1, rto_minutes = 1, restore_test_interval_days = 1 }
  cost_review = { owner = "fixture-owner", monthly_budget = null }
  rds = {
    identifier                 = "fixture-database", engine_version = "16.15", availability_zone = "fixture-az-a"
    database_name              = "fixture", master_username = "fixture_admin", application_username = "fixture_app"
    storage_kms_key_arn        = "arn:aws:kms:us-east-1:111122223333:key/00000000-0000-0000-0000-000000000001"
    admin_secret_kms_key_arn   = "arn:aws:kms:us-east-1:111122223333:key/00000000-0000-0000-0000-000000000002"
    ca_cert_identifier         = "rds-ca-rsa2048-g1", deletion_protection = true
    final_snapshot_identifier  = "fixture-final-snapshot", max_allocated_storage_gib = 100
    auto_minor_version_upgrade = true, backup_window = "02:00-02:30", maintenance_window = "sun:03:00-sun:03:30"
  }

  ec2_host = {
    ami_id          = "ami-0123456789abcdef0", ami_owner_account_id = "099720109477", architecture = "x86_64", upload_volume_gib = 1
    docker_versions = { engine = "5:29.8.2-1~ubuntu.24.04~noble", containerd = "2.2.4-1~ubuntu.24.04~noble", buildx = "0.32.1-1~ubuntu.24.04~noble", compose = "5.1.0-1~ubuntu.24.04~noble" }
  }
  runtime_secrets = {
    application_secret_arn = "arn:aws:secretsmanager:us-east-1:111122223333:secret:fixture-app-Ab1234"
    kms_key_arn            = "arn:aws:kms:us-east-1:111122223333:key/00000000-0000-0000-0000-000000000003"
    database_username      = "fixture_app"
    rotation_owner         = "fixture-operator"
  }
  valkey = {
    identifier              = "fixture-valkey", node_type = "cache.t4g.micro", engine_version = "8.2"
    parameter_group_family  = "valkey8", availability_zone = "fixture-az-a"
    user_group_id           = "fixture-approved-valkey", user_id = "fixture-app", application_username = "fixture_app", authentication_owner = "fixture-owner", authentication_method = "password"
    snapshot_retention_days = 1, maintenance_window = "sun:04:00-sun:05:00"
  }
}

run "valkey_off_has_no_resources_or_endpoints" {
  command = plan
  variables { valkey = null }
  assert {
    condition     = length(aws_elasticache_replication_group.application) == 0 && length(aws_elasticache_subnet_group.application) == 0 && length(aws_security_group.valkey) == 0 && output.valkey_connection == null
    error_message = "Default must not allocate cache capacity or expose an invalid endpoint."
  }
}

run "one_private_tls_primary_uses_preexisting_rbac" {
  command = plan
  assert {
    condition = (
      aws_elasticache_replication_group.application[0].engine == "valkey" &&
      aws_elasticache_replication_group.application[0].num_cache_clusters == 1 &&
      aws_elasticache_replication_group.application[0].cluster_mode == "disabled" &&
      !aws_elasticache_replication_group.application[0].automatic_failover_enabled &&
      !aws_elasticache_replication_group.application[0].multi_az_enabled &&
      aws_elasticache_replication_group.application[0].transit_encryption_enabled &&
      aws_elasticache_replication_group.application[0].transit_encryption_mode == "required" &&
      aws_elasticache_replication_group.application[0].at_rest_encryption_enabled &&
      aws_elasticache_replication_group.application[0].user_group_ids == toset([var.valkey.user_group_id]) &&
      aws_elasticache_replication_group.application[0].auth_token == null
    )
    error_message = "One authenticated Valkey primary only, TLS required; no password may enter Terraform."
  }
  assert {
    condition = (
      alltrue([for subnet in aws_subnet.database : !subnet.map_public_ip_on_launch]) &&
      aws_vpc_security_group_ingress_rule.valkey[0].from_port == 6379 &&
      aws_vpc_security_group_ingress_rule.valkey[0].cidr_ipv4 == null &&
      aws_vpc_security_group_ingress_rule.valkey[0].cidr_ipv6 == null &&
      aws_vpc_security_group_egress_rule.valkey[0].from_port == 6379 &&
      aws_vpc_security_group_egress_rule.valkey[0].cidr_ipv4 == null
    )
    error_message = "Valkey must use existing private subnets and allow only the EC2 security group."
  }
}

run "reject_default_unauthenticated_user" {
  command = plan
  variables { valkey = merge(var.valkey, { application_username = "default" }) }
  expect_failures = [var.valkey]
}
run "reject_cluster_parameter_group" {
  command = plan
  variables { valkey = merge(var.valkey, { parameter_group_family = "valkey8.cluster.on" }) }
  expect_failures = [var.valkey]
}
run "reject_missing_runtime_secret" {
  command = plan
  variables { runtime_secrets = null }
  expect_failures = [aws_elasticache_replication_group.application]
}
run "reject_public_or_unselected_az" {
  command = plan
  variables { valkey = merge(var.valkey, { availability_zone = "unselected-az" }) }
  expect_failures = [var.valkey]
}

run "reject_static_iam_token_contract" {
  command = plan
  variables { valkey = merge(var.valkey, { authentication_method = "iam" }) }
  expect_failures = [var.valkey]
}

# Native Terraform 1.10 plans keep resource IDs unknown: verify declared links
# statically using the same reference-network contract, without mocked apply.
run "restricted_valkey_resource_relationships" {
  command = plan
  assert {
    condition = (
      can(regex("(?s)resource \"aws_vpc_security_group_ingress_rule\" \"valkey\" \\{(?:\"[^\"]*\"|[^}\"])*security_group_id\\s*=\\s*aws_security_group.valkey\\[0\\].id[^}]*referenced_security_group_id\\s*=\\s*aws_security_group.web.id", file("${path.module}/valkey.tf"))) &&
      can(regex("(?s)resource \"aws_vpc_security_group_egress_rule\" \"valkey\" \\{(?:\"[^\"]*\"|[^}\"])*security_group_id\\s*=\\s*aws_security_group.web.id[^}]*referenced_security_group_id\\s*=\\s*aws_security_group.valkey\\[0\\].id", file("${path.module}/valkey.tf"))) &&
      can(regex("(?s)resource \"aws_elasticache_subnet_group\" \"application\" \\{(?:\"[^\"]*\"|[^}\"])*subnet_ids\\s*=\\s*\\[for subnet in aws_subnet.database : subnet.id\\]", file("${path.module}/valkey.tf"))) &&
      can(regex("(?s)resource \"aws_elasticache_replication_group\" \"application\" \\{(?:\"[^\"]*\"|[^}\"])*subnet_group_name\\s*=\\s*aws_elasticache_subnet_group.application\\[0\\].name[^}]*security_group_ids\\s*=\\s*\\[aws_security_group.valkey\\[0\\].id\\]", file("${path.module}/valkey.tf")))
    )
    error_message = "Valkey must use exactly the private subnet group, dedicated SG, and EC2 SG ingress/egress relationships."
  }
}
