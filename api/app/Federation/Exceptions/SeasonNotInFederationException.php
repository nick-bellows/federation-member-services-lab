<?php

namespace App\Federation\Exceptions;

class SeasonNotInFederationException extends FederationDomainException
{
    public function __construct()
    {
        parent::__construct('The season does not belong to the organization\'s federation.');
    }
}
