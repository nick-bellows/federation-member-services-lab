# Network, registries, logs, roles, secrets and the database for the proof.
#
# Proof-sized on purpose (docs/DEPLOYMENT.md names the production shape):
#   - two public subnets and no NAT gateway: Fargate tasks get public IPs for
#     outbound calls (ECR, the identity provider), which saves the NAT's hourly
#     cost; the production design uses private subnets and endpoints;
#   - the database sits in the same subnets, not publicly accessible, reachable
#     only from the task security groups;
#   - secrets in SSM SecureString parameters (free) rather than Secrets Manager.

data "aws_availability_zones" "available" {
  state = "available"
}

data "aws_caller_identity" "current" {}

locals {
  azs  = slice(data.aws_availability_zones.available.names, 0, 2)
  name = var.project
}

# ---- VPC ------------------------------------------------------------------

resource "aws_vpc" "this" {
  cidr_block           = var.vpc_cidr
  enable_dns_support   = true
  enable_dns_hostnames = true
  tags                 = { Name = local.name }
}

resource "aws_internet_gateway" "this" {
  vpc_id = aws_vpc.this.id
  tags   = { Name = local.name }
}

resource "aws_subnet" "public" {
  count                   = 2
  vpc_id                  = aws_vpc.this.id
  cidr_block              = cidrsubnet(var.vpc_cidr, 8, count.index)
  availability_zone       = local.azs[count.index]
  map_public_ip_on_launch = true
  tags                    = { Name = "${local.name}-public-${count.index}" }
}

resource "aws_route_table" "public" {
  vpc_id = aws_vpc.this.id
  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.this.id
  }
  tags = { Name = "${local.name}-public" }
}

resource "aws_route_table_association" "public" {
  count          = 2
  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public.id
}

# ---- Security groups ------------------------------------------------------

data "aws_ec2_managed_prefix_list" "cloudfront" {
  name = "com.amazonaws.global.cloudfront.origin-facing"
}

