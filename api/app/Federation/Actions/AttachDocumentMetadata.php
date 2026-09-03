<?php

namespace App\Federation\Actions;

use App\Federation\Enums\ApplicationActor;
use App\Federation\Enums\DocumentReviewStatus;
use App\Federation\Enums\DocumentType;
use App\Federation\Exceptions\ApplicationNotEditableException;
use App\Federation\Exceptions\DocumentNotAllowedException;
use App\Federation\Exceptions\TransitionNotAllowedForActorException;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Support\ApplicationActorResolver;
use App\Federation\Support\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records (or replaces) the metadata of one document on an application.
 * Only the applicant, only while the application is editable. Replacing a
 * document resets its review to pending.
 */
class AttachDocumentMetadata
{
    public function __construct(
        private readonly ApplicationActorResolver $actors,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(
        RegistrationApplication $application,
        User $actor,
        DocumentType $type,
        string $fileName,
        string $mimeType,
        int $sizeBytes,
        string $checksumSha256,
        ?string $requestId = null,
    ): ApplicationDocument {
        if (! $this->actors->canActAs($actor, $application, ApplicationActor::APPLICANT)) {
            throw new TransitionNotAllowedForActorException($application->status, $application->status, ApplicationActor::APPLICANT);
        }

        if (! $application->isEditableByApplicant()) {
            throw new ApplicationNotEditableException;
        }

        if (! in_array($mimeType, ApplicationDocument::ALLOWED_MIME_TYPES, true)) {
            throw new DocumentNotAllowedException("Format {$mimeType} is not accepted.");
        }

        if ($sizeBytes <= 0 || $sizeBytes > ApplicationDocument::MAX_SIZE_BYTES) {
            throw new DocumentNotAllowedException('Documents must be between 1 byte and 10 MB.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $checksumSha256)) {
            throw new DocumentNotAllowedException('Checksum must be a lowercase hex SHA-256.');
        }

        return DB::transaction(function () use ($application, $actor, $type, $fileName, $mimeType, $sizeBytes, $checksumSha256, $requestId) {
            $document = ApplicationDocument::query()->updateOrCreate(
                ['registration_application_id' => $application->getKey(), 'document_type' => $type->value],
                [
                    'file_name' => $fileName,
                    'mime_type' => $mimeType,
                    'size_bytes' => $sizeBytes,
                    'checksum_sha256' => $checksumSha256,
                    'review_status' => DocumentReviewStatus::PENDING,
                    'review_note' => null,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                ],
            );

            $this->audit->record(
                actor: $actor,
                action: 'document.attached',
                auditable: $application,
                new: ['document_type' => $type->value, 'checksum_sha256' => $checksumSha256],
                requestId: $requestId,
            );

            return $document;
        });
    }
}
