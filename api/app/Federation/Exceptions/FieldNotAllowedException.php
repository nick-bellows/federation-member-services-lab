<?php

namespace App\Federation\Exceptions;

/**
 * The acting person may not touch this field (field-level authorization,
 * ADR-0014). Named with the path so the client knows which operation to drop;
 * the whole patch was refused.
 */
class FieldNotAllowedException extends FederationDomainException
{
    public function __construct(public readonly string $path, public readonly string $operation)
    {
        parent::__construct("Operation \"{$operation}\" on {$path} is not allowed for this user.");
    }
}
