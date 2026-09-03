<?php

namespace App\Federation\JsonApi\ApplicationDocuments;

use App\Federation\Enums\DocumentReviewStatus;
use App\Federation\Enums\DocumentType;
use App\Federation\Models\ApplicationDocument;
use Illuminate\Validation\Rule;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

/**
 * Creating = the applicant attaches metadata. Updating = a reviewer records
 * a decision. The schema's read-only flags keep each side's fields out of
 * the other's request, so these rules only describe what is allowed.
 */
class ApplicationDocumentRequest extends ResourceRequest
{
    public function rules(): array
    {
        if ($this->isUpdating()) {
            return [
                'reviewStatus' => ['required', Rule::in([DocumentReviewStatus::ACCEPTED->value, DocumentReviewStatus::REJECTED->value])],
                'reviewNote' => ['nullable', 'string', 'max:1000'],
            ];
        }

        return [
            'documentType' => ['required', Rule::in(DocumentType::values())],
            'fileName' => ['required', 'string', 'max:255'],
            'mimeType' => ['required', Rule::in(ApplicationDocument::ALLOWED_MIME_TYPES)],
            'sizeBytes' => ['required', 'integer', 'min:1', 'max:'.ApplicationDocument::MAX_SIZE_BYTES],
            'checksumSha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'application' => ['required', JsonApiRule::toOne()],
        ];
    }
}
