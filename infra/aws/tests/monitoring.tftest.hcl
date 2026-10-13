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
    snapshots       = { interval_hours = 24, start_time_utc = "01:00", retain_count = 7 }
  }
  monitoring = {
    alarm_topic_arn           = "arn:aws:sns:us-east-1:111122223333:fixture-alerts"
    log_retention_days        = 7
    rds_log_retention_minutes = 1440
    cpu_threshold_percent     = { ec2 = 70, rds = 75 }
    rds_freeable_memory_bytes = 1000000
    rds_free_storage_bytes    = 2000000
    rds_max_connections       = 100
    period_seconds            = 300
    evaluation_periods        = 2
  }
}

run "monitoring_is_opt_in" {
  command = plan
  variables { monitoring = null }
  assert {
    condition     = length(aws_cloudwatch_metric_alarm.essential) == 0 && length(aws_cloudwatch_log_group.runtime) == 0 && length(aws_budgets_budget.monthly) == 0
    error_message = "Default reference must not activate paid observation or budgets."
  }
}
run "essential_health_and_capacity_alarms" {
  command = plan
  assert {
    condition     = jsondecode(aws_iam_role_policy.observation[0].policy).Statement[0].Condition.StringEquals["cloudwatch:namespace"] == "Template/fixture/Runtime" && jsondecode(aws_iam_role_policy.observation[0].policy).Statement[0].Condition.StringEquals["aws:RequestedRegion"] == "us-east-1" && jsondecode(aws_iam_role_policy.observation[0].policy).Statement[1].Resource == ["arn:aws:logs:us-east-1:111122223333:log-group:/template/fixture/runtime:log-stream:health"]
    error_message = "Host must publish only its namespace/region and pre-created redacted health log stream."
  }
  assert {
    condition = (
      length(aws_cloudwatch_metric_alarm.essential) == 8 &&
      aws_cloudwatch_metric_alarm.essential["ec2-status"].metric_name == "StatusCheckFailed" &&
      aws_cloudwatch_metric_alarm.essential["ec2-cpu"].namespace == "AWS/EC2" &&
      aws_cloudwatch_metric_alarm.essential["rds-cpu"].namespace == "AWS/RDS" &&
      aws_cloudwatch_metric_alarm.essential["rds-memory"].metric_name == "FreeableMemory" &&
      aws_cloudwatch_metric_alarm.essential["rds-storage"].metric_name == "FreeStorageSpace" &&
      aws_cloudwatch_metric_alarm.essential["rds-connections"].metric_name == "DatabaseConnections" &&
      aws_cloudwatch_metric_alarm.essential["app-up"].metric_name == "Liveness" &&
      aws_cloudwatch_metric_alarm.essential["app-ready"].metric_name == "Readiness"
    )
    error_message = "Keep host/database capacity/failure signals separate from application liveness and dependency readiness."
  }
  assert {
    condition     = alltrue([for alarm in aws_cloudwatch_metric_alarm.essential : alarm.period == 300 && alarm.evaluation_periods == 2 && alarm.alarm_actions == toset([var.monitoring.alarm_topic_arn]) && alarm.treat_missing_data == "breaching"])
    error_message = "Use selected periods/actions and fail observably when the probe/metrics stop arriving."
  }
  assert {
    condition     = aws_cloudwatch_metric_alarm.essential["rds-cpu"].threshold == 75 && aws_cloudwatch_metric_alarm.essential["ec2-cpu"].threshold == 70
    error_message = "Preserve owner capacity thresholds without inventing sizing."
  }
}
run "log_retention_and_backups_are_bounded" {
  command = plan
  assert {
    condition     = anytrue([for parameter in aws_db_parameter_group.application[0].parameter : parameter.name == "rds.log_retention_period" && parameter.value == "1440"])
    error_message = "Native RDS diagnostic retention must use the explicitly selected supported minutes; raw logs are not redacted or automatically exported."
  }
  assert {
    condition     = length(aws_cloudwatch_log_group.runtime) == 1 && alltrue([for group in aws_cloudwatch_log_group.runtime : group.retention_in_days == 7]) && length(aws_db_instance.application[0].enabled_cloudwatch_logs_exports) == 0 && aws_db_instance.application[0].backup_retention_period == 7 && !aws_db_instance.application[0].delete_automated_backups && length(aws_dlm_lifecycle_policy.uploads) == 1
    error_message = "Configure explicit RDS/application log retention and retain the approved non-destructive backup policies."
  }
  assert {
    condition     = length(aws_budgets_budget.monthly) == 0
    error_message = "A monitoring selection cannot imply a financial budget without amount/currency/subscribers."
  }
}
run "reject_invalid_retention" {
  command = plan
  variables { monitoring = merge(var.monitoring, { log_retention_days = 2 }) }
  expect_failures = [var.monitoring]
}

