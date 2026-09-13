<?php

namespace App\Federation\Exceptions;

class WindowAlreadyOpenException extends FederationDomainException
{
    public function __construct()
    {
        parent::__construct('A registration window already exists for this organization and season.');
    }
}
