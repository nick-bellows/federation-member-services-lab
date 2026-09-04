# The four services and the one-off migration task, all on the two release
# images (deploy/compose.release.yml is the same shape without AWS).

resource "aws_ecs_cluster" "this" {
  name = local.name
  setting {
    name  = "containerInsights"
    value = "disabled" # cost; enable for a real environment
  }
}

locals {
  public_url = "https://${aws_cloudfront_distribution.this.domain_name}"
  api_url    = "http://${aws_lb.this.dns_name}"

  api_environment = [
    { name = "APP_NAME", value = "Northgate Federation" },
    { name = "APP_ENV", value = "production" },
    { name = "APP_DEBUG", value = "false" },
    { name = "APP_URL", value = local.public_url },
    { name = "LOG_CHANNEL", value = "json" },
    { name = "LOG_LEVEL", value = "info" },
    { name = "DB_CONNECTION", value = "pgsql" },
    { name = "DB_HOST", value = aws_db_instance.this.address },
    { name = "DB_PORT", value = "5432" },
    { name = "DB_DATABASE", value = "verein" },
    { name = "DB_USERNAME", value = "verein" },
    { name = "CACHE_DRIVER", value = "file" },
    { name = "FILESYSTEM_DISK", value = "local" },
    { name = "FILAMENT_FILESYSTEM_DISK", value = "local" },
    { name = "QUEUE_CONNECTION", value = "database" },
    { name = "SESSION_DRIVER", value = "file" },
    { name = "SESSION_LIFETIME", value = "120" },
    { name = "MAIL_MAILER", value = "log" },
    { name = "MAIL_FROM_ADDRESS", value = "noreply@northgate.example" },
    { name = "MAIL_FROM_NAME", value = "Northgate Federation" },
    { name = "WEB_APPLICATION_URL", value = local.public_url },
    { name = "CLUB_ADMIN_LOGIN_PATH", value = "/admin/auth/login" },
    { name = "OIDC_ISSUER", value = var.oidc_issuer },
    { name = "OIDC_AUDIENCE", value = var.oidc_audience },
    { name = "OIDC_PROVISION_USERS", value = "true" },
    { name = "LEARNING_CENTER_BASE_URL", value = var.learning_center_base_url },
    { name = "LEARNING_CENTER_TOKEN_ENDPOINT", value = var.learning_center_token_endpoint },
    { name = "LEARNING_CENTER_CLIENT_ID", value = var.learning_center_client_id },
    { name = "LEARNING_CENTER_AUDIENCE", value = "https://learning-center.northgate.example" },
    { name = "LEARNING_CENTER_TIMEOUT_MS", value = "1500" },
    { name = "LEARNING_CENTER_SNAPSHOT_TTL_MINUTES", value = "720" },
    { name = "OTEL_EXPORTER", value = "none" },
    { name = "OTEL_SERVICE_NAME", value = "federation-api" },
    { name = "READINESS_OUTBOX_MAX_AGE_SECONDS", value = "300" },
  ]

  api_secrets = [
    { name = "APP_KEY", valueFrom = aws_ssm_parameter.secret["APP_KEY"].arn },
    { name = "DB_PASSWORD", valueFrom = aws_ssm_parameter.secret["DB_PASSWORD"].arn },
    { name = "METRICS_TOKEN", valueFrom = aws_ssm_parameter.secret["METRICS_TOKEN"].arn },
    { name = "LEARNING_CENTER_CLIENT_SECRET", valueFrom = aws_ssm_parameter.secret["LEARNING_CENTER_CLIENT_SECRET"].arn },
  ]

  web_environment = [
    { name = "WEB_APPLICATION_URL", value = local.public_url },
    { name = "WEBSITE_URL", value = local.public_url },
    { name = "API_DOMAIN", value = local.api_url },
    { name = "API_PATH", value = "/api/v1" },
    { name = "API_BEARER_TOKEN", value = "" },
    { name = "NEXTAUTH_URL", value = local.public_url },
    { name = "ADMIN_LOGIN_PATHNAME", value = "/admin/auth/login" },
    { name = "AUTH0_ISSUER", value = trimsuffix(var.oidc_issuer, "/") },
    { name = "AUTH0_CLIENT_ID", value = var.auth0_client_id },
    { name = "OIDC_AUDIENCE", value = var.oidc_audience },
    { name = "SIGNAL_INVITE_URL", value = "https://example.invalid" },
    { name = "SUPPORT_EMAIL_ADDRESS", value = "support@northgate.example" },
    { name = "SUPPORT_PHONE_NUMBER", value = "000" },
  ]

  web_secrets = [
    { name = "NEXTAUTH_SECRET", valueFrom = aws_ssm_parameter.secret["NEXTAUTH_SECRET"].arn },
    { name = "AUTH0_CLIENT_SECRET", valueFrom = aws_ssm_parameter.secret["AUTH0_CLIENT_SECRET"].arn },
  ]

  log_configuration = {
    logDriver = "awslogs"
    options = {
      awslogs-group         = aws_cloudwatch_log_group.federation.name
      awslogs-region        = var.region
      awslogs-stream-prefix = "ecs"
    }
  }
}

