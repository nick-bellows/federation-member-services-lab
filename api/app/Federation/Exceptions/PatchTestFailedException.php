<?php

namespace App\Federation\Exceptions;

/**
 * An RFC 6902 "test" operation did not match the stored value: the client's
 * view was stale. Nothing was applied; the client re-reads and retries.
 */
class PatchTestFailedException extends FederationDomainException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("The value at {$path} no longer matches; re-read the application and try again.");
    }
}
