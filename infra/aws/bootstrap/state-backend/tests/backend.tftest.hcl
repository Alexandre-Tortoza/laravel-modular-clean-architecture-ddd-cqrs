mock_provider "aws" {}

variables {
  aws_region              = "fixture-region"
  aws_partition           = "aws"
  state_bucket_name       = "fixture-state-bucket"
  environment             = "fixture"
  approved_principal_arns = ["arn:aws:iam::111111111111:role/fixture-state-operator"]
}

run "secure_state_bucket" {
  command = plan
  assert {
    condition = (
      !aws_s3_bucket.state.force_destroy &&
      aws_s3_bucket_versioning.state.versioning_configuration[0].status == "Enabled" &&
      one(aws_s3_bucket_server_side_encryption_configuration.state.rule).apply_server_side_encryption_by_default[0].sse_algorithm == "AES256" &&
      aws_s3_bucket_public_access_block.state.block_public_acls &&
      aws_s3_bucket_public_access_block.state.block_public_policy &&
      aws_s3_bucket_public_access_block.state.ignore_public_acls &&
      aws_s3_bucket_public_access_block.state.restrict_public_buckets &&
      aws_s3_bucket_ownership_controls.state.rule[0].object_ownership == "BucketOwnerEnforced"
    )
    error_message = "The separately bootstrapped state bucket must preserve versions, encrypt data, block public access and avoid destructive cleanup."
  }
  assert {
    condition = (
      jsondecode(aws_s3_bucket_policy.state.policy).Statement[0].Condition.Bool["aws:SecureTransport"] == "false" &&
      toset(jsondecode(aws_s3_bucket_policy.state.policy).Statement[1].Condition.ArnNotEquals["aws:PrincipalArn"]) == var.approved_principal_arns
    )
    error_message = "Bucket policy must deny insecure transport and actors outside the explicitly approved roles."
  }
  assert {
    condition = (
      output.backend_configuration.key == "${var.environment}/terraform.tfstate" &&
      output.backend_configuration.use_lockfile &&
      output.backend_configuration.encrypt &&
      output.backend_configuration.region == var.aws_region &&
      !contains(keys(output.backend_configuration), "dynamodb_table")
    )
    error_message = "Backend configuration must isolate state by environment and use S3 native locking with encryption and explicit region."
  }
  assert {
    condition = (
      tolist(jsondecode(output.state_writer_policy).Statement[1].Action) == tolist(["s3:GetObject", "s3:PutObject"]) &&
      tolist(jsondecode(output.state_writer_policy).Statement[2].Action) == tolist(["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]) &&
      endswith(jsondecode(output.state_writer_policy).Statement[2].Resource, ".tflock")
    )
    error_message = "Writer access must scope delete to the lockfile, not to state objects."
  }
}

run "reject_missing_approved_actor" {
  command = plan
  variables { approved_principal_arns = [] }
  expect_failures = [var.approved_principal_arns]
}

run "reject_wildcard_actor" {
  command = plan
  variables { approved_principal_arns = ["*"] }
  expect_failures = [var.approved_principal_arns]
}

run "reject_ambiguous_environment" {
  command = plan
  variables { environment = "../fixture" }
  expect_failures = [var.environment]
}

run "reject_missing_bucket_name" {
  command = plan
  variables { state_bucket_name = " " }
  expect_failures = [var.state_bucket_name]
}

run "separate_environment_state_and_permissions" {
  command = plan
  variables { environment = "fixture-second" }
  assert {
    condition = (
      output.backend_configuration.key != run.secure_state_bucket.backend_configuration.key &&
      jsondecode(output.state_writer_policy).Statement[1].Resource == "arn:aws:s3:::fixture-state-bucket/fixture-second/terraform.tfstate" &&
      jsondecode(output.state_writer_policy).Statement[2].Resource == "arn:aws:s3:::fixture-state-bucket/fixture-second/terraform.tfstate.tflock"
    )
    error_message = "Changing the environment must isolate both the state key and writer permissions, including the lockfile."
  }
}
