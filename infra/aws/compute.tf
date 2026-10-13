locals {
  host_count = var.ec2_host == null ? 0 : 1
  host_tags  = merge(local.network_tags, { Name = "${var.environment}-compose" })
}

# Pinned ID and approved owner; no latest AMI or SSM parameter resolution.
data "aws_ami" "host" {
  count  = local.host_count
  owners = [var.ec2_host.ami_owner_account_id]
  filter {
    name   = "image-id"
    values = [var.ec2_host.ami_id]
  }
  lifecycle {
    postcondition {
      condition = (
        self.architecture == var.ec2_host.architecture && self.virtualization_type == "hvm" && self.root_device_type == "ebs" &&
        startswith(self.name, "ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-${var.ec2_host.architecture == "x86_64" ? "amd64" : "arm64"}-server-")
      )
      error_message = "The approved AMI must be Ubuntu Server 24.04 noble with the selected architecture, HVM and EBS."
    }
  }
}

data "aws_ec2_instance_type" "host" {
  count         = local.host_count
  instance_type = var.compute.instance_type
  lifecycle {
    postcondition {
      condition     = self.hypervisor == "nitro" && contains(self.supported_architectures, var.ec2_host.architecture)
      error_message = "Choose a Nitro instance compatible with the approved AMI; upload storage is identified by its EBS NVMe serial, never an ambiguous device name."
    }
  }
}

resource "aws_instance" "web" {
  count                       = local.host_count
  ami                         = data.aws_ami.host[0].id
  instance_type               = var.compute.instance_type
  subnet_id                   = aws_subnet.web.id
  vpc_security_group_ids      = [aws_security_group.web.id]
  associate_public_ip_address = true
  iam_instance_profile        = aws_iam_instance_profile.web[0].name
  user_data_replace_on_change = true
  user_data = templatefile("${path.module}/templates/ec2-bootstrap.sh.tftpl", {
    architecture             = var.ec2_host.architecture == "x86_64" ? "amd64" : "arm64"
    docker_versions          = var.ec2_host.docker_versions
    upload_volume_id         = aws_ebs_volume.uploads[0].id
    initialize_upload_volume = var.ec2_host.initialize_upload_volume
  })
  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
    instance_metadata_tags      = "disabled"
  }
  root_block_device {
    volume_type           = "gp3"
    volume_size           = var.compute.root_volume_gib
    encrypted             = true
    delete_on_termination = true
  }
  tags = local.host_tags
  depends_on = [
    data.aws_ec2_instance_type.host,
    aws_route.web_internet, aws_route_table_association.web,
    aws_iam_role_policy.web, aws_vpc_security_group_egress_rule.updates,
  ]
}

# Separate from the disposable host/root disk. Removing or replacing this
# resource requires a reviewed lifecycle change, never an implicit deletion.
resource "aws_ebs_volume" "uploads" {
  count             = local.host_count
  availability_zone = var.network.public_subnet_az
  type              = "gp3"
  size              = var.ec2_host.upload_volume_gib
  encrypted         = true
  snapshot_id       = var.ec2_host.upload_snapshot_id
  tags = merge(local.host_tags, {
    Name = "${var.environment}-uploads", TemplateUploads = var.environment
  })
  lifecycle { prevent_destroy = true }
}

resource "aws_volume_attachment" "uploads" {
  count        = local.host_count
  device_name  = "/dev/sdf"
  volume_id    = aws_ebs_volume.uploads[0].id
  instance_id  = aws_instance.web[0].id
  force_detach = false
}

output "ec2_runtime" {
  description = "Opt-in instance and retained upload volume identifiers; no application is deployed and bootstrap completion must be checked through SSM."
  value = var.ec2_host == null ? null : {
    instance_id      = aws_instance.web[0].id
    upload_volume_id = aws_ebs_volume.uploads[0].id
    host_upload_path = "/srv/laravel/uploads"
    release_path     = "/srv/laravel/releases"
    runtime_env_path = "/srv/laravel/runtime/application.env"
    management       = "ssm-session-manager"
    logs             = "journalctl -u docker; docker logs <container>; /var/log/cloud-init-output.log"
    updates          = "Review pinned Docker APT versions and AMI, then replace the disposable host while retaining the upload EBS volume."
  }
}
