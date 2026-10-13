mock_provider "aws" {
  mock_data "aws_ami" {
    defaults = {
      architecture        = "x86_64"
      name                = "ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-fixture"
      root_device_type    = "ebs"
      virtualization_type = "hvm"
    }
  }
  mock_data "aws_ec2_instance_type" {
    defaults = { hypervisor = "nitro", supported_architectures = ["x86_64"] }
  }
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
  ec2_host = {
    ami_id                   = "ami-0123456789abcdef0"
    ami_owner_account_id     = "099720109477"
    architecture             = "x86_64"
    upload_volume_gib        = 1
    initialize_upload_volume = false
    docker_versions = {
      engine     = "5:29.8.2-1~ubuntu.24.04~noble"
      containerd = "2.2.4-1~ubuntu.24.04~noble"
      buildx     = "0.32.1-1~ubuntu.24.04~noble"
      compose    = "5.1.0-1~ubuntu.24.04~noble"
    }
  }

}

run "host_is_opt_in" {
  command = plan
  variables { ec2_host = null }
  assert {
    condition     = length(aws_instance.web) == 0 && length(aws_ebs_volume.uploads) == 0 && length(aws_iam_instance_profile.web) == 0
    error_message = "No EC2, persistent volume or instance identity may be planned without an explicit host selection."
  }
}

run "encrypted_managed_host" {
  command = plan
  assert {
    condition = (
      length(aws_instance.web) == 1 && aws_instance.web[0].associate_public_ip_address &&
      aws_instance.web[0].metadata_options[0].http_tokens == "required" &&
      aws_instance.web[0].metadata_options[0].http_put_response_hop_limit == 1 &&
      aws_instance.web[0].root_block_device[0].encrypted &&
      aws_instance.web[0].root_block_device[0].volume_size == var.compute.root_volume_gib &&
      aws_ebs_volume.uploads[0].encrypted && aws_ebs_volume.uploads[0].size == var.ec2_host.upload_volume_gib
    )
    error_message = "The explicit host must use encrypted operator-sized disks, IMDSv2, no SSH key and the real public-subnet egress path."
  }
}

run "reject_unpinned_ami" {
  command = plan
  variables { ec2_host = merge(var.ec2_host, { ami_id = "latest" }) }
  expect_failures = [var.ec2_host]
}

run "reject_shell_in_package_version" {
  command = plan
  variables { ec2_host = merge(var.ec2_host, { docker_versions = merge(var.ec2_host.docker_versions, { compose = "$(touch /tmp/untrusted)" }) }) }
  expect_failures = [var.ec2_host]
}

run "reject_incompatible_ami" {
  command = plan
  override_data {
    target = data.aws_ami.host[0]
    values = { architecture = "arm64", name = "unapproved-image", root_device_type = "ebs", virtualization_type = "hvm" }
  }
  expect_failures = [data.aws_ami.host]
}

run "reject_non_nitro_host" {
  command = plan
  override_data {
    target = data.aws_ec2_instance_type.host[0]
    values = { hypervisor = "xen", supported_architectures = ["x86_64"] }
  }
  expect_failures = [data.aws_ec2_instance_type.host]
}

run "reject_broad_instance_access" {
  command = plan
  variables { instance_access = { secret_arns = ["arn:aws:secretsmanager:fixture-region:123456789012:secret:*"] } }
  expect_failures = [var.instance_access]
}

run "minimum_management_only" {
  command = plan
  assert {
    condition = (length(jsondecode(aws_iam_role_policy.web[0].policy).Statement) == 1 &&
    toset(jsondecode(aws_iam_role_policy.web[0].policy).Statement[0].Action) == toset(["ssm:UpdateInstanceInformation", "ssmmessages:CreateControlChannel", "ssmmessages:CreateDataChannel", "ssmmessages:OpenControlChannel", "ssmmessages:OpenDataChannel"]))
    error_message = "An empty application access selection must grant only AWS's minimal Session Manager operations."
  }
}

run "selected_secret_and_image_access" {
  command = plan
  variables {
    instance_access = {
      ecr_repository_arns = ["arn:aws:ecr:fixture-region:123456789012:repository/fixture-php"]
      secret_arns         = ["arn:aws:secretsmanager:fixture-region:123456789012:secret:fixture-app-AbCdEf"]
      secret_kms_key_arns = ["arn:aws:kms:fixture-region:123456789012:key/12345678-1234-1234-1234-123456789abc"]
    }
  }
  assert {
    condition = (
      length(jsondecode(aws_iam_role_policy.web[0].policy).Statement) == 5 &&
      toset(jsondecode(aws_iam_role_policy.web[0].policy).Statement[2].Resource) == var.instance_access.ecr_repository_arns &&
      toset(jsondecode(aws_iam_role_policy.web[0].policy).Statement[3].Resource) == var.instance_access.secret_arns &&
      toset(jsondecode(aws_iam_role_policy.web[0].policy).Statement[4].Resource) == var.instance_access.secret_kms_key_arns &&
      jsondecode(aws_iam_role_policy.web[0].policy).Statement[4].Condition.StringEquals["kms:ViaService"] == "secretsmanager.fixture-region.amazonaws.com"
    )
    error_message = "Application IAM must use exact selected resources and constrain KMS decryption to Secrets Manager."
  }
}

run "snapshots_are_opt_in" {
  command = plan
  assert {
    condition     = length(aws_dlm_lifecycle_policy.uploads) == 0 && length(aws_iam_role.upload_snapshots) == 0
    error_message = "No snapshot cadence or retention may be invented for the operator."
  }
}

run "operator_selected_snapshots" {
  command = plan
  variables { ec2_host = merge(var.ec2_host, { snapshots = { interval_hours = 24, start_time_utc = "01:00", retain_count = 2 } }) }
  assert {
    condition = (
      aws_dlm_lifecycle_policy.uploads[0].state == "ENABLED" &&
      aws_dlm_lifecycle_policy.uploads[0].policy_details[0].target_tags == tomap({ TemplateUploads = var.environment }) &&
      one(aws_dlm_lifecycle_policy.uploads[0].policy_details[0].schedule).create_rule[0].interval == 24 &&
      one(aws_dlm_lifecycle_policy.uploads[0].policy_details[0].schedule).retain_rule[0].count == 2
    )
    error_message = "Snapshot plans must retain the operator's explicit cadence/count and target only this environment's upload volume tag."
  }
}

run "reject_zero_snapshot_retention" {
  command = plan
  variables { ec2_host = merge(var.ec2_host, { snapshots = { interval_hours = 24, start_time_utc = "01:00", retain_count = 0 } }) }
  expect_failures = [var.ec2_host]
}

run "reject_formatting_a_restore" {
  command = plan
  variables { ec2_host = merge(var.ec2_host, { upload_snapshot_id = "snap-0123456789abcdef0", initialize_upload_volume = true }) }
  expect_failures = [var.ec2_host]
}
