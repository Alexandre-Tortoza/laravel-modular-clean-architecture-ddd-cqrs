variable "monitoring" {
  description = "Opt-in EC2/RDS/application observation. Select reviewed thresholds, basic-metric-compatible periods, retained logs and an existing approved SNS destination. Null creates no observation/budget resources."
  type = object({
    alarm_topic_arn           = string
    log_retention_days        = number
    rds_log_retention_minutes = number
    cpu_threshold_percent     = object({ ec2 = number, rds = number })
    rds_freeable_memory_bytes = number
    rds_free_storage_bytes    = number
    rds_max_connections       = number
    period_seconds            = number
    evaluation_periods        = number
    budget = optional(object({
      threshold_percent = number
      subscriber_emails = set(string)
      scope             = string
    }))
  })
  default = null
  validation {
    condition = var.monitoring == null ? true : try(
      can(regex("^arn:aws:sns:${var.aws_region}:[0-9]{12}:[A-Za-z0-9_-]+$", var.monitoring.alarm_topic_arn)) &&
      contains([1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922, 3288, 3653], var.monitoring.log_retention_days) &&
      var.monitoring.rds_log_retention_minutes >= 1440 && var.monitoring.rds_log_retention_minutes <= 10080 && floor(var.monitoring.rds_log_retention_minutes) == var.monitoring.rds_log_retention_minutes &&
      alltrue([for value in values(var.monitoring.cpu_threshold_percent) : value > 0 && value <= 100]) &&
      alltrue([for value in [var.monitoring.rds_freeable_memory_bytes, var.monitoring.rds_free_storage_bytes, var.monitoring.rds_max_connections, var.monitoring.evaluation_periods] : value > 0 && floor(value) == value]) &&
      var.monitoring.period_seconds >= 300 && var.monitoring.period_seconds <= 86400 && var.monitoring.period_seconds % 60 == 0 &&
      var.monitoring.evaluation_periods * var.monitoring.period_seconds <= 86400,
    false)
    error_message = "Select an exact regional SNS ARN, supported CloudWatch retention and 1440–10080 whole minutes of native RDS retention, reviewed capacity thresholds and periods of at least 300 seconds (EC2 basic monitoring), with an evaluation window up to one day."
  }
  validation {
    condition = var.monitoring == null ? true : (var.monitoring.budget == null ? true : try(
      var.cost_review.monthly_budget != null &&
      var.monitoring.budget.threshold_percent > 0 && var.monitoring.budget.threshold_percent <= 100 &&
      length(var.monitoring.budget.subscriber_emails) >= 1 && length(var.monitoring.budget.subscriber_emails) <= 10 &&
      alltrue([for email in var.monitoring.budget.subscriber_emails : can(regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$", email))]) &&
      contains(["account", "environment-tag"], var.monitoring.budget.scope),
    false))
    error_message = "Opt-in budget alerts require the explicitly reviewed monthly amount/currency, selected threshold, 1–10 subscriber addresses and explicit account or activated environment-tag scope."
  }
}
