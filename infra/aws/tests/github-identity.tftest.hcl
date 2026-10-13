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


run "deployment_identity_is_opt_in" {
  command = plan
  assert {
    condition     = length(aws_iam_role.github_deploy) == 0 && length(aws_iam_role_policy.github_deploy) == 0
    error_message = "No GitHub deployment role exists until explicitly approved."
  }
}

run "separate_exact_environment_deploy_role" {
  command = plan
  variables {
    container_registry = merge(var.container_registry, { publisher = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner@123456/fixture-repo@789012:environment:publisher"
    } })
    ec2_host = {
      ami_id          = "ami-0123456789abcdef0", ami_owner_account_id = "099720109477", architecture = "x86_64", upload_volume_gib = 1
      docker_versions = { engine = "5:29.8.2-1~ubuntu.24.04~noble", containerd = "2.2.4-1~ubuntu.24.04~noble", buildx = "0.32.1-1~ubuntu.24.04~noble", compose = "5.1.0-1~ubuntu.24.04~noble" }
    }
    github_deployment_identity = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner@123456/fixture-repo@789012:environment:deploy"
      document_arn      = "arn:aws:ssm:fixture-region:123456789012:document/fixture-approved-deploy"
    }
  }
  assert {
    condition = (
      length(aws_iam_role.registry_publisher) == 1 && aws_iam_role.registry_publisher[0].name_prefix != aws_iam_role.github_deploy[0].name_prefix &&
      jsondecode(aws_iam_role.github_deploy[0].assume_role_policy).Statement[0].Condition.StringEquals["token.actions.githubusercontent.com:aud"] == "sts.amazonaws.com" &&
      jsondecode(aws_iam_role.github_deploy[0].assume_role_policy).Statement[0].Condition.StringEquals["token.actions.githubusercontent.com:sub"] == var.github_deployment_identity.subject &&
      length(local.github_deploy_policy.Statement) == 2 &&
      local.github_deploy_policy.Statement[0].Action == ["ssm:SendCommand"] &&
      length(local.github_deploy_policy.Statement[0].Resource) == 2 && local.github_deploy_policy.Statement[0].Resource[0] == var.github_deployment_identity.document_arn &&
      local.github_deploy_policy.Statement[1].Action == ["ssm:GetCommandInvocation"] &&
      local.github_deploy_policy.Statement[1].Condition.StringEquals["aws:RequestedRegion"] == var.aws_region
    )
    error_message = "Deployment must use exact audience/environment subject and only the selected SSM document/instance, with region-constrained invocation observation."
  }
}

run "reject_wildcard_deploy_subject" {
  command = plan
  variables {
    ec2_host = {
      ami_id          = "ami-0123456789abcdef0", ami_owner_account_id = "099720109477", architecture = "x86_64", upload_volume_gib = 1
      docker_versions = { engine = "5:29.8.2-1~ubuntu.24.04~noble", containerd = "2.2.4-1~ubuntu.24.04~noble", buildx = "0.32.1-1~ubuntu.24.04~noble", compose = "5.1.0-1~ubuntu.24.04~noble" }
    }
    github_deployment_identity = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner/fixture-repo:*"
      document_arn      = "arn:aws:ssm:fixture-region:123456789012:document/fixture-approved-deploy"
    }
  }
  expect_failures = [var.github_deployment_identity]
}

run "reject_unprotected_tag_or_pr_deployment_subject" {
  command = plan
  variables {
    ec2_host = {
      ami_id          = "ami-0123456789abcdef0", ami_owner_account_id = "099720109477", architecture = "x86_64", upload_volume_gib = 1
      docker_versions = { engine = "5:29.8.2-1~ubuntu.24.04~noble", containerd = "2.2.4-1~ubuntu.24.04~noble", buildx = "0.32.1-1~ubuntu.24.04~noble", compose = "5.1.0-1~ubuntu.24.04~noble" }
    }
    github_deployment_identity = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner@123456/fixture-repo@789012:ref:refs/tags/v1.0.0"
      document_arn      = "arn:aws:ssm:fixture-region:123456789012:document/fixture-approved-deploy"
    }
  }
  expect_failures = [var.github_deployment_identity]
}

run "reject_shared_aws_shell_document" {
  command = plan
  variables {
    ec2_host = {
      ami_id          = "ami-0123456789abcdef0", ami_owner_account_id = "099720109477", architecture = "x86_64", upload_volume_gib = 1
      docker_versions = { engine = "5:29.8.2-1~ubuntu.24.04~noble", containerd = "2.2.4-1~ubuntu.24.04~noble", buildx = "0.32.1-1~ubuntu.24.04~noble", compose = "5.1.0-1~ubuntu.24.04~noble" }
    }
    github_deployment_identity = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner@123456/fixture-repo@789012:environment:deploy"
      document_arn      = "arn:aws:ssm:fixture-region::document/AWS-RunShellScript"
    }
  }
  expect_failures = [var.github_deployment_identity]
}

run "reject_foreign_account_deployment_provider" {
  command = plan
  variables {
    ec2_host = {
      ami_id          = "ami-0123456789abcdef0", ami_owner_account_id = "099720109477", architecture = "x86_64", upload_volume_gib = 1
      docker_versions = { engine = "5:29.8.2-1~ubuntu.24.04~noble", containerd = "2.2.4-1~ubuntu.24.04~noble", buildx = "0.32.1-1~ubuntu.24.04~noble", compose = "5.1.0-1~ubuntu.24.04~noble" }
    }

    github_deployment_identity = {
      oidc_provider_arn = "arn:aws:iam::999999999999:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner@123456/fixture-repo@789012:environment:deploy"
      document_arn      = "arn:aws:ssm:fixture-region:123456789012:document/fixture-approved-deploy"
    }
  }
  expect_failures = [aws_iam_role.github_deploy]
}
run "reject_shared_publisher_deployment_subject" {
  command = plan
  variables {
    ec2_host = {
      ami_id          = "ami-0123456789abcdef0", ami_owner_account_id = "099720109477", architecture = "x86_64", upload_volume_gib = 1
      docker_versions = { engine = "5:29.8.2-1~ubuntu.24.04~noble", containerd = "2.2.4-1~ubuntu.24.04~noble", buildx = "0.32.1-1~ubuntu.24.04~noble", compose = "5.1.0-1~ubuntu.24.04~noble" }
    }

    container_registry = merge(var.container_registry, { publisher = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner@123456/fixture-repo@789012:environment:publisher"
    } })
    github_deployment_identity = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner@123456/fixture-repo@789012:environment:publisher"
      document_arn      = "arn:aws:ssm:fixture-region:123456789012:document/fixture-approved-deploy"
    }
  }
  expect_failures = [aws_iam_role.github_deploy]
}
