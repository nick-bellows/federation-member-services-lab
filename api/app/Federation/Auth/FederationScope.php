<?php

namespace App\Federation\Auth;

/**
 * Coarse capabilities a signed-in user holds in the federation, derived from
 * database roles (never from token claims). Object-level checks, such as
 * "may review this application", remain the job of the actor resolver and
 * the policies; scopes tell the client what to offer.
 */
enum FederationScope: string
{
    case MEMBER_READ_SELF = 'member:read:self';
    case MEMBER_UPDATE_SELF = 'member:update:self';
    case APPLICATION_CREATE = 'application:create';
    case APPLICATION_REVIEW = 'application:review';
    case ORGANIZATION_MANAGE = 'organization:manage';
}