resource "aws_security_group" "alb" {
  name        = "${local.name}-alb"
  description = "Load balancer: CloudFront and the web tasks only"
  vpc_id      = aws_vpc.this.id

  ingress {
    description     = "CloudFront origin-facing addresses"
    from_port       = 80
    to_port         = 80
    protocol        = "tcp"
    prefix_list_ids = [data.aws_ec2_managed_prefix_list.cloudfront.id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_security_group" "tasks" {
  name        = "${local.name}-tasks"
  description = "Fargate tasks: API and web from the load balancer; worker and scheduler outbound only"
  vpc_id      = aws_vpc.this.id

  ingress {
    description     = "API from the load balancer"
    from_port       = 80
    to_port         = 80
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  ingress {
    description     = "Web from the load balancer"
    from_port       = 3000
    to_port         = 3000
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

# The web server calls the API through the load balancer (API_DOMAIN); allow it.
resource "aws_security_group_rule" "alb_from_tasks" {
  type                     = "ingress"
  security_group_id        = aws_security_group.alb.id
  from_port                = 80
  to_port                  = 80
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.tasks.id
  description              = "Web tasks reach the API through the load balancer"
}

resource "aws_security_group" "database" {
  name        = "${local.name}-database"
  description = "PostgreSQL from the tasks only"
  vpc_id      = aws_vpc.this.id

  ingress {
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = [aws_security_group.tasks.id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

# ---- Registries and logs --------------------------------------------------

resource "aws_ecr_repository" "api" {
  name                 = "${local.name}/federation-api"
  image_tag_mutability = "IMMUTABLE"
  force_delete         = true
  image_scanning_configuration {
    scan_on_push = true
  }
}

resource "aws_ecr_repository" "web" {
  name                 = "${local.name}/federation-web"
  image_tag_mutability = "IMMUTABLE"
  force_delete         = true
  image_scanning_configuration {
    scan_on_push = true
  }
}

resource "aws_cloudwatch_log_group" "federation" {
  name              = "/${local.name}/federation"
  retention_in_days = var.log_retention_days
}

# The line an alarm attaches to (ADR-0015): a scheduled task that failed.
resource "aws_cloudwatch_log_metric_filter" "scheduled_task_failed" {
  name           = "${local.name}-scheduled-task-failed"
  log_group_name = aws_cloudwatch_log_group.federation.name
  pattern        = "{ $.message = \"scheduled_task_failed\" }"

  metric_transformation {
    name      = "ScheduledTaskFailed"
    namespace = "${local.name}/federation"
    value     = "1"
  }
}

resource "aws_cloudwatch_metric_alarm" "scheduled_task_failed" {
  alarm_name          = "${local.name}-scheduled-task-failed"
  namespace           = "${local.name}/federation"
  metric_name         = "ScheduledTaskFailed"
  statistic           = "Sum"
  period              = 900
  evaluation_periods  = 1
  threshold           = 0
  comparison_operator = "GreaterThanThreshold"
  treat_missing_data  = "notBreaching"
  alarm_description   = "A scheduled federation task exited non-zero (docs/RUNBOOK.md)."
}

# ---- Secrets (SSM SecureString) -------------------------------------------

resource "random_id" "app_key" {
  byte_length = 32
}

resource "random_password" "db" {
  length  = 32
  special = false
}

resource "random_password" "metrics_token" {
  length  = 40
  special = false
}

resource "random_password" "nextauth_secret" {
  length  = 48
  special = false
}

locals {
  parameters = {
    APP_KEY                       = "base64:${random_id.app_key.b64_std}"
    DB_PASSWORD                   = random_password.db.result
    METRICS_TOKEN                 = random_password.metrics_token.result
    NEXTAUTH_SECRET               = random_password.nextauth_secret.result
    AUTH0_CLIENT_SECRET           = var.auth0_client_secret
    LEARNING_CENTER_CLIENT_SECRET = var.learning_center_client_secret
  }
}

resource "aws_ssm_parameter" "secret" {
  for_each = local.parameters
  name     = "/${local.name}/${each.key}"
  type     = "SecureString"
  value    = each.value
}

# ---- IAM ------------------------------------------------------------------

data "aws_iam_policy_document" "ecs_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "execution" {
  name               = "${local.name}-ecs-execution"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume.json
}

resource "aws_iam_role_policy_attachment" "execution_managed" {
  role       = aws_iam_role.execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

data "aws_iam_policy_document" "execution_parameters" {
  statement {
    actions   = ["ssm:GetParameters", "ssm:GetParameter"]
    resources = [for p in aws_ssm_parameter.secret : p.arn]
  }
  statement {
    actions   = ["kms:Decrypt"]
    resources = ["arn:aws:kms:${var.region}:${data.aws_caller_identity.current.account_id}:alias/aws/ssm"]
  }
}

resource "aws_iam_role_policy" "execution_parameters" {
  name   = "read-parameters"
  role   = aws_iam_role.execution.id
  policy = data.aws_iam_policy_document.execution_parameters.json
}

# The application itself needs no AWS API in the proof (no S3 yet, ADR-0008).
resource "aws_iam_role" "task" {
  name               = "${local.name}-ecs-task"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume.json
}

# ---- Database -------------------------------------------------------------

resource "aws_db_subnet_group" "this" {
  name       = local.name
  subnet_ids = aws_subnet.public[*].id
}

resource "aws_db_instance" "this" {
  identifier              = local.name
  engine                  = "postgres"
  engine_version          = "16"
  instance_class          = var.db_instance_class
  allocated_storage       = var.db_allocated_storage_gb
  storage_type            = "gp3"
  db_name                 = "verein"
  username                = "verein"
  password                = random_password.db.result
  db_subnet_group_name    = aws_db_subnet_group.this.name
  vpc_security_group_ids  = [aws_security_group.database.id]
  publicly_accessible     = false
  multi_az                = false
  backup_retention_period = 1
  skip_final_snapshot     = true # a proof; docs/RELEASE.md snapshots before a migration by hand
  deletion_protection     = false
  apply_immediately       = true
}
