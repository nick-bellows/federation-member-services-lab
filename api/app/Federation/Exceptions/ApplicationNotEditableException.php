<?php

namespace App\Federation\Exceptions;

class ApplicationNotEditableException extends FederationDomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'The application can no longer be changed by the applicant in its current status.');
    }
}
