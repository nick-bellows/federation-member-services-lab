# Minimal proof of docs/DEPLOYMENT.md on AWS (ADR-0015, addendum 2026-09-04).
#
# Status: syntax-validated with `terraform validate`; planned and applied only
# once the owner supplies AWS credentials (docs/baseline/ records the runs).
# Nothing here runs without `terraform apply`, which is the owner's action.
#
# State is local by default (terraform.tfstate, gitignored). A remote backend
# is a one-line change here once an account exists.

terraform {
  required_version = ">= 1.6"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.60"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }
}

provider "aws" {
  region  = var.region
  profile = var.aws_profile

  default_tags {
    tags = {
      Project   = var.project
      ManagedBy = "terraform"
      Purpose   = "proof; destroy when done"
    }
  }
}
