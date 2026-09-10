<?php

namespace App\Federation\Exceptions;

/**
 * The applicant presented an idempotency key they had already used for a
 * different window or role: a client fault, never a replay to answer.
 */
class IdempotencyKeyReusedException extends FederationDomainException
{
    public function __construct()
    {
        parent::__construct('This idempotency key was already used for a different application; use a new key for a new attempt.');
    }
}
