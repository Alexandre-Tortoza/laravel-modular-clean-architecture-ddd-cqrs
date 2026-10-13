variable "controlled_deployment" {
  description = "Opt-in reviewed SSM release document, public runtime/TLS paths and selected retained host. Null creates no deployment document; no deployment runs during provisioning."
  type = object({
    document_name             = string
    project                   = string
    tls_domain                = string
    tls_certificate_mechanism = string
    tls_certificate_directory = string
    tls_acme_webroot          = string
    tls_bind_ipv4             = string
    tls_ca_bundle             = string
    database_ca_bundle        = string
  })
  default = null
  validation {
    condition = var.controlled_deployment == null ? true : (
      var.ec2_host != null && var.container_registry != null && var.github_deployment_identity != null &&
      can(regex("^[A-Za-z0-9_.-]{3,80}$", var.controlled_deployment.document_name)) &&
      can(regex("^[a-z][a-z0-9-]{0,40}$", var.controlled_deployment.project)) &&
      can(regex("^[a-z0-9][a-z0-9.-]*\\.[a-z]{2,}$", var.controlled_deployment.tls_domain)) &&
      contains(["provided", "acme-webroot", "acme-dns"], var.controlled_deployment.tls_certificate_mechanism) &&
      can(cidrhost("${var.controlled_deployment.tls_bind_ipv4}/32", 0)) && !strcontains(var.controlled_deployment.tls_bind_ipv4, ":") &&
      alltrue([for path in [var.controlled_deployment.tls_certificate_directory, var.controlled_deployment.tls_acme_webroot, var.controlled_deployment.database_ca_bundle] : can(regex("^/srv/laravel/[A-Za-z0-9_./-]+$", path)) && !strcontains(path, "..")]) &&
      (var.controlled_deployment.tls_ca_bundle == "" || (can(regex("^/srv/laravel/[A-Za-z0-9_./-]+$", var.controlled_deployment.tls_ca_bundle)) && !strcontains(var.controlled_deployment.tls_ca_bundle, "..")))
    )
    error_message = "Select the EC2/private registry/deploy identity prerequisites, concrete TLS domain and root-owned host paths; never include secret values."
  }
}

locals {
  deployment_count = var.controlled_deployment == null || var.ec2_host == null || var.container_registry == null || var.github_deployment_identity == null ? 0 : 1
  deployment_contract = local.deployment_count == 0 ? null : {
    php_repository    = local.repository_urls.php
    nginx_repository  = local.repository_urls.nginx
    aws_region        = var.aws_region
    instance_id       = aws_instance.web[0].id
    upload_volume_id  = aws_ebs_volume.uploads[0].id
    project           = var.controlled_deployment.project
    runtime_directory = "/srv/laravel/runtime"
    runtime_env_file  = "/srv/laravel/runtime/application.env"
    upload_directory  = "/srv/laravel/uploads"
    tls_ca_bundle     = var.controlled_deployment.tls_ca_bundle
    runner_sha256     = filesha256("${path.module}/scripts/deploy_release.py")
    compose_sha256    = filesha256("${path.module}/../../compose.production.release.yaml")
    compose_environment = {
      RUNTIME_ENV_FILE          = "/srv/laravel/runtime/application.env"
      UPLOAD_DIRECTORY          = "/srv/laravel/uploads"
      DB_CA_BUNDLE              = var.controlled_deployment.database_ca_bundle
      TLS_DOMAIN                = var.controlled_deployment.tls_domain
      TLS_CERTIFICATE_MECHANISM = var.controlled_deployment.tls_certificate_mechanism
      TLS_CERTIFICATE_DIRECTORY = var.controlled_deployment.tls_certificate_directory
      TLS_ACME_WEBROOT          = var.controlled_deployment.tls_acme_webroot
      TLS_BIND_IPV4             = var.controlled_deployment.tls_bind_ipv4
    }
  }
  deployment_document = local.deployment_count == 0 ? null : {
    schemaVersion = "2.2"
    description   = "Promote reviewed OCI digests on exactly the selected Laravel host; no rebuild, provisioning or schema rollback."
    parameters = {
      Release = { type = "String", interpolationType = "ENV_VAR", allowedPattern = "^[A-Za-z0-9+/=]{1,8192}$", description = "Base64 compact public release manifest; never commands or secrets" }
    }
    mainSteps = [{
      action = "aws:runShellScript", name = "controlledRelease"
      inputs = {
        timeoutSeconds = "900"
        runCommand = [
          "set -eu; umask 0077",
          "test -n \"$${SSM_Release:-}\"",
          "directory=$(mktemp -d /srv/laravel/runtime/deployment.XXXXXXXX); trap 'rm -rf \"$directory\"' EXIT",
          "printf '%s' '${base64encode(file("${path.module}/scripts/deploy_release.py"))}' | base64 --decode >\"$directory/runner.py\"",
          "printf '%s' '${base64encode(file("${path.module}/../../compose.production.release.yaml"))}' | base64 --decode >\"$directory/compose.yaml\"",
          "printf '%s' '${base64encode(jsonencode(local.deployment_contract))}' | base64 --decode >\"$directory/contract.json\"",
          "printf '%s' \"$SSM_Release\" | base64 --decode >\"$directory/request.json\"",
          "python3 \"$directory/runner.py\" --contract \"$directory/contract.json\" --request \"$directory/request.json\" --compose \"$directory/compose.yaml\" --execute"
        ]
      }
    }]
  }
}

resource "aws_ssm_document" "controlled_release" {
  count           = local.deployment_count
  name            = var.controlled_deployment.document_name
  document_type   = "Command"
  document_format = "JSON"
  content         = jsonencode(local.deployment_document)
  tags            = local.network_tags
  lifecycle {
    prevent_destroy = true
    precondition {
      condition     = var.github_deployment_identity.document_arn == "arn:aws:ssm:${var.aws_region}:${data.aws_caller_identity.registry[0].account_id}:document/${var.controlled_deployment.document_name}"
      error_message = "The reviewed document must be exactly the document granted to the separate deployment role."
    }
  }
}

output "controlled_deployment" {
  description = "Public owner-review export only. Actual document version/hash and selected host are unknown before separately authorized provisioning."
  value = local.deployment_count == 0 ? null : {
    contract         = local.deployment_contract
    contract_sha256  = sha256(jsonencode(local.deployment_contract))
    document_arn     = var.github_deployment_identity.document_arn
    document_version = aws_ssm_document.controlled_release[0].document_version
    document_sha256  = aws_ssm_document.controlled_release[0].hash
    instance_id      = aws_instance.web[0].id
    role_arn         = aws_iam_role.github_deploy[0].arn
  }
}
