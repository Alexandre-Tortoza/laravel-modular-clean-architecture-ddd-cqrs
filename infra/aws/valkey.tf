variable "valkey" {
  description = "Optional single private Valkey primary. Existing VALKEY RBAC group/password user must be reviewed by the named owner outside Terraform; no credential values belong here. Null allocates nothing."
  type = object({
    identifier              = string
    node_type               = string
    engine_version          = string
    parameter_group_family  = string
    availability_zone       = string
    user_group_id           = string
    user_id                 = string
    application_username    = string
    authentication_method   = string
    authentication_owner    = string
    snapshot_retention_days = number
    maintenance_window      = string
  })
  default = null
  validation {
    condition = var.valkey == null ? true : (
      can(regex("^[a-z][a-z0-9-]{0,39}$", var.valkey.identifier)) &&
      !strcontains(var.valkey.identifier, "--") && !endswith(var.valkey.identifier, "-") &&
      can(regex("^cache\\.[a-z0-9]+\\.[a-z0-9]+$", var.valkey.node_type)) &&
      can(regex("^(7\\.[2-9]|[89]\\.[0-9]+|[1-9][0-9]+\\.[0-9]+)$", var.valkey.engine_version)) &&
      var.valkey.parameter_group_family == "valkey${split(".", var.valkey.engine_version)[0]}" &&
      contains([for subnet in var.network.private_subnets : subnet.availability_zone], var.valkey.availability_zone) &&
      alltrue([for id in [var.valkey.user_group_id, var.valkey.user_id] : can(regex("^[a-zA-Z][a-zA-Z0-9-]{0,39}$", id))]) &&
      can(regex("^[a-zA-Z][a-zA-Z0-9_-]{0,127}$", var.valkey.application_username)) &&
      var.valkey.application_username != "default" && trimspace(var.valkey.authentication_owner) != "" &&
      var.valkey.authentication_method == "password" &&
      var.valkey.snapshot_retention_days >= 0 && var.valkey.snapshot_retention_days <= 35 && floor(var.valkey.snapshot_retention_days) == var.valkey.snapshot_retention_days &&
      can(regex("^(mon|tue|wed|thu|fri|sat|sun):[0-2][0-9]:[0-5][0-9]-(mon|tue|wed|thu|fri|sat|sun):[0-2][0-9]:[0-5][0-9]$", var.valkey.maintenance_window))
    )
    error_message = "Select explicit Valkey version/family/capacity/private AZ, approved RBAC identities/owner, 0–35 snapshot days and a maintenance window; no cluster mode or default unauthenticated user."
  }
}

resource "aws_elasticache_subnet_group" "application" {
  count       = var.valkey == null ? 0 : 1
  name        = "${var.environment}-${var.valkey.identifier}"
  description = "Optional Valkey in the existing private application subnets"
  subnet_ids  = [for subnet in aws_subnet.database : subnet.id]
  tags        = local.network_tags
}

resource "aws_security_group" "valkey" {
  count       = var.valkey == null ? 0 : 1
  name_prefix = "${var.environment}-valkey-"
  description = "TLS Valkey from the EC2 application security group only"
  vpc_id      = aws_vpc.reference.id
  tags        = local.network_tags
}

resource "aws_vpc_security_group_ingress_rule" "valkey" {
  count                        = var.valkey == null ? 0 : 1
  security_group_id            = aws_security_group.valkey[0].id
  referenced_security_group_id = aws_security_group.web.id
  ip_protocol                  = "tcp"
  from_port                    = 6379
  to_port                      = 6379
  tags                         = local.network_tags
}

resource "aws_vpc_security_group_egress_rule" "valkey" {
  count                        = var.valkey == null ? 0 : 1
  security_group_id            = aws_security_group.web.id
  referenced_security_group_id = aws_security_group.valkey[0].id
  ip_protocol                  = "tcp"
  from_port                    = 6379
  to_port                      = 6379
  tags                         = local.network_tags
}

# This AWS API resource supports RBAC/TLS with one primary; count=1 does not
# create a replica, shard, global group or a RedisCluster client requirement.
resource "aws_elasticache_replication_group" "application" {
  count                       = var.valkey == null ? 0 : 1
  replication_group_id        = var.valkey.identifier
  description                 = "Optional single private Valkey primary; no HA guarantee"
  engine                      = "valkey"
  engine_version              = var.valkey.engine_version
  node_type                   = var.valkey.node_type
  parameter_group_name        = "default.${var.valkey.parameter_group_family}"
  cluster_mode                = "disabled"
  num_cache_clusters          = 1
  automatic_failover_enabled  = false
  multi_az_enabled            = false
  preferred_cache_cluster_azs = [var.valkey.availability_zone]
  subnet_group_name           = aws_elasticache_subnet_group.application[0].name
  security_group_ids          = [aws_security_group.valkey[0].id]
  port                        = 6379
  at_rest_encryption_enabled  = true
  transit_encryption_enabled  = true
  transit_encryption_mode     = "required"
  user_group_ids              = [var.valkey.user_group_id]
  snapshot_retention_limit    = var.valkey.snapshot_retention_days
  maintenance_window          = var.valkey.maintenance_window
  auto_minor_version_upgrade  = true
  apply_immediately           = false
  tags                        = local.network_tags
  lifecycle {
    prevent_destroy = true
    precondition {
      condition     = var.ec2_host != null && var.rds != null && var.runtime_secrets != null
      error_message = "Optional Valkey requires the approved private EC2/RDS/runtime-secret contract."
    }
  }
}

output "valkey_connection" {
  description = "Public endpoint metadata only, no password or automatic driver switch. RBAC engine/membership/auth and regional capacity need approved operator preflight."
  value = var.valkey == null ? null : {
    engine                 = "valkey"
    host                   = aws_elasticache_replication_group.application[0].primary_endpoint_address
    port                   = 6379
    tls_required           = true
    username               = var.valkey.application_username
    authentication         = "preexisting VALKEY RBAC group and operator-managed password in application secret"
    authentication_owner   = var.valkey.authentication_owner
    user_group_id          = var.valkey.user_group_id
    user_id                = var.valkey.user_id
    driver_defaults        = { cache = "database", session = "database", queue = "database" }
    pricing_reference      = "https://aws.amazon.com/elasticache/pricing/"
    availability_statement = "one primary, no replicas or failover; do not assume durable sessions/jobs"
  }
}
