variable "github_deployment_identity" {
  description = "Opt-in GitHub deployment identity with an existing account-level provider and an exact protected environment subject. The separately approved account-owned SSM document must expose only the reviewed release operation. Null creates no role."
  type        = object({ oidc_provider_arn = string, subject = string, document_arn = string })
  default     = null
  validation {
    condition = var.github_deployment_identity == null ? true : (
      can(regex("^arn:aws:iam::[0-9]{12}:oidc-provider/token\\.actions\\.githubusercontent\\.com$", var.github_deployment_identity.oidc_provider_arn)) &&
      can(regex("^repo:[A-Za-z0-9_.-]+@[0-9]+/[A-Za-z0-9_.-]+@[0-9]+:environment:[A-Za-z0-9_.-]+$", var.github_deployment_identity.subject)) &&
      can(regex("^arn:aws:ssm:${var.aws_region}:[0-9]{12}:document/[A-Za-z0-9_.-]+$", var.github_deployment_identity.document_arn)) &&
      var.ec2_host != null && var.container_registry != null
    )
    error_message = "Deployment requires the selected EC2/private registries, an existing GitHub provider, one exact environment subject (no wildcard/tag/PR) and an account-owned regional document, never AWS-RunShellScript."
  }
}

locals {
  github_deploy_count = var.github_deployment_identity == null || var.ec2_host == null || var.container_registry == null ? 0 : 1
  github_deploy_policy = local.github_deploy_count == 0 ? null : {
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "SendReviewedReleaseCommand", Effect = "Allow", Action = ["ssm:SendCommand"]
        Resource  = [var.github_deployment_identity.document_arn, "arn:aws:ec2:${var.aws_region}:${data.aws_caller_identity.registry[0].account_id}:instance/${aws_instance.web[0].id}"]
        Condition = { StringEquals = { "aws:RequestedRegion" = var.aws_region } }
      },
      {
        # GetCommandInvocation has no resource-level ARN support. Regional
        # observation is necessary; it must never be used to print secret output.
        Sid       = "ObserveReleaseResult", Effect = "Allow", Action = ["ssm:GetCommandInvocation"], Resource = ["*"]
        Condition = { StringEquals = { "aws:RequestedRegion" = var.aws_region } }
      },
    ]
  }
}

resource "aws_iam_role" "github_deploy" {
  count                = local.github_deploy_count
  name_prefix          = "${var.environment}-github-deploy-"
  max_session_duration = 3600
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow", Action = "sts:AssumeRoleWithWebIdentity"
      Principal = { Federated = var.github_deployment_identity.oidc_provider_arn }
      Condition = { StringEquals = {
        "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com"
        "token.actions.githubusercontent.com:sub" = var.github_deployment_identity.subject
      } }
    }]
  })
  tags = local.network_tags
  lifecycle {
    precondition {
      condition     = split(":", var.github_deployment_identity.oidc_provider_arn)[4] == data.aws_caller_identity.registry[0].account_id && split(":", var.github_deployment_identity.document_arn)[4] == data.aws_caller_identity.registry[0].account_id
      error_message = "The registered OIDC provider and reviewed deployment document must belong to the selected AWS account."
    }
    precondition {
      condition     = var.container_registry.publisher == null ? true : var.github_deployment_identity.subject != var.container_registry.publisher.subject
      error_message = "Publication and deployment must use different protected environment subjects and separate roles."
    }
  }
}

resource "aws_iam_role_policy" "github_deploy" {
  count  = local.github_deploy_count
  role   = aws_iam_role.github_deploy[0].id
  policy = jsonencode(local.github_deploy_policy)
}

output "github_deployment_identity" {
  value = local.github_deploy_count == 0 ? null : {
    role_arn     = aws_iam_role.github_deploy[0].arn
    subject      = var.github_deployment_identity.subject
    document_arn = var.github_deployment_identity.document_arn
    instance_id  = aws_instance.web[0].id
    audience     = "sts.amazonaws.com"
  }
  description = "Prepared deployment identity only; provisioning and protected GitHub environment activation require separate owner approval. No Terraform role is needed for mocked CI."
}
