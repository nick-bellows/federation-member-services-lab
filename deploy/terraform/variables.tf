variable "project" {
  description = "Name prefix for every resource."
  type        = string
  default     = "northgate"
}

variable "region" {
  type    = string
  default = "us-east-1"
}

variable "aws_profile" {
  description = "AWS CLI profile to use; null for the default credential chain."
  type        = string
  default     = null
}

variable "vpc_cidr" {
  type    = string
  default = "10.20.0.0/16"
}

variable "api_image" {
  description = "Full image reference for the API release image (ECR URI with a git-SHA tag). Build with docker/api/api.release.Dockerfile for linux/amd64."
  type        = string
}

variable "web_image" {
  description = "Full image reference for the web release image (ECR URI with a git-SHA tag). Build with docker/web_application/web_application.Dockerfile for linux/amd64."
  type        = string
}

variable "api_desired_count" {
  type    = number
  default = 1
}

variable "web_desired_count" {
  type    = number
  default = 1
}

variable "db_instance_class" {
  type    = string
  default = "db.t4g.micro"
}

variable "db_allocated_storage_gb" {
  type    = number
  default = 20
}

# Identity (docs/AUTH0_WALKTHROUGH.md). The API validates tokens against the
# issuer's discovery document; the web app runs the authorization-code flow.
variable "oidc_issuer" {
  description = "Issuer as it appears in tokens, with Auth0's trailing slash, e.g. https://<tenant>.<region>.auth0.com/"
  type        = string
}

variable "oidc_audience" {
  type    = string
  default = "https://api.northgate.example"
}

variable "auth0_client_id" {
  type = string
}

variable "auth0_client_secret" {
  type      = string
  sensitive = true
}

# The Learning Center. No provider exists in the proof: readiness reports it
# as degraded and a reviewer's refresh answers 503, which is the designed
# behaviour (ADR-0009). Point these at a real provider when one exists.
variable "learning_center_base_url" {
  type    = string
  default = "https://learning-center.northgate.example"
}

variable "learning_center_token_endpoint" {
  type    = string
  default = "https://learning-center.northgate.example/oauth/token"
}

variable "learning_center_client_id" {
  type    = string
  default = "federation-api"
}

variable "learning_center_client_secret" {
  type      = string
  sensitive = true
  default   = "unset"
}

variable "log_retention_days" {
  type    = number
  default = 14
}
