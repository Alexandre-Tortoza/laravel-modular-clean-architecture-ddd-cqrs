# Reference contract only: resource implementations follow in AWS-02 through AWS-09.
# No provider, data source, backend, credential, secret value or resource is needed here.
output "reference_topology" {
  description = "Selected EC2/Compose + private RDS topology and explicit operator inputs; this output is not deployed infrastructure."
  value = {
    region      = var.aws_region
    environment = var.environment
    domain_name = var.domain_name
    compute = {
      host_count   = 1
      orchestrator = "docker-compose"
      roles        = tolist(["web", "worker", "scheduler"])
      subnet       = "public"
      capacity     = var.compute
    }
    network = {
      configuration          = var.network
      public_ingress_ports   = tolist([80, 443])
      database_port          = 5432
      database_source        = "ec2-security-group"
      egress                 = "public-ipv4-and-internet-gateway"
      nat_gateway_required   = false
      load_balancer_required = false
    }
    database = {
      engine              = "postgresql"
      engine_major        = 16
      publicly_accessible = false
      multi_az            = false
      capacity            = var.database
    }
    recovery    = var.recovery
    cost_review = var.cost_review
  }
}
