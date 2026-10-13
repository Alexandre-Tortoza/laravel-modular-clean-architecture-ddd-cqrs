resource "aws_iam_role" "web" {
  count       = local.host_count
  name_prefix = "${var.environment}-compose-"
  assume_role_policy = jsonencode({
    Version   = "2012-10-17"
    Statement = [{ Effect = "Allow", Action = "sts:AssumeRole", Principal = { Service = "ec2.amazonaws.com" } }]
  })
  tags = local.host_tags
}

# AWS's minimum Session Manager policy requires these channel operations to
# use Resource '*'. No Parameter Store, RunCommand or administrative policies.
# https://docs.aws.amazon.com/systems-manager/latest/userguide/getting-started-create-iam-instance-profile.html
resource "aws_iam_role_policy" "web" {
  count = local.host_count
  role  = aws_iam_role.web[0].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = concat([
      {
        Sid    = "SessionManager", Effect = "Allow", Resource = ["*"]
        Action = ["ssm:UpdateInstanceInformation", "ssmmessages:CreateControlChannel", "ssmmessages:CreateDataChannel", "ssmmessages:OpenControlChannel", "ssmmessages:OpenDataChannel"]
      }
      ], length(var.instance_access.ecr_repository_arns) == 0 ? [] : [
      { Sid = "RegistryToken", Effect = "Allow", Action = ["ecr:GetAuthorizationToken"], Resource = ["*"] },
      {
        Sid    = "PullSelectedImages", Effect = "Allow", Resource = sort(tolist(var.instance_access.ecr_repository_arns))
        Action = ["ecr:BatchCheckLayerAvailability", "ecr:GetDownloadUrlForLayer", "ecr:BatchGetImage"]
      }
      ], length(var.instance_access.secret_arns) == 0 ? [] : [
      { Sid = "ReadSelectedSecrets", Effect = "Allow", Action = ["secretsmanager:GetSecretValue"], Resource = sort(tolist(var.instance_access.secret_arns)) }
      ], length(var.instance_access.secret_kms_key_arns) == 0 ? [] : [
      {
        Sid = "DecryptSelectedSecrets", Effect = "Allow", Action = ["kms:Decrypt"], Resource = sort(tolist(var.instance_access.secret_kms_key_arns))
        Condition = {
          StringEquals = { "kms:ViaService" = "secretsmanager.${var.aws_region}.amazonaws.com", "kms:EncryptionContext:SecretARN" = sort(tolist(var.instance_access.secret_arns)) }
        }
      }
    ])
  })
}

resource "aws_iam_instance_profile" "web" {
  count       = local.host_count
  name_prefix = "${var.environment}-compose-"
  role        = aws_iam_role.web[0].name
  tags        = local.host_tags
}
