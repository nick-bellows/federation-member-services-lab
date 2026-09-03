<?php

namespace App\Federation\Exceptions;

use App\Federation\Enums\DocumentType;

class ApplicationIncompleteException extends FederationDomainException
{
    /**
     * @param  array<int, DocumentType>  $missingDocuments
     */
    public function __construct(public readonly array $missingDocuments, public readonly bool $missingDateOfBirth)
    {
        $parts = [];

        if ($missingDateOfBirth) {
            $parts[] = 'date of birth';
        }

        if ($missingDocuments !== []) {
            $parts[] = 'documents: '.implode(', ', array_map(fn (DocumentType $type) => $type->value, $missingDocuments));
        }

        parent::__construct('The application is incomplete. Missing '.implode('; ', $parts).'.');
    }
}
