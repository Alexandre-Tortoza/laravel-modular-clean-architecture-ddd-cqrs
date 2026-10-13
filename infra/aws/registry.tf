locals {
  release_repositories = var.container_registry == null ? {} : var.container_registry.repositories
  publisher_count      = var.container_registry == null ? 0 : (var.container_registry.publisher == null ? 0 : 1)
  registry_pull_count  = var.container_registry == null || var.ec2_host == null ? 0 : 1
  repository_arns = var.container_registry == null ? {} : {
    for role, name in local.release_repositories : role => "arn:aws:ecr:${var.aws_region}:${data.aws_caller_identity.registry[0].account_id}:repository/${name}"
  }
  repository_urls = var.container_registry == null ? {} : {
    for role, name in local.release_repositories : role => "${data.aws_caller_identity.registry[0].account_id}.dkr.ecr.${var.aws_region}.amazonaws.com/${name}"
  }
}

data "aws_caller_identity" "registry" { count = var.container_registry == null ? 0 : 1 }

resource "aws_ecr_repository" "release" {
  for_each             = local.release_repositories
  name                 = each.value
  image_tag_mutability = "IMMUTABLE"
  force_delete         = false
  encryption_configuration { encryption_type = "AES256" }
  image_scanning_configuration { scan_on_push = true }
  tags = merge(local.network_tags, { ImageRole = each.key })
  lifecycle { prevent_destroy = true }
}

# No lifecycle expiration, including untagged images: a digest may still be
# active even after losing a tag. Cleanup requires a separately reviewed list
# excluding current and rollback digests; a count/age rule cannot express that.

resource "aws_iam_role_policy" "registry_pull" {
  count = local.registry_pull_count
  role  = aws_iam_role.web[0].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Sid = "RegistryToken", Effect = "Allow", Action = ["ecr:GetAuthorizationToken"], Resource = ["*"] },
      {
        Sid    = "PullReleaseImages", Effect = "Allow", Resource = sort(values(local.repository_arns))
        Action = ["ecr:BatchCheckLayerAvailability", "ecr:GetDownloadUrlForLayer", "ecr:BatchGetImage"]
      },
    ]
  })
}

# An existing account-level provider avoids silently taking ownership of a
# shared OIDC registration. Match the operator-verified subject exactly;
# GitHub can include immutable repository/owner IDs in newer subjects.
resource "aws_iam_role" "registry_publisher" {
  count                = local.publisher_count
  name_prefix          = "${var.environment}-ecr-publisher-"
  max_session_duration = 3600
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow", Action = "sts:AssumeRoleWithWebIdentity"
      Principal = { Federated = var.container_registry.publisher.oidc_provider_arn }
      Condition = {
        StringEquals = {
          "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com"
          "token.actions.githubusercontent.com:sub" = var.container_registry.publisher.subject
        }
      }
    }]
  })
  tags = local.network_tags
  lifecycle {
    precondition {
      condition     = split(":", var.container_registry.publisher.oidc_provider_arn)[4] == data.aws_caller_identity.registry[0].account_id
      error_message = "The explicitly registered GitHub OIDC provider must belong to this AWS account."
    }
  }
}

resource "aws_iam_role_policy" "registry_publish" {
  count = local.publisher_count
  role  = aws_iam_role.registry_publisher[0].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Sid = "RegistryToken", Effect = "Allow", Action = ["ecr:GetAuthorizationToken"], Resource = ["*"] },
      {
        Sid    = "PublishReleaseImages", Effect = "Allow", Resource = sort(values(local.repository_arns))
        Action = ["ecr:BatchCheckLayerAvailability", "ecr:InitiateLayerUpload", "ecr:UploadLayerPart", "ecr:CompleteLayerUpload", "ecr:PutImage"]
      },
    ]
  })
}

output "release_registry" {
  description = "Private registry, explicit egress/storage estimate and publication identity; no registry, identity or image exists until separately authorized provisioning/publication."
  value = var.container_registry == null ? null : {
    repositories        = local.repository_urls
    repository_arns     = local.repository_arns
    retained_artifacts  = "all-tagged-and-untagged-digests; separately reviewed cleanup only"
    egress_path         = var.container_registry.egress_path
    egress_requirements = "EC2 public IPv4, web IGW route and HTTPS egress to ECR API/registry and S3 image layers; no NAT or PrivateLink resources"
    storage_estimate = merge(var.container_registry.storage_estimate, {
      estimated_monthly_amount = var.container_registry.storage_estimate.expected_gib * var.container_registry.storage_estimate.price_per_gib_month
      pricing_reference        = "https://aws.amazon.com/ecr/pricing/"
    })
    publisher_role_arn = local.publisher_count == 0 ? null : aws_iam_role.registry_publisher[0].arn
    publisher_subject  = local.publisher_count == 0 ? null : var.container_registry.publisher.subject
    releases = var.container_registry.releases == null ? null : {
      for role, release in { current = var.container_registry.releases.current, previous = var.container_registry.releases.previous } : role => release == null ? null : {
        source_revision              = release.source_revision
        release_tag                  = "release-${release.source_revision}"
        php_image                    = "${local.repository_urls.php}@${release.php_digest}"
        nginx_image                  = "${local.repository_urls.nginx}@${release.nginx_digest}"
      }
    }
  }
}
