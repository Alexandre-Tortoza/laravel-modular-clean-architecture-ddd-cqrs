mock_provider "aws" {
  mock_data "aws_caller_identity" { defaults = { account_id = "123456789012" } }
  mock_data "aws_ami" {
    defaults = { architecture = "x86_64", name = "ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-fixture", root_device_type = "ebs", virtualization_type = "hvm" }
  }
  mock_data "aws_ec2_instance_type" { defaults = { hypervisor = "nitro", supported_architectures = ["x86_64"] } }
}

# Synthetic interface inputs are not capacity or region recommendations.
variables {
  aws_region  = "fixture-region"
  environment = "fixture"
  domain_name = "fixture.invalid"
  network = {
    vpc_cidr           = "10.123.0.0/16"
    public_subnet_cidr = "10.123.1.0/24"
    public_subnet_az   = "fixture-az-a"
    private_subnets = [
      { cidr = "10.123.2.0/24", availability_zone = "fixture-az-a" },
      { cidr = "10.123.3.0/24", availability_zone = "fixture-az-b" },
    ]
  }
  compute                = { instance_type = "fixture.instance", root_volume_gib = 1 }
  database               = { instance_class = "fixture.database", allocated_storage_gib = 1, backup_retention_days = 1 }
  recovery               = { rpo_minutes = 1, rto_minutes = 1, restore_test_interval_days = 1 }
  cost_review            = { owner = "fixture-owner", monthly_budget = null }
  web_ingress_ipv4_cidrs = ["203.0.113.0/24"]
  container_registry = {
    repositories     = { php = "fixture/php", nginx = "fixture/nginx" }
    egress_path      = "public-ipv4-igw"
    storage_estimate = { expected_gib = 2, price_per_gib_month = 0.25, currency = "FIX" }
  }
}

run "registry_is_opt_in" {
  command = plan
  variables { container_registry = null }
  assert {
    condition     = length(aws_ecr_repository.release) == 0 && length(aws_iam_role.registry_publisher) == 0
    error_message = "No private registry or publication identity may be planned without explicit selection."
  }
}

run "immutable_private_releases" {
  command = plan
  assert {
    condition = length(aws_ecr_repository.release) == 2 && alltrue([
      for repository in aws_ecr_repository.release : repository.image_tag_mutability == "IMMUTABLE" && !repository.force_delete && repository.encryption_configuration[0].encryption_type == "AES256"
    ])
    error_message = "The selected PHP/Nginx registries must be private, encrypted, strictly immutable and safe from forced deletion."
  }
  assert {
    condition = alltrue([
      for filename in fileset(path.module, "*.tf") : length(regexall("resource\\s+\\\"aws_ecr_lifecycle_policy\\\"", file("${path.module}/${filename}"))) == 0
    ])
    error_message = "Lifecycle expiry must not delete tagged or untagged digests that may still be active; N/N-1 cleanup requires a separate reviewed operation."
  }
}

run "explicit_storage_cost" {
  command = plan
  assert {
    condition     = output.release_registry.storage_estimate.estimated_monthly_amount == 0.5 && output.release_registry.storage_estimate.currency == "FIX" && output.release_registry.egress_path == "public-ipv4-igw"
    error_message = "Keep the operator's explicit storage/price/currency and feasible EC2 IGW path; the estimate is not observed billing."
  }
}

run "reject_duplicate_image_repository" {
  command = plan
  variables { container_registry = merge(var.container_registry, { repositories = { php = "fixture/php", nginx = "fixture/php" } }) }
  expect_failures = [var.container_registry]
}

run "reject_unimplemented_egress_path" {
  command = plan
  variables { container_registry = merge(var.container_registry, { egress_path = "private-without-endpoints" }) }
  expect_failures = [var.container_registry]
}

run "reject_unreviewed_storage_price" {
  command = plan
  variables { container_registry = merge(var.container_registry, { storage_estimate = { expected_gib = 2, price_per_gib_month = 0, currency = "FIX" } }) }
  expect_failures = [var.container_registry]
}

run "exact_immutable_github_subject" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { publisher = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner@123456/fixture-repo@789012:environment:release"
    } })
  }
  assert {
    condition = (
      length(aws_iam_role.registry_publisher) == 1 &&
      jsondecode(aws_iam_role.registry_publisher[0].assume_role_policy).Statement[0].Action == "sts:AssumeRoleWithWebIdentity" &&
      jsondecode(aws_iam_role.registry_publisher[0].assume_role_policy).Statement[0].Condition.StringEquals["token.actions.githubusercontent.com:aud"] == "sts.amazonaws.com" &&
      jsondecode(aws_iam_role.registry_publisher[0].assume_role_policy).Statement[0].Condition.StringEquals["token.actions.githubusercontent.com:sub"] == var.container_registry.publisher.subject &&
      toset(jsondecode(aws_iam_role_policy.registry_publish[0].policy).Statement[1].Resource) == toset(["arn:aws:ecr:fixture-region:123456789012:repository/fixture/php", "arn:aws:ecr:fixture-region:123456789012:repository/fixture/nginx"]) &&
      toset(jsondecode(aws_iam_role_policy.registry_publish[0].policy).Statement[1].Action) == toset(["ecr:BatchCheckLayerAvailability", "ecr:BatchGetImage", "ecr:InitiateLayerUpload", "ecr:UploadLayerPart", "ecr:CompleteLayerUpload", "ecr:PutImage"])
    )
    error_message = "GitHub publication must use exact audience/subject and only upload permissions on the two private repos, without IAM/secret/deletion access."
  }
}

