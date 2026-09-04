output "public_url" {
  description = "Where people go (CloudFront, TLS on its default certificate)."
  value       = "https://${aws_cloudfront_distribution.this.domain_name}"
}

output "auth0_callback_url" {
  description = "Add this to the Auth0 application's Allowed Callback URLs (docs/AUTH0_WALKTHROUGH.md)."
  value       = "https://${aws_cloudfront_distribution.this.domain_name}/api/auth/callback/auth0"
}

output "alb_dns_name" {
  value = aws_lb.this.dns_name
}

output "ecr_api" {
  value = aws_ecr_repository.api.repository_url
}

output "ecr_web" {
  value = aws_ecr_repository.web.repository_url
}

output "database_endpoint" {
  value = aws_db_instance.this.address
}

output "cluster" {
  value = aws_ecs_cluster.this.name
}

output "migrate_task_definition" {
  description = "Run once per release, before the services move to a new image (docs/RELEASE.md line 14)."
  value       = aws_ecs_task_definition.php["migrate"].family
}

output "run_migrate_command" {
  description = "The one-off migration task."
  value       = "aws ecs run-task --cluster ${aws_ecs_cluster.this.name} --launch-type FARGATE --task-definition ${aws_ecs_task_definition.php["migrate"].family} --network-configuration 'awsvpcConfiguration={subnets=[${join(",", aws_subnet.public[*].id)}],securityGroups=[${aws_security_group.tasks.id}],assignPublicIp=ENABLED}' --region ${var.region}"
}

output "metrics_token_parameter" {
  description = "SSM parameter holding the scrape token for /api/metrics and /api/health/checks."
  value       = aws_ssm_parameter.secret["METRICS_TOKEN"].name
}
