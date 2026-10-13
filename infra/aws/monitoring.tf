locals {
  observation_count    = var.monitoring == null ? 0 : 1
  metric_namespace     = "Template/${var.environment}/Runtime"
  monthly_budget_count = var.monitoring == null ? 0 : (var.monitoring.budget == null ? 0 : 1)
  essential_alarms = var.monitoring == null ? {} : {
    ec2-status      = { namespace = "AWS/EC2", metric = "StatusCheckFailed", statistic = "Maximum", threshold = 0, comparison = "GreaterThanThreshold", dimensions = { InstanceId = try(aws_instance.web[0].id, "missing-host") } }
    ec2-cpu         = { namespace = "AWS/EC2", metric = "CPUUtilization", statistic = "Average", threshold = var.monitoring.cpu_threshold_percent.ec2, comparison = "GreaterThanThreshold", dimensions = { InstanceId = try(aws_instance.web[0].id, "missing-host") } }
    rds-cpu         = { namespace = "AWS/RDS", metric = "CPUUtilization", statistic = "Average", threshold = var.monitoring.cpu_threshold_percent.rds, comparison = "GreaterThanThreshold", dimensions = { DBInstanceIdentifier = try(var.rds.identifier, "missing-database") } }
    rds-memory      = { namespace = "AWS/RDS", metric = "FreeableMemory", statistic = "Minimum", threshold = var.monitoring.rds_freeable_memory_bytes, comparison = "LessThanThreshold", dimensions = { DBInstanceIdentifier = try(var.rds.identifier, "missing-database") } }
    rds-storage     = { namespace = "AWS/RDS", metric = "FreeStorageSpace", statistic = "Minimum", threshold = var.monitoring.rds_free_storage_bytes, comparison = "LessThanThreshold", dimensions = { DBInstanceIdentifier = try(var.rds.identifier, "missing-database") } }
    rds-connections = { namespace = "AWS/RDS", metric = "DatabaseConnections", statistic = "Maximum", threshold = var.monitoring.rds_max_connections, comparison = "GreaterThanThreshold", dimensions = { DBInstanceIdentifier = try(var.rds.identifier, "missing-database") } }
    app-up          = { namespace = local.metric_namespace, metric = "Liveness", statistic = "Minimum", threshold = 1, comparison = "LessThanThreshold", dimensions = { Environment = var.environment } }
    app-ready       = { namespace = local.metric_namespace, metric = "Readiness", statistic = "Minimum", threshold = 1, comparison = "LessThanThreshold", dimensions = { Environment = var.environment } }
  }
  observed_log_groups = var.monitoring == null ? {} : {
    application = "/template/${var.environment}/runtime"
  }
}

data "aws_caller_identity" "observation" { count = local.observation_count }

resource "aws_cloudwatch_metric_alarm" "essential" {
  for_each            = local.essential_alarms
  alarm_name          = "${var.environment}-${each.key}"
  alarm_description   = "Operator-selected ${each.key} signal; single EC2/Single-AZ remain failure domains. Missing data breaches; app readiness is distinct from liveness."
  namespace           = each.value.namespace
  metric_name         = each.value.metric
  statistic           = each.value.statistic
  threshold           = each.value.threshold
  comparison_operator = each.value.comparison
  dimensions          = each.value.dimensions
  period              = var.monitoring.period_seconds
  evaluation_periods  = var.monitoring.evaluation_periods
  treat_missing_data  = "breaching"
  alarm_actions       = [var.monitoring.alarm_topic_arn]
  ok_actions          = [var.monitoring.alarm_topic_arn]
  tags                = local.network_tags
  lifecycle {
    precondition {
      condition     = var.ec2_host != null && var.rds != null
      error_message = "Observation requires the selected EC2 and private RDS contracts."
    }
    precondition {
      condition     = split(":", var.monitoring.alarm_topic_arn)[4] == data.aws_caller_identity.observation[0].account_id
      error_message = "Use an existing approved alert topic in this selected region/account; subscriptions/publishing policy require separate operator verification."
    }
  }
}

resource "aws_cloudwatch_log_group" "runtime" {
  for_each          = local.observed_log_groups
  name              = each.value
  retention_in_days = var.monitoring.log_retention_days
  tags              = local.network_tags
  lifecycle { prevent_destroy = true }
}

resource "aws_cloudwatch_log_stream" "health" {
  count          = local.observation_count
  name           = "health"
  log_group_name = aws_cloudwatch_log_group.runtime["application"].name
}

resource "aws_budgets_budget" "monthly" {
  count        = local.monthly_budget_count
  name         = "${var.environment}-reviewed-monthly-cost"
  budget_type  = "COST"
  time_unit    = "MONTHLY"
  limit_amount = tostring(var.cost_review.monthly_budget.amount)
  limit_unit   = var.cost_review.monthly_budget.currency
  dynamic "cost_filter" {
    for_each = var.monitoring.budget.scope == "environment-tag" ? [1] : []
    content {
      name   = "TagKeyValue"
      values = [join("$", ["user:Environment", var.environment])]
    }
  }
  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = var.monitoring.budget.threshold_percent
    threshold_type             = "PERCENTAGE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = sort(tolist(var.monitoring.budget.subscriber_emails))
  }
  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = var.monitoring.budget.threshold_percent
    threshold_type             = "PERCENTAGE"
    notification_type          = "FORECASTED"
    subscriber_email_addresses = sort(tolist(var.monitoring.budget.subscriber_emails))
  }
}
