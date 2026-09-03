<?php

namespace App\Federation\Exceptions;

class DuplicateApplicationException extends FederationDomainException
{
    public function __construct()
    {
        parent::__construct('An open or approved application already exists for this person, organization, season and role.');
    }
}
