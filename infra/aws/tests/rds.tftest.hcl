mock_provider "aws" {
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
}

run "database_requires_explicit_opt_in" {
  command = plan
  variables { rds = null }
  assert {
    condition     = length(aws_db_instance.application) == 0 && length(aws_db_subnet_group.application) == 0
    error_message = "Default reference validation must not select or provision RDS."
  }
}

run "private_encrypted_postgresql" {
  command = plan

  assert {
    condition = (
      aws_db_instance.application[0].engine == "postgres" &&
      aws_db_instance.application[0].engine_version == "16.15" &&
      !aws_db_instance.application[0].publicly_accessible && !aws_db_instance.application[0].multi_az &&
      aws_db_instance.application[0].storage_encrypted && aws_db_instance.application[0].deletion_protection &&
      aws_db_instance.application[0].manage_master_user_password &&
      aws_db_instance.application[0].password == null &&
      aws_db_instance.application[0].allocated_storage == 20 && aws_db_instance.application[0].backup_retention_period == 7 &&
      !aws_db_instance.application[0].delete_automated_backups && !aws_db_instance.application[0].skip_final_snapshot &&
      !aws_db_instance.application[0].apply_immediately && !aws_db_instance.application[0].allow_major_version_upgrade
    )
    error_message = "RDS must remain private, encrypted, Single-AZ, recoverable, managed-password and maintenance-window controlled."
  }
  assert {
    condition     = anytrue([for parameter in aws_db_parameter_group.application[0].parameter : parameter.name == "rds.force_ssl" && parameter.value == "1"])
    error_message = "The database must refuse plaintext clients."
  }
  assert {
    condition     = output.database_connection.sslmode == "verify-full" && output.database_connection.username == "fixture_app" && output.database_connection.username != aws_db_instance.application[0].username
    error_message = "Runtime must verify server identity and use the separately provisioned non-administrative application role."
  }
}

run "reject_same_administrator_and_application_role" {
  command = plan
  variables { rds = merge(var.rds, { application_username = "fixture_admin" }) }
  expect_failures = [var.rds]
}

run "reject_non_postgresql_16_engine" {
  command = plan
  variables { rds = merge(var.rds, { engine_version = "17.1" }) }
  expect_failures = [var.rds]
}

run "reject_administrative_application_role" {
  command = plan
  variables { rds = merge(var.rds, { application_username = "rds_superuser" }) }
  expect_failures = [var.rds]
}

run "reject_unbounded_or_insufficient_storage_ceiling" {
  command = plan
  variables { rds = merge(var.rds, { max_allocated_storage_gib = 10 }) }
  expect_failures = [var.rds]
}

run "reject_database_in_public_only_az" {
  command = plan
  variables { rds = merge(var.rds, { availability_zone = "fixture-az-c" }) }
  expect_failures = [var.rds]
}

run "reject_storage_outside_selected_class_limits" {
  command = plan
  variables { database = { instance_class = "fixture.database", allocated_storage_gib = 1, backup_retention_days = 7 } }
  expect_failures = [aws_db_instance.application]
}

run "reject_key_from_another_region" {
  command = plan
  variables { rds = merge(var.rds, { storage_kms_key_arn = "arn:aws:kms:us-west-2:111122223333:key/00000000-0000-0000-0000-000000000001" }) }
  expect_failures = [var.rds]
}

run "database_has_no_automatic_sql_or_secret_value_access" {
  command = plan
  assert {
    condition = (
      can(regex("vpc_security_group_ids\\s*=\\s*\\[aws_security_group.database.id\\]", file("${path.module}/rds.tf"))) &&
      can(regex("subnet_ids\\s*=\\s*\\[for subnet in aws_subnet.database : subnet.id\\]", file("${path.module}/rds.tf"))) &&
      length(regexall("provisioner|aws_secretsmanager_secret_version|aws_ssm_parameter", file("${path.module}/rds.tf"))) == 0
    )
    error_message = "RDS must reference only private subnets/SG and never execute SQL or retrieve secret values into state."
  }
}
