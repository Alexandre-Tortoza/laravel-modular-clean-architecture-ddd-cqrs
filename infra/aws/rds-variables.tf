variable "rds" {
  description = "Explicit opt-in RDS configuration; null selects no database. No passwords or secret values are accepted."
  type = object({
    identifier                 = string
    engine_version             = string
    availability_zone          = string
    database_name              = string
    master_username            = string
    application_username       = string
    storage_kms_key_arn        = string
    admin_secret_kms_key_arn   = string
    ca_cert_identifier         = string
    deletion_protection        = bool
    final_snapshot_identifier  = string
    max_allocated_storage_gib  = number
    auto_minor_version_upgrade = bool
    backup_window              = string
    maintenance_window         = string
  })
  default = null

  validation {
    condition = try(var.rds == null ? true : (
      var.rds.deletion_protection != null && var.rds.auto_minor_version_upgrade != null &&
      can(regex("^16\\.[0-9]+(-R[0-9]+)?$", var.rds.engine_version)) &&
      can(regex("^[a-z][a-z0-9-]{0,62}$", var.rds.identifier)) &&
      !endswith(var.rds.identifier, "-") && !strcontains(var.rds.identifier, "--") &&
      can(regex("^[a-z][a-z0-9-]{0,254}$", var.rds.final_snapshot_identifier)) &&
      !endswith(var.rds.final_snapshot_identifier, "-") && !strcontains(var.rds.final_snapshot_identifier, "--") &&
      can(regex("^[a-z][a-z0-9_]{0,62}$", var.rds.database_name)) &&
      can(regex("^[a-z][a-z0-9_]{0,15}$", var.rds.master_username)) &&
      can(regex("^[a-z][a-z0-9_]{0,62}$", var.rds.application_username)) &&
      var.rds.master_username != var.rds.application_username &&
      !contains(["postgres", "rdsadmin", "rds_superuser"], var.rds.application_username) &&
      !contains(["rdsadmin", "rds_superuser"], var.rds.master_username)
    ), false)
    error_message = "Select a full PostgreSQL 16 version and valid identifiers, with a separate non-administrative application role and mandatory final snapshot name."
  }
  validation {
    condition = try(var.rds == null ? true : (
      contains([for subnet in var.network.private_subnets : subnet.availability_zone], var.rds.availability_zone) &&
      floor(var.rds.max_allocated_storage_gib) == var.rds.max_allocated_storage_gib &&
      (var.rds.max_allocated_storage_gib == 0 || var.rds.max_allocated_storage_gib >= var.database.allocated_storage_gib)
    ), false)
    error_message = "Select a private database AZ and an explicit whole-number storage ceiling: zero disables autoscaling, otherwise it must cover initial storage."
  }
  validation {
    condition = var.rds == null ? true : alltrue([
      for arn in [var.rds.storage_kms_key_arn, var.rds.admin_secret_kms_key_arn] :
      can(regex("^arn:(aws|aws-us-gov|aws-cn):kms:${var.aws_region}:[0-9]{12}:key/[a-zA-Z0-9-]+$", arn))
    ])
    error_message = "Select explicit regional KMS key ARNs for encrypted database storage and the managed administrator secret."
  }
  validation {
    condition = var.rds == null ? true : (
      contains(["rds-ca-rsa2048-g1", "rds-ca-rsa4096-g1", "rds-ca-ecc384-g1"], var.rds.ca_cert_identifier) &&
      can(regex("^([01][0-9]|2[0-3]):[0-5][0-9]-([01][0-9]|2[0-3]):[0-5][0-9]$", var.rds.backup_window)) &&
      can(regex("^(mon|tue|wed|thu|fri|sat|sun):([01][0-9]|2[0-3]):[0-5][0-9]-(mon|tue|wed|thu|fri|sat|sun):([01][0-9]|2[0-3]):[0-5][0-9]$", var.rds.maintenance_window))
    )
    error_message = "Select a current RDS CA and explicit UTC backup/maintenance windows; availability and window overlap require operator review."
  }
}
