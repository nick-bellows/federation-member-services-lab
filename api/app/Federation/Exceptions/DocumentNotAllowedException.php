<?php

namespace App\Federation\Exceptions;

class DocumentNotAllowedException extends FederationDomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'The document type, size or format is not allowed.');
    }
}
