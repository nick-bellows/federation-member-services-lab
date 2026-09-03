<?php

namespace App\Federation\Exceptions;

class RoleNotOfferedException extends FederationDomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'The registration window does not accept applications for this role.');
    }
}
