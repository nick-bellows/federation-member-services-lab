<?php

namespace App\Federation\Exceptions;

use App\Federation\Enums\ApplicationStatus;

class IllegalTransitionException extends FederationDomainException
{
    public function __construct(
        public readonly ApplicationStatus $from,
        public readonly ApplicationStatus $to,
    ) {
        parent::__construct("An application cannot move from {$from->value} to {$to->value}.");
    }
}
