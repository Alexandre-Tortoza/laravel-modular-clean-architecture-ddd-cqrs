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

run "default_has_no_deployment_document" {
  command = plan
  assert {
    condition     = length(aws_ssm_document.controlled_release) == 0
    error_message = "Default infrastructure must never provision a deployment command."
  }
}
run "reviewed_deployment_document_has_bound_runtime_only" {
  command = plan
  variables { controlled_deployment = {
    document_name             = "fixture-approved-deploy", project = "fixture"
    tls_domain                = "fixture.invalid", tls_certificate_mechanism = "provided"
    tls_certificate_directory = "/srv/laravel/tls", tls_acme_webroot = "/srv/laravel/acme", tls_bind_ipv4 = "127.0.0.1"
    tls_ca_bundle             = "", database_ca_bundle = "/srv/laravel/rds.pem"
  } }
  assert {
    condition = (
      length(aws_ssm_document.controlled_release) == 1 &&
      aws_ssm_document.controlled_release[0].document_type == "Command" &&
      local.deployment_document.parameters.Release.interpolationType == "ENV_VAR" &&
      local.deployment_document.parameters.Release.allowedPattern == "^[A-Za-z0-9+/=]{1,8192}$" &&
      local.deployment_contract.compose_environment.RUNTIME_ENV_FILE == "/srv/laravel/runtime/application.env" &&
      local.deployment_contract.compose_environment.UPLOAD_DIRECTORY == "/srv/laravel/uploads" &&
      local.deployment_document.parameters.Operation.default == "deploy" &&
      local.deployment_document.parameters.Operation.allowedPattern == "^(deploy|rollback)$" &&
      local.deployment_document.parameters.Operation.interpolationType == "ENV_VAR" &&
      local.deployment_contract.rollback_runner_sha256 == filesha256("scripts/rollback_release.py") &&
      local.deployment_contract.runner_sha256 == filesha256("scripts/deploy_release.py") &&
      local.deployment_contract.compose_sha256 == filesha256("../../compose.production.release.yaml") &&
      length(jsonencode(local.deployment_document)) < 65536 &&
      length(local.deployment_document.mainSteps) == 1
    )
    error_message = "Reviewed command must bind the host runner/Compose sources, private env and retained upload paths with safe ENV_VAR input."
  }
}
run "foreign_document_fails_before_plan" {
  command = plan
  variables {
    controlled_deployment = {
      document_name             = "fixture-approved-deploy", project = "fixture"
      tls_domain                = "fixture.invalid", tls_certificate_mechanism = "provided"
      tls_certificate_directory = "/srv/laravel/tls", tls_acme_webroot = "/srv/laravel/acme", tls_bind_ipv4 = "127.0.0.1"
      tls_ca_bundle             = "", database_ca_bundle = "/srv/laravel/rds.pem"
    }
    github_deployment_identity = {
      oidc_provider_arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
      subject           = "repo:fixture-owner@123456/fixture-repo@789012:environment:deploy"
      document_arn      = "arn:aws:ssm:fixture-region:123456789012:document/other-reviewed-document"
    }
  }
  expect_failures = [aws_ssm_document.controlled_release]
}
run "unapproved_path_and_domain_fail" {
  command = plan
  variables { controlled_deployment = merge({
    document_name             = "fixture-approved-deploy", project = "fixture"
    tls_domain                = "fixture.invalid", tls_certificate_mechanism = "provided"
    tls_certificate_directory = "/srv/laravel/tls", tls_acme_webroot = "/srv/laravel/acme", tls_bind_ipv4 = "127.0.0.1"
    tls_ca_bundle             = "", database_ca_bundle = "/srv/laravel/rds.pem"
  }, { tls_domain = "https://fixture.invalid", tls_certificate_directory = "/etc/../private" }) }
  expect_failures = [var.controlled_deployment]
}
