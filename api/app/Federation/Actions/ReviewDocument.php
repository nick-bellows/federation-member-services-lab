<?php

namespace App\Federation\Actions;

use App\Federation\Enums\ApplicationActor;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Enums\DocumentReviewStatus;
use App\Federation\Exceptions\IllegalTransitionException;
use App\Federation\Exceptions\ReasonRequiredException;
use App\Federation\Exceptions\TransitionNotAllowedForActorException;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Support\ApplicationActorResolver;
use App\Federation\Support\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A reviewer accepts or rejects one document while the application is under
 * review. Rejections need a note; the applicant sees it.
 */
class ReviewDocument
{
    public function __construct(
        private readonly ApplicationActorResolver $actors,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(
        ApplicationDocument $document,
        User $reviewer,
        DocumentReviewStatus $status,
        ?string $note = null,
        ?string $requestId = null,
    ): ApplicationDocument {
        $application = $document->application;

        if (! $this->actors->canActAs($reviewer, $application, ApplicationActor::REVIEWER)) {
            throw new TransitionNotAllowedForActorException($application->status, $application->status, ApplicationActor::REVIEWER);
        }

        if ($application->status !== ApplicationStatus::UNDER_REVIEW) {
            throw new IllegalTransitionException($application->status, ApplicationStatus::UNDER_REVIEW);
        }

        if ($status === DocumentReviewStatus::REJECTED && blank($note)) {
            throw new ReasonRequiredException(ApplicationStatus::NEEDS_INFORMATION);
        }

        return DB::transaction(function () use ($document, $reviewer, $status, $note, $application, $requestId) {
            $previous = $document->review_status;

            $document->forceFill([
                'review_status' => $status,
                'review_note' => $note,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record(
                actor: $reviewer,
                action: 'document.reviewed',
                auditable: $application,
                previous: ['document_type' => $document->document_type->value, 'review_status' => $previous?->value],
                new: ['document_type' => $document->document_type->value, 'review_status' => $status->value],
                reason: $note,
                requestId: $requestId,
            );

            return $document;
        });
    }
}
