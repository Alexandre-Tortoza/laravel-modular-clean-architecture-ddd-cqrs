variable "runtime_secrets" {
  description = "Opt-in existing Secrets Manager application credential. Identifiers only: an approved operator creates/rotates values outside Terraform. Null grants no additional read access."
  type = object({
    application_secret_arn = string
    kms_key_arn            = string
    database_username      = string
    rotation_owner         = string
  })
  default = null
  validation {
    condition = var.runtime_secrets == null ? true : (
      can(regex("^arn:aws:secretsmanager:${var.aws_region}:[0-9]{12}:secret:[A-Za-z0-9/_+=.@-]+-[A-Za-z0-9]{6}$", var.runtime_secrets.application_secret_arn)) &&
      can(regex("^arn:aws:kms:${var.aws_region}:[0-9]{12}:key/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$", var.runtime_secrets.kms_key_arn)) &&
      split(":", var.runtime_secrets.application_secret_arn)[4] == split(":", var.runtime_secrets.kms_key_arn)[4] &&
      can(regex("^[a-z][a-z0-9_]{0,62}$", var.runtime_secrets.database_username)) &&
      !contains(["postgres", "rdsadmin", "rds_superuser"], var.runtime_secrets.database_username) &&
      length(trimspace(var.runtime_secrets.rotation_owner)) > 0
    )
    error_message = "Select exact same-account regional application secret/KMS ARNs, a non-administrative database role and responsible rotation owner; never supply credential values."
  }
}

data "aws_caller_identity" "runtime_secrets" {
  count = var.runtime_secrets == null ? 0 : 1
}

# GetSecretValue never runs during Terraform. Even another identity policy may
# not grant this host administrator/other-secret reads while this boundary is selected.
resource "aws_iam_role_policy" "application_secrets" {
  count = var.runtime_secrets == null ? 0 : 1
  role  = try(aws_iam_role.web[0].id, "missing-host")
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Sid = "ReadApplicationCredential", Effect = "Allow", Action = ["secretsmanager:GetSecretValue"], Resource = [var.runtime_secrets.application_secret_arn] },
      { Sid = "DenyOtherSecretReads", Effect = "Deny", Action = ["secretsmanager:GetSecretValue"], NotResource = [var.runtime_secrets.application_secret_arn] },
      {
        Sid = "DecryptApplicationCredential", Effect = "Allow", Action = ["kms:Decrypt"], Resource = [var.runtime_secrets.kms_key_arn]
        Condition = { StringEquals = {
          "kms:ViaService"                  = "secretsmanager.${var.aws_region}.amazonaws.com"
          "kms:EncryptionContext:SecretARN" = var.runtime_secrets.application_secret_arn
        } }
      }
    ]
  })
  lifecycle {
    precondition {
      condition     = split(":", var.runtime_secrets.application_secret_arn)[4] == data.aws_caller_identity.runtime_secrets[0].account_id
      error_message = "Runtime secret/key must belong to this explicitly selected AWS account."
    }
    precondition {
      condition     = var.ec2_host != null && var.rds != null
      error_message = "Runtime secrets require the explicitly selected EC2 host and private RDS contract."
    }
    precondition {
      condition     = var.rds == null ? true : var.runtime_secrets.database_username == var.rds.application_username
      error_message = "Secret schema must select the RDS application role, never the administrator."
    }
    precondition {
      condition     = var.rds == null ? true : var.runtime_secrets.application_secret_arn != aws_db_instance.application[0].master_user_secret[0].secret_arn
      error_message = "The host cannot receive the RDS-managed administrator secret. This metadata relationship is also checked when AWS resolves its ARN."
    }
    precondition {
      condition = (
        length(setsubtract(var.instance_access.secret_arns, [var.runtime_secrets.application_secret_arn])) == 0 &&
        length(setsubtract(var.instance_access.secret_kms_key_arns, [var.runtime_secrets.kms_key_arn])) == 0
      )
      error_message = "Remove legacy grants to other secrets/keys before selecting the application runtime boundary."
    }
  }
}

output "runtime_secret_delivery" {
  description = "Non-secret host metadata only. Existing managed values remain outside Terraform, images and bootstrap; reads/restarts require separate explicit operator actions."
  value = var.runtime_secrets == null ? null : {
    service           = "Secrets Manager"
    secret_arn        = var.runtime_secrets.application_secret_arn
    version_stage     = "AWSCURRENT"
    region            = var.aws_region
    database_username = var.runtime_secrets.database_username
    rotation_owner    = var.runtime_secrets.rotation_owner
    pricing_reference = "https://aws.amazon.com/secrets-manager/pricing/"
    rotation_policy   = "operator-reviewed database credential rotation; APP_KEY stable; no automatic secret read, rotation or restart"
  }
}