run "reject_wildcard_oidc_subject" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { publisher = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner/fixture-repo:*"
    } })
  }
  expect_failures = [var.container_registry]
}

run "reject_pull_request_subject" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { publisher = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner/fixture-repo:pull_request"
    } })
  }
  expect_failures = [var.container_registry]
}

run "reject_foreign_oidc_provider_account" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { publisher = {
      oidc_provider_arn = "arn:aws:iam::999999999999:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner/fixture-repo:environment:release"
    } })
  }
  expect_failures = [aws_iam_role.registry_publisher]
}

run "ec2_has_exact_pull_permissions" {
  command = plan
  variables {
    ec2_host = {
      ami_id          = "ami-0123456789abcdef0", ami_owner_account_id = "099720109477", architecture = "x86_64", upload_volume_gib = 1
      docker_versions = { engine = "5:29.8.2-1~ubuntu.24.04~noble", containerd = "2.2.4-1~ubuntu.24.04~noble", buildx = "0.32.1-1~ubuntu.24.04~noble", compose = "5.1.0-1~ubuntu.24.04~noble" }
    }
  }
  assert {
    condition = (
      length(aws_iam_role_policy.registry_pull) == 1 && length(aws_iam_role.registry_publisher) == 0 &&
      length(jsondecode(aws_iam_role_policy.registry_pull[0].policy).Statement) == 2 &&
      toset(jsondecode(aws_iam_role_policy.registry_pull[0].policy).Statement[1].Action) == toset(["ecr:BatchCheckLayerAvailability", "ecr:GetDownloadUrlForLayer", "ecr:BatchGetImage"]) &&
      toset(jsondecode(aws_iam_role_policy.registry_pull[0].policy).Statement[1].Resource) == toset(["arn:aws:ecr:fixture-region:123456789012:repository/fixture/php", "arn:aws:ecr:fixture-region:123456789012:repository/fixture/nginx"])
    )
    error_message = "The EC2 identity must pull only the selected release repos and never inherit the publisher's write permissions."
  }
}

run "current_and_previous_release_digest_references" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { releases = {
      current  = { source_revision = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", php_digest = "sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", nginx_digest = "sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" }
      previous = { source_revision = "cccccccccccccccccccccccccccccccccccccccc", php_digest = "sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc", nginx_digest = "sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd" }
    } })
  }
  assert {
    condition = (
      output.release_registry.releases.current.php_image == "123456789012.dkr.ecr.fixture-region.amazonaws.com/fixture/php@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" &&
      output.release_registry.releases.previous.nginx_image == "123456789012.dkr.ecr.fixture-region.amazonaws.com/fixture/nginx@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd" &&
      output.release_registry.releases.current.release_tag == "release-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" &&
      output.release_registry.releases.previous.source_revision == "cccccccccccccccccccccccccccccccccccccccc"
    )
    error_message = "Both N/N-1 images must remain pinned to their supplied digests with source provenance; production may not rely on latest or a mutable tag."
  }
}

run "reject_latest_instead_of_release_digest" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { releases = {
      current = { source_revision = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", php_digest = "latest", nginx_digest = "sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" }
    } })
  }
  expect_failures = [var.container_registry]
}

run "legacy_exact_release_tag_subject" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { publisher = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner/fixture-repo:ref:refs/tags/v1.0.0"
    } })
  }
  assert {
    condition     = jsondecode(aws_iam_role.registry_publisher[0].assume_role_policy).Statement[0].Condition.StringEquals["token.actions.githubusercontent.com:sub"] == "repo:fixture-owner/fixture-repo:ref:refs/tags/v1.0.0"
    error_message = "A verified legacy-format release subject may select one exact tag without broadening to other refs."
  }
}

run "reject_branch_publisher_subject" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { publisher = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner/fixture-repo:ref:refs/heads/dev"
    } })
  }
  expect_failures = [var.container_registry]
}

run "reject_foreign_identity_issuer" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { publisher = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/other-issuer.invalid"
      subject           = "repo:fixture-owner/fixture-repo:environment:release"
    } })
  }
  expect_failures = [var.container_registry]
}

run "reject_missing_release_source_provenance" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { releases = {
      current = { source_revision = "abc123", php_digest = "sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", nginx_digest = "sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" }
    } })
  }
  expect_failures = [var.container_registry]
}
