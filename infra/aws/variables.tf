variable "aws_region" {
  description = "Operator-selected AWS region; availability and prices must be reviewed there."
  type        = string
  nullable    = false
  validation {
    condition     = trimspace(var.aws_region) != ""
    error_message = "Select an AWS region explicitly."
  }
}

variable "environment" {
  description = "Operator-selected environment identifier used to isolate future resources and state."
  type        = string
  nullable    = false
  validation {
    condition     = can(regex("^[a-z][a-z0-9-]*$", var.environment))
    error_message = "Use a lowercase environment identifier beginning with a letter."
  }
}

variable "domain_name" {
  description = "Operator-owned hostname for the web entry point; this contract does not create DNS records or certificates."
  type        = string
  nullable    = false
  validation {
    condition     = trimspace(var.domain_name) != "" && !strcontains(var.domain_name, "://")
    error_message = "Supply an explicit hostname, without a URL scheme."
  }
}

variable "network" {
  description = "Explicit VPC/public EC2 subnet and private RDS subnets in at least two distinct AZs. No subnet or AZ is inferred."
  type = object({
    vpc_cidr           = string
    public_subnet_cidr = string
    public_subnet_az   = string
    private_subnets = list(object({
      cidr              = string
      availability_zone = string
    }))
  })
  nullable = false
  validation {
    condition = (
      can(cidrnetmask(var.network.vpc_cidr)) &&
      can(cidrnetmask(var.network.public_subnet_cidr)) &&
      trimspace(var.network.public_subnet_az) != "" &&
      length(distinct([for subnet in var.network.private_subnets : subnet.availability_zone])) >= 2 &&
      alltrue([for subnet in var.network.private_subnets : can(cidrnetmask(subnet.cidr)) && trimspace(subnet.availability_zone) != ""])
    )
    error_message = "Supply valid IPv4 CIDRs and private subnets in at least two named, distinct AZs, even for Single-AZ RDS."
  }
  validation {
    condition = try(alltrue([
      for cidr in concat([var.network.vpc_cidr, var.network.public_subnet_cidr], [for subnet in var.network.private_subnets : subnet.cidr]) :
      tonumber(split("/", cidr)[1]) >= 16 && tonumber(split("/", cidr)[1]) <= 28
    ]), false)
    error_message = "VPC and subnet IPv4 CIDRs must use AWS-supported prefix lengths /16 through /28."
  }
  validation {
    condition = try(alltrue([
      for cidr in concat([var.network.public_subnet_cidr], [for subnet in var.network.private_subnets : subnet.cidr]) :
      tonumber(split("/", cidr)[1]) >= tonumber(split("/", var.network.vpc_cidr)[1]) &&
      cidrhost("${cidrhost(cidr, 0)}/${split("/", var.network.vpc_cidr)[1]}", 0) == cidrhost(var.network.vpc_cidr, 0)
    ]), false)
    error_message = "All public and private subnets must be contained in the selected VPC CIDR."
  }
  validation {
    condition = try(alltrue(flatten([
      for i, left in concat([var.network.public_subnet_cidr], [for subnet in var.network.private_subnets : subnet.cidr]) : [
        for j, right in concat([var.network.public_subnet_cidr], [for subnet in var.network.private_subnets : subnet.cidr]) :
        i >= j || cidrhost("${cidrhost(left, 0)}/${min(tonumber(split("/", left)[1]), tonumber(split("/", right)[1]))}", 0) != cidrhost("${cidrhost(right, 0)}/${min(tonumber(split("/", left)[1]), tonumber(split("/", right)[1]))}", 0)
      ]
    ])), false)
    error_message = "Public and private subnet CIDRs must not overlap."
  }
}

variable "web_ingress_ipv4_cidrs" {
  description = "Explicit HTTPS client CIDRs; select 0.0.0.0/0 only when a public web service is intended."
  type        = set(string)
  nullable    = false
  validation {
    condition     = length(var.web_ingress_ipv4_cidrs) > 0 && alltrue([for cidr in var.web_ingress_ipv4_cidrs : can(cidrnetmask(cidr))])
    error_message = "Select at least one valid IPv4 client CIDR for HTTPS ingress explicitly."
  }
}

variable "enable_http_ingress" {
  description = "Opt-in port 80 for a redirect or certificate challenge; runtime TLS must be implemented separately."
  type        = bool
  default     = false
  nullable    = false
}

variable "compute" {
  description = "EC2 instance type and encrypted root EBS capacity chosen for the combined web, worker and scheduler workload."
  type = object({
    instance_type   = string
    root_volume_gib = number
  })
  nullable = false
  validation {
    condition     = trimspace(var.compute.instance_type) != "" && var.compute.root_volume_gib > 0 && floor(var.compute.root_volume_gib) == var.compute.root_volume_gib
    error_message = "Select an instance type and a positive whole-number root volume size explicitly."
  }
}

variable "database" {
  description = "Private PostgreSQL 16 Single-AZ RDS class, storage and nonzero automated-backup retention selected by the operator."
  type = object({
    instance_class        = string
    allocated_storage_gib = number
    backup_retention_days = number
  })
  nullable = false
  validation {
    condition = (
      trimspace(var.database.instance_class) != "" &&
      var.database.allocated_storage_gib > 0 && floor(var.database.allocated_storage_gib) == var.database.allocated_storage_gib &&
      var.database.backup_retention_days >= 1 && var.database.backup_retention_days <= 35 && floor(var.database.backup_retention_days) == var.database.backup_retention_days
    )
    error_message = "Select an RDS class, positive whole-number storage and 1–35 whole days of backup retention explicitly."
  }
}

variable "recovery" {
  description = "Operator-approved recovery objectives and cadence for an isolated restore rehearsal; targets are not guarantees."
  type = object({
    rpo_minutes                = number
    rto_minutes                = number
    restore_test_interval_days = number
  })
  nullable = false
  validation {
    condition     = alltrue([for target in values(var.recovery) : target > 0 && floor(target) == target])
    error_message = "Define positive whole-number RPO, RTO and restore rehearsal interval explicitly."
  }
}

variable "cost_review" {
  description = "Cost-review owner and optional explicit monthly ceiling/currency; null budget means undecided, not free. Only a separately selected monitoring.budget creates AWS Budget notifications."
  type = object({
    owner = string
    monthly_budget = object({
      amount   = number
      currency = string
    })
  })
  nullable = false
  validation {
    condition = (
      trimspace(var.cost_review.owner) != "" &&
      (var.cost_review.monthly_budget == null ? true : var.cost_review.monthly_budget.amount > 0 && trimspace(var.cost_review.monthly_budget.currency) != "")
    )
    error_message = "Name a cost-review owner; when a budget is specified, provide a positive amount and its currency."
  }
}