run "reject_unbounded_native_rds_log_retention" {
  command = plan
  variables { monitoring = merge(var.monitoring, { rds_log_retention_minutes = 0 }) }
  expect_failures = [var.monitoring]
}
run "reject_overaggressive_basic_ec2_period" {
  command = plan
  variables { monitoring = merge(var.monitoring, { period_seconds = 60 }) }
  expect_failures = [var.monitoring]
}
run "reject_secret_or_wildcard_alert_target" {
  command = plan
  variables { monitoring = merge(var.monitoring, { alarm_topic_arn = "*" }) }
  expect_failures = [var.monitoring]
}

run "explicit_budget_amount_and_scope" {
  command = plan
  variables {
    cost_review = { owner = "fixture-owner", monthly_budget = { amount = 123, currency = "USD" } }
    monitoring  = merge(var.monitoring, { budget = { threshold_percent = 80, subscriber_emails = ["operator@fixture.invalid"], scope = "account" } })
  }
  assert {
    condition     = length(aws_budgets_budget.monthly) == 1 && aws_budgets_budget.monthly[0].limit_amount == "123" && aws_budgets_budget.monthly[0].limit_unit == "USD" && aws_budgets_budget.monthly[0].time_unit == "MONTHLY" && length(aws_budgets_budget.monthly[0].cost_filter) == 0 && length(aws_budgets_budget.monthly[0].notification) == 2
    error_message = "Use only the owner's explicit financial amount/currency, deliberate account scope and actual/forecast notifications; no automatic budget actions."
  }
}

run "tag_scoped_budget_preserves_environment_selector" {
  command = plan
  variables {
    cost_review = { owner = "fixture-owner", monthly_budget = { amount = 123, currency = "USD" } }
    monitoring  = merge(var.monitoring, { budget = { threshold_percent = 80, subscriber_emails = ["operator@fixture.invalid"], scope = "environment-tag" } })
  }
  assert {
    condition     = anytrue([for filter in aws_budgets_budget.monthly[0].cost_filter : filter.name == "TagKeyValue" && toset(filter.values) == toset(["user:Environment$fixture"])])
    error_message = "Use the selected environment cost-allocation tag without claiming that its billing activation has occurred."
  }
}

run "reject_budget_without_reviewed_amount" {
  command = plan
  variables { monitoring = merge(var.monitoring, { budget = { threshold_percent = 80, subscriber_emails = ["operator@fixture.invalid"], scope = "account" } }) }
  expect_failures = [var.monitoring]
}

run "reject_foreign_account_alert_topic" {
  command = plan
  variables { monitoring = merge(var.monitoring, { alarm_topic_arn = "arn:aws:sns:us-east-1:999988887777:fixture-alerts" }) }
  expect_failures = [aws_cloudwatch_metric_alarm.essential]
}

run "reject_missing_host" {
  command = plan
  variables { ec2_host = null }
  expect_failures = [aws_cloudwatch_metric_alarm.essential, aws_iam_role_policy.observation]
}
