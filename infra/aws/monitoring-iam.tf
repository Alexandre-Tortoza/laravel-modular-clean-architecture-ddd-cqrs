# PutMetricData has no resource-level ARN support. Restrict its namespace and
# region; the host can append only the pre-created health stream, not raw app logs.
resource "aws_iam_role_policy" "observation" {
  count = local.observation_count
  role  = try(aws_iam_role.web[0].id, "missing-host")
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "PublishSelectedHealthMetrics", Effect = "Allow", Action = ["cloudwatch:PutMetricData"], Resource = ["*"]
        Condition = { StringEquals = { "cloudwatch:namespace" = local.metric_namespace, "aws:RequestedRegion" = var.aws_region } }
      },
      {
        Sid      = "AppendRedactedHealthEvents", Effect = "Allow", Action = ["logs:PutLogEvents"]
        Resource = ["arn:aws:logs:${var.aws_region}:${data.aws_caller_identity.observation[0].account_id}:log-group:${local.observed_log_groups.application}:log-stream:health"]
      }
    ]
  })
  lifecycle {
    precondition {
      condition     = var.ec2_host != null && var.rds != null
      error_message = "Observation grants require the selected EC2/private RDS contracts."
    }
  }
}
