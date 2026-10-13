variable "container_registry" {
  description = "Opt-in private PHP/Nginx ECR repositories. All artifacts remain retained for digest rollback; storage estimates and the public IPv4/IGW egress path require explicit operator review. Null creates no registry."
  type = object({
    repositories = object({ php = string, nginx = string })
    egress_path  = string
    storage_estimate = object({
      expected_gib        = number
      price_per_gib_month = number
      currency            = string
    })
    publisher = optional(object({
      oidc_provider_arn = string
      subject           = string
    }))
    releases = optional(object({
      current  = object({ source_revision = string, php_digest = string, nginx_digest = string })
      previous = optional(object({ source_revision = string, php_digest = string, nginx_digest = string }))
    }))
  })
  default = null
  validation {
    condition = var.container_registry == null ? true : (
      var.container_registry.repositories.php != var.container_registry.repositories.nginx &&
      alltrue([for name in values(var.container_registry.repositories) : length(name) >= 2 && length(name) <= 256 && can(regex("^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)*$", name))]) &&
      var.container_registry.egress_path == "public-ipv4-igw" &&
      var.container_registry.storage_estimate.expected_gib >= 0 && var.container_registry.storage_estimate.price_per_gib_month > 0 &&
      can(regex("^[A-Z]{3}$", var.container_registry.storage_estimate.currency))
    )
    error_message = "Select two distinct valid private repository names, the reviewed public-ipv4-igw path, nonnegative storage, a positive reviewed storage unit price and a three-letter currency."
  }
  validation {
    condition = var.container_registry == null ? true : (var.container_registry.publisher == null ? true : (
      can(regex("^arn:aws:iam::[0-9]{12}:oidc-provider/token\\.actions\\.githubusercontent\\.com$", var.container_registry.publisher.oidc_provider_arn)) &&
      can(regex("^repo:[A-Za-z0-9_.-]+(@[0-9]+)?/[A-Za-z0-9_.-]+(@[0-9]+)?:(environment:[A-Za-z0-9_.%-]+|ref:refs/tags/[A-Za-z0-9_.-]+)$", var.container_registry.publisher.subject))
    ))
    error_message = "Publisher requires an existing GitHub OIDC provider ARN and an exact verified repository subject restricted to one environment or release tag, without wildcards or PR/branch subjects. Include immutable IDs when GitHub uses them."
  }
  validation {
    condition = var.container_registry == null ? true : (var.container_registry.releases == null ? true : (
      alltrue([for release in [var.container_registry.releases.current, var.container_registry.releases.previous] : release == null ? true : (
        can(regex("^[0-9a-f]{40}$", release.source_revision)) &&
        can(regex("^sha256:[0-9a-f]{64}$", release.php_digest)) && can(regex("^sha256:[0-9a-f]{64}$", release.nginx_digest))
      )])
    ))
    error_message = "Each selected current/previous release requires its complete Git source SHA and both immutable sha256 image digests; tags and latest are not image references."
  }
}
