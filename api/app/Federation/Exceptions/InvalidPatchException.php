<?php

namespace App\Federation\Exceptions;

/**
 * The patch document itself is not acceptable: malformed, an unsupported
 * operation, a path that is not one top-level field, or a value that fails
 * the field's validation. Nothing was applied.
 */
class InvalidPatchException extends FederationDomainException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
