variable "ec2_host" {
  description = "Opt-in Ubuntu 24.04 EC2 host. Select an immutable regional AMI, architecture, upload capacity and every Docker APT version explicitly. Null plans no host."
  type = object({
    ami_id                   = string
    ami_owner_account_id     = string
    architecture             = string
    upload_volume_gib        = number
    initialize_upload_volume = optional(bool, false)
    upload_snapshot_id       = optional(string)
    docker_versions = object({
      engine     = string
      containerd = string
      buildx     = string
      compose    = string
    })
    snapshots = optional(object({
      interval_hours = number
      start_time_utc = string
      retain_count   = number
    }))
  })
  default = null
  validation {
    condition = var.ec2_host == null ? true : (
      can(regex("^ami-[0-9a-f]{17}$", var.ec2_host.ami_id)) &&
      can(regex("^[0-9]{12}$", var.ec2_host.ami_owner_account_id)) &&
      contains(["x86_64", "arm64"], var.ec2_host.architecture) &&
      var.ec2_host.upload_volume_gib >= 1 && floor(var.ec2_host.upload_volume_gib) == var.ec2_host.upload_volume_gib &&
      (var.ec2_host.upload_snapshot_id == null ? true : can(regex("^snap-[0-9a-f]{17}$", var.ec2_host.upload_snapshot_id)) && !var.ec2_host.initialize_upload_volume)
    )
    error_message = "Select a full AMI ID, approved owner account, x86_64/arm64, whole-number upload capacity and a full snapshot ID when restoring. Restores cannot authorize formatting."
  }
  validation {
    condition = var.ec2_host == null ? true : alltrue([
      for version in values(var.ec2_host.docker_versions) : can(regex("^[0-9][0-9A-Za-z.+:~_-]*~ubuntu\\.24\\.04~noble$", version))
    ])
    error_message = "Pin all five Docker packages to explicit Ubuntu 24.04 noble APT versions; spaces, shell syntax and latest are forbidden. Engine and CLI share one version."
  }
  validation {
    condition = var.ec2_host == null ? true : (var.ec2_host.snapshots == null ? true : (
      contains([1, 2, 3, 4, 6, 8, 12, 24], var.ec2_host.snapshots.interval_hours) &&
      can(regex("^([01][0-9]|2[0-3]):[0-5][0-9]$", var.ec2_host.snapshots.start_time_utc)) &&
      var.ec2_host.snapshots.retain_count >= 1 && var.ec2_host.snapshots.retain_count <= 1000 && floor(var.ec2_host.snapshots.retain_count) == var.ec2_host.snapshots.retain_count
    ))
    error_message = "Opt-in upload snapshots require a supported hourly interval, explicit UTC HH:MM and 1–1000 whole retained snapshots."
  }
}

variable "instance_access" {
  description = "Exact ECR repository, Secrets Manager secret and secret KMS key ARNs allowed to this host; these are identifiers, never secret values. Empty sets grant no application access."
  type = object({
    ecr_repository_arns = optional(set(string), [])
    secret_arns         = optional(set(string), [])
    secret_kms_key_arns = optional(set(string), [])
  })
  default  = {}
  nullable = false
  validation {
    condition = (
      alltrue([for arn in var.instance_access.ecr_repository_arns : can(regex("^arn:aws:ecr:[a-z0-9-]+:[0-9]{12}:repository/[a-z0-9][a-z0-9._/-]*$", arn))]) &&
      alltrue([for arn in var.instance_access.secret_arns : can(regex("^arn:aws:secretsmanager:[a-z0-9-]+:[0-9]{12}:secret:[A-Za-z0-9/_+=.@-]+$", arn))]) &&
      alltrue([for arn in var.instance_access.secret_kms_key_arns : can(regex("^arn:aws:kms:[a-z0-9-]+:[0-9]{12}:key/[0-9a-f-]+$", arn))]) &&
      (length(var.instance_access.secret_kms_key_arns) == 0 || length(var.instance_access.secret_arns) > 0)
    )
    error_message = "Supply exact resource ARNs without wildcards; secret KMS decryption requires at least one selected secret."
  }
}
