locals {
  network_tags = { Environment = var.environment, ManagedBy = "terraform" }
}

resource "aws_vpc" "reference" {
  cidr_block           = var.network.vpc_cidr
  enable_dns_support   = true
  enable_dns_hostnames = true
  tags                 = merge(local.network_tags, { Name = "${var.environment}-reference" })
}

# Leave the default SG unusable; all workloads must select their dedicated SG.
resource "aws_default_security_group" "deny_default" {
  vpc_id  = aws_vpc.reference.id
  ingress = []
  egress  = []
  tags    = local.network_tags
}

resource "aws_subnet" "web" {
  vpc_id                  = aws_vpc.reference.id
  cidr_block              = var.network.public_subnet_cidr
  availability_zone       = var.network.public_subnet_az
  map_public_ip_on_launch = false
  tags                    = merge(local.network_tags, { Name = "${var.environment}-web" })
}

resource "aws_subnet" "database" {
  for_each                = { for index, subnet in var.network.private_subnets : tostring(index) => subnet }
  vpc_id                  = aws_vpc.reference.id
  cidr_block              = each.value.cidr
  availability_zone       = each.value.availability_zone
  map_public_ip_on_launch = false
  tags                    = merge(local.network_tags, { Name = "${var.environment}-database-${each.key}" })
}

resource "aws_internet_gateway" "public" {
  vpc_id = aws_vpc.reference.id
  tags   = local.network_tags
}

resource "aws_route_table" "web" {
  vpc_id = aws_vpc.reference.id
  tags   = merge(local.network_tags, { Name = "${var.environment}-web" })
}

resource "aws_route" "web_internet" {
  route_table_id         = aws_route_table.web.id
  destination_cidr_block = "0.0.0.0/0"
  gateway_id             = aws_internet_gateway.public.id
}

resource "aws_route_table_association" "web" {
  subnet_id      = aws_subnet.web.id
  route_table_id = aws_route_table.web.id
}

# Only the implicit VPC-local route: no IGW, NAT or public database route.
resource "aws_route_table" "database" {
  vpc_id = aws_vpc.reference.id
  route  = []
  tags   = merge(local.network_tags, { Name = "${var.environment}-database" })
}

resource "aws_route_table_association" "database" {
  for_each       = aws_subnet.database
  subnet_id      = each.value.id
  route_table_id = aws_route_table.database.id
}

resource "aws_security_group" "web" {
  name_prefix = "${var.environment}-web-"
  description = "HTTPS clients; SSM management requires no inbound administrative port"
  vpc_id      = aws_vpc.reference.id
  tags        = local.network_tags
}

resource "aws_security_group" "database" {
  name_prefix = "${var.environment}-database-"
  description = "Private PostgreSQL from the web security group only"
  vpc_id      = aws_vpc.reference.id
  tags        = local.network_tags
}

resource "aws_vpc_security_group_ingress_rule" "https" {
  for_each          = var.web_ingress_ipv4_cidrs
  security_group_id = aws_security_group.web.id
  cidr_ipv4         = each.value
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443
  description       = "Operator-selected HTTPS clients"
  tags              = local.network_tags
}

resource "aws_vpc_security_group_ingress_rule" "http" {
  for_each          = var.enable_http_ingress ? var.web_ingress_ipv4_cidrs : toset([])
  security_group_id = aws_security_group.web.id
  cidr_ipv4         = each.value
  ip_protocol       = "tcp"
  from_port         = 80
  to_port           = 80
  description       = "Opt-in HTTP redirect or certificate challenge only"
  tags              = local.network_tags
}

resource "aws_vpc_security_group_ingress_rule" "postgresql" {
  security_group_id            = aws_security_group.database.id
  referenced_security_group_id = aws_security_group.web.id
  ip_protocol                  = "tcp"
  from_port                    = 5432
  to_port                      = 5432
  description                  = "Private PostgreSQL from web only"
  tags                         = local.network_tags
}

resource "aws_vpc_security_group_egress_rule" "postgresql" {
  security_group_id            = aws_security_group.web.id
  referenced_security_group_id = aws_security_group.database.id
  ip_protocol                  = "tcp"
  from_port                    = 5432
  to_port                      = 5432
  description                  = "Private application database"
  tags                         = local.network_tags
}

# Public-subnet EC2 must explicitly receive a public IPv4 in AWS-04. These
# endpoint ports permit registries, SSM and package updates via the IGW.
resource "aws_vpc_security_group_egress_rule" "updates" {
  for_each          = toset(["80", "443"])
  security_group_id = aws_security_group.web.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = tonumber(each.value)
  to_port           = tonumber(each.value)
  description       = "Registry, SSM and package endpoints"
  tags              = local.network_tags
}
