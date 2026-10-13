data "aws_rds_engine_version" "selected" {
  count                  = var.rds == null ? 0 : 1
  engine                 = "postgres"
  version                = var.rds.engine_version
  parameter_group_family = "postgres16"
}

data "aws_rds_orderable_db_instance" "selected" {
  count                       = var.rds == null ? 0 : 1
  engine                      = "postgres"
  engine_version              = var.rds.engine_version
  instance_class              = var.database.instance_class
  storage_type                = "gp3"
  vpc                         = true
  supports_storage_encryption = true
}

data "aws_rds_certificate" "selected" {
  count = var.rds == null ? 0 : 1
  id    = var.rds.ca_cert_identifier
}

resource "aws_db_subnet_group" "application" {
  count       = var.rds == null ? 0 : 1
  name_prefix = "${var.environment}-database-"
  description = "Private PostgreSQL subnets in at least two AZs; instance remains Single-AZ"
  subnet_ids  = [for subnet in aws_subnet.database : subnet.id]
  tags        = local.network_tags
}

resource "aws_db_parameter_group" "application" {
  count       = var.rds == null ? 0 : 1
  name_prefix = "${var.environment}-postgres16-"
  family      = "postgres16"
  description = "Require TLS and avoid SQL statement logging"
  parameter {
    name         = "rds.force_ssl"
    value        = "1"
    apply_method = "pending-reboot"
  }
  parameter {
    name  = "log_statement"
    value = "none"
  }
  parameter {
    name  = "log_min_error_statement"
    value = "panic"
  }
  dynamic "parameter" {
    for_each = var.monitoring == null ? [] : [var.monitoring.rds_log_retention_minutes]
    content {
      name  = "rds.log_retention_period"
      value = tostring(parameter.value)
    }
  }
  tags = local.network_tags
  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_db_instance" "application" {
  count                           = var.rds == null ? 0 : 1
  identifier                      = var.rds.identifier
  engine                          = "postgres"
  engine_version                  = var.rds.engine_version
  instance_class                  = var.database.instance_class
  db_name                         = var.rds.database_name
  username                        = var.rds.master_username
  manage_master_user_password     = true
  master_user_secret_kms_key_id   = var.rds.admin_secret_kms_key_arn
  storage_type                    = "gp3"
  allocated_storage               = var.database.allocated_storage_gib
  max_allocated_storage           = var.rds.max_allocated_storage_gib
  storage_encrypted               = true
  kms_key_id                      = var.rds.storage_kms_key_arn
  publicly_accessible             = false
  multi_az                        = false
  availability_zone               = var.rds.availability_zone
  db_subnet_group_name            = aws_db_subnet_group.application[0].name
  vpc_security_group_ids          = [aws_security_group.database.id]
  parameter_group_name            = aws_db_parameter_group.application[0].name
  ca_cert_identifier              = var.rds.ca_cert_identifier
  backup_retention_period         = var.database.backup_retention_days
  backup_window                   = var.rds.backup_window
  maintenance_window              = var.rds.maintenance_window
  auto_minor_version_upgrade      = var.rds.auto_minor_version_upgrade
  allow_major_version_upgrade     = false
  apply_immediately               = false
  deletion_protection             = var.rds.deletion_protection
  skip_final_snapshot             = false
  final_snapshot_identifier       = var.rds.final_snapshot_identifier
  delete_automated_backups        = false
  copy_tags_to_snapshot           = true
  enabled_cloudwatch_logs_exports = []
  tags                            = local.network_tags

  lifecycle {
    prevent_destroy = true
    precondition {
      condition     = data.aws_rds_engine_version.selected[0].version_actual == var.rds.engine_version
      error_message = "The explicitly selected PostgreSQL version must resolve exactly in the chosen AWS region."
    }
    precondition {
      condition = (
        var.database.allocated_storage_gib >= data.aws_rds_orderable_db_instance.selected[0].min_storage_size &&
        var.database.allocated_storage_gib <= data.aws_rds_orderable_db_instance.selected[0].max_storage_size &&
        var.rds.max_allocated_storage_gib <= data.aws_rds_orderable_db_instance.selected[0].max_storage_size &&
        contains(data.aws_rds_orderable_db_instance.selected[0].availability_zones, var.rds.availability_zone)
      )
      error_message = "Selected class/version/gp3 storage limits and private AZ must be available in the chosen AWS region."
    }
    precondition {
      condition     = data.aws_rds_certificate.selected[0].certificate_type == "CA"
      error_message = "The selected RDS certificate must resolve to a regional certificate authority."
    }
  }
}

output "database_connection" {
  description = "Non-secret runtime contract. Create the application role separately before using it; never connect the service with master credentials."
  value = var.rds == null ? null : {
    host               = aws_db_instance.application[0].address
    port               = 5432
    database           = var.rds.database_name
    username           = var.rds.application_username
    sslmode            = "verify-full"
    ca_cert_identifier = var.rds.ca_cert_identifier
  }
}

output "database_administrator_secret_arn" {
  description = "Managed administrator secret identifier for the separately authorized operator; never grant it to the application host. No secret value is read by Terraform."
  value       = var.rds == null ? null : aws_db_instance.application[0].master_user_secret[0].secret_arn
}
