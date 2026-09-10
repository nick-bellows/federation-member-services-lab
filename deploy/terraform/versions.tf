# Minimal proof of docs/DEPLOYMENT.md on AWS (ADR-0015, addendum 2026-09-04).
#
# Status: syntax-validated with `terraform validate`; planned and applied only
# once the owner supplies AWS credentials (docs/baseline/ records the runs).
# Nothing here runs without `terraform apply`, which is the owner's action.
#
# State is local for the proof (terraform.tfstate, gitignored: it holds the
# generated database password and tokens). Before any apply that a second
# person or machine must be able to continue, configure a remote backend
# first and never commit the state (C2):
#
#   backend "s3" {
#     bucket         = "<an existing, versioned, encrypted bucket>"
#     key            = "federation-member-services-lab/proof.tfstate"
#     region         = "us-east-1"
#     dynamodb_table = "<a lock table with a LockID string key>"
#     encrypt        = true
#   }

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
