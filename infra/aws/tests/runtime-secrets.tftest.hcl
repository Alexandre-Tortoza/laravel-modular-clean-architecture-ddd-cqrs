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
}

run "runtime_secrets_are_opt_in" {
  command = plan
  variables { runtime_secrets = null }
  assert {
    condition     = length(aws_iam_role_policy.application_secrets) == 0 && output.runtime_secret_delivery == null
    error_message = "Default must not grant or fetch application secrets."
  }
}

run "one_application_secret_and_no_administrator_access" {
  command = plan
  assert {
    condition = (
      jsondecode(aws_iam_role_policy.application_secrets[0].policy).Statement[0].Resource == [var.runtime_secrets.application_secret_arn] &&
      jsondecode(aws_iam_role_policy.application_secrets[0].policy).Statement[0].Action == ["secretsmanager:GetSecretValue"] &&
      jsondecode(aws_iam_role_policy.application_secrets[0].policy).Statement[1].Effect == "Deny" &&
      jsondecode(aws_iam_role_policy.application_secrets[0].policy).Statement[1].NotResource == [var.runtime_secrets.application_secret_arn] &&
      jsondecode(aws_iam_role_policy.application_secrets[0].policy).Statement[2].Resource == [var.runtime_secrets.kms_key_arn] &&
      jsondecode(aws_iam_role_policy.application_secrets[0].policy).Statement[2].Condition.StringEquals["kms:EncryptionContext:SecretARN"] == var.runtime_secrets.application_secret_arn &&
      jsondecode(aws_iam_role_policy.application_secrets[0].policy).Statement[2].Condition.StringEquals["kms:ViaService"] == "secretsmanager.us-east-1.amazonaws.com"
    )
    error_message = "Host must read one application secret, deny every other secret and decrypt only its selected KMS context."
  }
  assert {
    condition     = alltrue([for filename in fileset(path.module, "*.tf") : length(regexall("aws_secretsmanager_secret_version", file("${path.module}/${filename}"))) == 0])
    error_message = "No Terraform-managed or fetched secret value may enter state or plans."
  }
}

run "reject_wildcard_secret" {
  command = plan
  variables { runtime_secrets = merge(var.runtime_secrets, { application_secret_arn = "arn:aws:secretsmanager:us-east-1:111122223333:secret:*" }) }
  expect_failures = [var.runtime_secrets]
}
run "reject_foreign_kms_region" {
  command = plan
  variables { runtime_secrets = merge(var.runtime_secrets, { kms_key_arn = "arn:aws:kms:eu-west-1:111122223333:key/00000000-0000-0000-0000-000000000003" }) }
  expect_failures = [var.runtime_secrets]
}
run "reject_foreign_secret_account" {
  command = plan
  variables {
    runtime_secrets = merge(var.runtime_secrets, {
      application_secret_arn = "arn:aws:secretsmanager:us-east-1:999988887777:secret:fixture-app-Ab1234"
      kms_key_arn            = "arn:aws:kms:us-east-1:999988887777:key/00000000-0000-0000-0000-000000000003"
    })
  }
  expect_failures = [aws_iam_role_policy.application_secrets]
}
run "reject_administrator_username" {
  command = plan
  variables { runtime_secrets = merge(var.runtime_secrets, { database_username = "postgres" }) }
  expect_failures = [var.runtime_secrets]
}
run "reject_mismatched_database_application_role" {
  command = plan
  variables { runtime_secrets = merge(var.runtime_secrets, { database_username = "another_app" }) }
  expect_failures = [aws_iam_role_policy.application_secrets]
}
run "reject_secrets_without_host" {
  command = plan
  variables { ec2_host = null }
  expect_failures = [aws_iam_role_policy.application_secrets]
}
run "reject_legacy_extra_secret_grant" {
  command = plan
  variables { instance_access = { secret_arns = ["arn:aws:secretsmanager:us-east-1:111122223333:secret:administrator-Ab1234"] } }
  expect_failures = [aws_iam_role_policy.application_secrets]
}
