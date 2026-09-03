<?php

namespace App\Federation\Exceptions;

use App\Federation\Enums\ApplicationActor;
use App\Federation\Enums\ApplicationStatus;

class TransitionNotAllowedForActorException extends FederationDomainException
{
    public function __construct(
        public readonly ApplicationStatus $from,
        public readonly ApplicationStatus $to,
        public readonly ApplicationActor $required,
    ) {
        parent::__construct(
            "Only the {$required->value} may move an application from {$from->value} to {$to->value}."
        );
    }
}
