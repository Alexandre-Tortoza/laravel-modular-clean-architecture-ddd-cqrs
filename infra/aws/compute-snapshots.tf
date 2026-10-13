locals {
  upload_snapshot_count = var.ec2_host == null ? 0 : (var.ec2_host.snapshots == null ? 0 : 1)
  snapshot_arn          = "arn:aws:ec2:${var.aws_region}::snapshot/*"
}

data "aws_caller_identity" "snapshots" { count = local.upload_snapshot_count }

resource "aws_iam_role" "upload_snapshots" {
  count       = local.upload_snapshot_count
  name_prefix = "${var.environment}-upload-snapshots-"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect = "Allow", Action = "sts:AssumeRole", Principal = { Service = "dlm.amazonaws.com" }
      Condition = {
        StringEquals = { "aws:SourceAccount" = data.aws_caller_identity.snapshots[0].account_id }
        ArnLike      = { "aws:SourceArn" = "arn:aws:dlm:${var.aws_region}:${data.aws_caller_identity.snapshots[0].account_id}:policy/*" }
      }
    }]
  })
  tags = local.host_tags
}

# Regional single-volume snapshots only: no AMI, cross-region copy, fast restore,
# RunCommand scripts or access to application secrets.
resource "aws_iam_role_policy" "upload_snapshots" {
  count = local.upload_snapshot_count
  role  = aws_iam_role.upload_snapshots[0].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Effect = "Allow", Action = ["ec2:DescribeVolumes", "ec2:DescribeSnapshots"], Resource = ["*"] },
      { Effect = "Allow", Action = ["ec2:CreateSnapshot"], Resource = [aws_ebs_volume.uploads[0].arn, local.snapshot_arn] },
      {
        Effect    = "Allow", Action = ["ec2:CreateTags"], Resource = [local.snapshot_arn]
        Condition = { StringEquals = { "aws:RequestTag/TemplateUploads" = var.environment, "aws:RequestTag/SnapshotCreator" = "TemplateDLM" } }
      },
      {
        Effect    = "Allow", Action = ["ec2:DeleteSnapshot"], Resource = [local.snapshot_arn]
        Condition = { StringEquals = { "aws:ResourceTag/TemplateUploads" = var.environment, "aws:ResourceTag/SnapshotCreator" = "TemplateDLM" } }
      },
    ]
  })
}

resource "aws_dlm_lifecycle_policy" "uploads" {
  count              = local.upload_snapshot_count
  description        = "${var.environment} retained upload EBS snapshots"
  execution_role_arn = aws_iam_role.upload_snapshots[0].arn
  state              = "ENABLED"
  policy_details {
    policy_type    = "EBS_SNAPSHOT_MANAGEMENT"
    resource_types = ["VOLUME"]
    target_tags    = { TemplateUploads = var.environment }
    schedule {
      name = "operator-selected-upload-retention"
      create_rule {
        interval      = var.ec2_host.snapshots.interval_hours
        interval_unit = "HOURS"
        times         = [var.ec2_host.snapshots.start_time_utc]
      }
      retain_rule { count = var.ec2_host.snapshots.retain_count }
      tags_to_add = { TemplateUploads = var.environment, SnapshotCreator = "TemplateDLM" }
      copy_tags   = false
    }
  }
  tags       = local.host_tags
  depends_on = [aws_iam_role_policy.upload_snapshots]
}