# One definition function for the PHP image; the command and RUN_MIGRATIONS differ.
locals {
  php_roles = {
    api = {
      command     = null
      migrations  = "0"
      port        = 80
      healthcheck = ["CMD-SHELL", "curl -fsS http://127.0.0.1/api/health/live || exit 1"]
    }
    worker = {
      command     = ["php", "artisan", "federation:work"]
      migrations  = "0"
      port        = null
      healthcheck = null
    }
    scheduler = {
      command     = ["php", "artisan", "schedule:work"]
      migrations  = "0"
      port        = null
      healthcheck = null
    }
    migrate = {
      command     = ["php", "artisan", "migrate:status"]
      migrations  = "1"
      port        = null
      healthcheck = null
    }
  }
}

resource "aws_ecs_task_definition" "php" {
  for_each                 = local.php_roles
  family                   = "${local.name}-${each.key}"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = 256
  memory                   = 512
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }

  container_definitions = jsonencode([
    {
      name         = each.key
      image        = var.api_image
      essential    = true
      command      = each.value.command
      user         = each.key == "api" ? null : "1000:1000"
      environment  = concat(local.api_environment, [{ name = "RUN_MIGRATIONS", value = each.value.migrations }])
      secrets      = local.api_secrets
      portMappings = each.value.port == null ? [] : [{ containerPort = each.value.port, protocol = "tcp" }]
      healthCheck = each.value.healthcheck == null ? null : {
        command     = each.value.healthcheck
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 60
      }
      logConfiguration = local.log_configuration
    }
  ])
}

resource "aws_ecs_task_definition" "web" {
  family                   = "${local.name}-web"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = 256
  memory                   = 512
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }

  container_definitions = jsonencode([
    {
      name             = "web"
      image            = var.web_image
      essential        = true
      environment      = local.web_environment
      secrets          = local.web_secrets
      portMappings     = [{ containerPort = 3000, protocol = "tcp" }]
      logConfiguration = local.log_configuration
    }
  ])
}

# ---- Services -------------------------------------------------------------

resource "aws_ecs_service" "api" {
  name                              = "${local.name}-api"
  cluster                           = aws_ecs_cluster.this.id
  task_definition                   = aws_ecs_task_definition.php["api"].arn
  desired_count                     = var.api_desired_count
  launch_type                       = "FARGATE"
  health_check_grace_period_seconds = 90

  network_configuration {
    subnets          = aws_subnet.public[*].id
    security_groups  = [aws_security_group.tasks.id]
    assign_public_ip = true
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.api.arn
    container_name   = "api"
    container_port   = 80
  }

  depends_on = [aws_lb_listener.http]
}

resource "aws_ecs_service" "web" {
  name                              = "${local.name}-web"
  cluster                           = aws_ecs_cluster.this.id
  task_definition                   = aws_ecs_task_definition.web.arn
  desired_count                     = var.web_desired_count
  launch_type                       = "FARGATE"
  health_check_grace_period_seconds = 60

  network_configuration {
    subnets          = aws_subnet.public[*].id
    security_groups  = [aws_security_group.tasks.id]
    assign_public_ip = true
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.web.arn
    container_name   = "web"
    container_port   = 3000
  }

  depends_on = [aws_lb_listener.http]
}

resource "aws_ecs_service" "worker" {
  name            = "${local.name}-worker"
  cluster         = aws_ecs_cluster.this.id
  task_definition = aws_ecs_task_definition.php["worker"].arn
  desired_count   = 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.public[*].id
    security_groups  = [aws_security_group.tasks.id]
    assign_public_ip = true
  }
}

# Exactly one scheduler (ADR-0015); the schedule's withoutOverlapping guards a second by accident.
resource "aws_ecs_service" "scheduler" {
  name                               = "${local.name}-scheduler"
  cluster                            = aws_ecs_cluster.this.id
  task_definition                    = aws_ecs_task_definition.php["scheduler"].arn
  desired_count                      = 1
  launch_type                        = "FARGATE"
  deployment_minimum_healthy_percent = 0
  deployment_maximum_percent         = 100

  network_configuration {
    subnets          = aws_subnet.public[*].id
    security_groups  = [aws_security_group.tasks.id]
    assign_public_ip = true
  }
}
