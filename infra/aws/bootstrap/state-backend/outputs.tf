output "backend_configuration" {
  description = "Reviewed values for a separately authorized remote initialization; no backend initialization is performed by this output."
  value = {
    bucket       = var.state_bucket_name
    key          = local.state_key
    region       = var.aws_region
    encrypt      = true
    use_lockfile = true
  }
}

output "state_writer_policy" {
  description = "Least-privilege policy template for one environment. Attach only through a separately reviewed IAM change; no IAM grant is created here."
  value = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "ListEnvironmentState"
        Effect    = "Allow"
        Action    = ["s3:ListBucket"]
        Resource  = local.bucket_arn
        Condition = { StringEquals = { "s3:prefix" = [local.state_key, "${local.state_key}.tflock"] } }
      },
      {
        Sid      = "ReadWriteState"
        Effect   = "Allow"
        Action   = ["s3:GetObject", "s3:PutObject"]
        Resource = local.state_arn
      },
      {
        Sid      = "ManageStateLock"
        Effect   = "Allow"
        Action   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
        Resource = local.lock_arn
      },
    ]
  })
}
