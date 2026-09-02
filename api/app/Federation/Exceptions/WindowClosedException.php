<?php

namespace App\Federation\Exceptions;

class WindowClosedException extends FederationDomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Applications can only be started while the registration window is open.');
    }
}
