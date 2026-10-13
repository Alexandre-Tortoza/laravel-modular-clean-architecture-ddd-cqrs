variable "aws_region" {
  description = "Explicit operator-selected region for the state bucket."
  type        = string
  nullable    = false
  validation {
    condition     = trimspace(var.aws_region) != ""
    error_message = "Select the state bucket region explicitly."
  }
}

variable "aws_partition" {
  description = "Explicit ARN partition for the selected region; used for reviewed S3/role policy ARNs."
  type        = string
  nullable    = false
  validation {
    condition     = can(regex("^aws(-[a-z]+)*$", var.aws_partition))
    error_message = "Supply the AWS partition explicitly."
  }
}

variable "state_bucket_name" {
  description = "Explicit globally unique state bucket name; this contract does not discover or reserve a name."
  type        = string
  nullable    = false
  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$", var.state_bucket_name))
    error_message = "Supply a 3–63 character lowercase bucket name using letters, digits and hyphens."
  }
}

variable "environment" {
  description = "Explicit environment identifier; its own key and IAM path isolate the application state."
  type        = string
  nullable    = false
  validation {
    condition     = can(regex("^[a-z][a-z0-9-]*$", var.environment))
    error_message = "Use a lowercase environment identifier without path separators."
  }
}

variable "approved_principal_arns" {
  description = "Explicit IAM role ARNs allowed by the bucket guard, including the bootstrap operator/recovery role and separately scoped state writers. No roles are created or granted access here."
  type        = set(string)
  nullable    = false
  validation {
    condition = length(var.approved_principal_arns) > 0 && alltrue([
      for arn in var.approved_principal_arns : can(regex("^arn:${var.aws_partition}:iam::[0-9]{12}:role/[^*?]+$", arn))
    ])
    error_message = "Approve at least one concrete IAM role ARN in the selected partition; wildcard actors are not allowed."
  }
}
