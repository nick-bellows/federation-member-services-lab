<?php

namespace App\Federation\Exceptions;

use App\Federation\Enums\ApplicationStatus;

class ReasonRequiredException extends FederationDomainException
{
    public function __construct(public readonly ApplicationStatus $to)
    {
        parent::__construct("A reason is required to move an application to {$to->value}.");
    }
}
