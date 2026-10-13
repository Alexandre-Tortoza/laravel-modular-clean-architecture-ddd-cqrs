output "network_ids" {
  description = "Explicit network resources for the later EC2 and private RDS implementations."
  value = {
    vpc_id                     = aws_vpc.reference.id
    web_subnet_id              = aws_subnet.web.id
    database_subnet_ids        = [for subnet in aws_subnet.database : subnet.id]
    web_security_group_id      = aws_security_group.web.id
    database_security_group_id = aws_security_group.database.id
  }
}
