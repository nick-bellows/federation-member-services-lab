<?php

namespace Tests\Feature\Federation;

use App\Federation\Actions\AttachDocumentMetadata;
use App\Federation\Actions\PatchApplicationFields;
use App\Federation\Actions\ReviewDocument;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Enums\DocumentReviewStatus;
use App\Federation\Enums\DocumentType;
use App\Federation\Exceptions\ApplicationNotEditableException;
use App\Federation\Exceptions\IllegalTransitionException;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Models\AuditEntry;
use App\Federation\Models\RegistrationApplication;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The three actions that write outside a transition (a field patch, a
 * document's metadata, a document review) must decide on the row they lock,
 * not on the row the request loaded (ADR-0016). The interleaving is
 * simulated: the moment the action's transaction begins, before it locks the
 * row, another writer moves the application on. The action must see the new
 * state and refuse.
 *
 * The simulated writer runs on the same connection inside the action's own
 * transaction, so a refusal rolls it back too; the tests assert the refusal
 * and the absence of the write, never the status afterwards.
 */
class ConcurrentAuthorizationTest extends FederationTestCase
{
    public function test_a_patch_is_refused_when_the_application_leaves_the_applicants_hands_before_the_lock(): void
    {
        $application = $this->startApplication();
        $this->whenTheNextTransactionBegins(fn () => $this->moveOn($application, ApplicationStatus::SUBMITTED));

        $this->assertThrows(
            fn () => app(PatchApplicationFields::class)->execute(
                $application,
                [['op' => 'replace', 'path' => '/phone', 'field' => 'phone', 'value' => '+1 555 0100']],
                $this->applicant,
            ),
            ApplicationNotEditableException::class,
        );

        $this->assertNull($application->fresh()->phone);
        $this->assertSame(0, AuditEntry::query()->where('action', 'application.fields_patched')->count());
    }

    public function test_document_metadata_is_refused_when_the_application_is_submitted_before_the_lock(): void
    {
        $application = $this->startApplication(complete: false);
        $this->whenTheNextTransactionBegins(fn () => $this->moveOn($application, ApplicationStatus::SUBMITTED));

        $this->assertThrows(
            fn () => app(AttachDocumentMetadata::class)->execute(
                $application,
                $this->applicant,
                DocumentType::PROOF_OF_AGE,
                'passport.pdf',
                'application/pdf',
                240000,
                hash('sha256', 'passport'),
            ),
            ApplicationNotEditableException::class,
        );

        $this->assertSame(0, ApplicationDocument::query()->where('registration_application_id', $application->getKey())->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'document.attached')->count());
    }

    public function test_a_document_review_is_refused_when_the_application_is_decided_before_the_lock(): void
    {
        $application = $this->applicationUnderReview();
        $document = $application->documents()->firstOrFail();
        $this->whenTheNextTransactionBegins(fn () => $this->moveOn($application, ApplicationStatus::APPROVED));

        $this->assertThrows(
            fn () => app(ReviewDocument::class)->execute($document, $this->organizationAdmin, DocumentReviewStatus::ACCEPTED),
            IllegalTransitionException::class,
        );

        $this->assertSame(DocumentReviewStatus::PENDING, $document->fresh()->review_status);
        $this->assertSame(0, AuditEntry::query()->where('action', 'document.reviewed')->count());
    }

    /**
     * Runs $writer once, inside the next transaction that begins on the
     * connection and before anything else happens in it.
     */
    private function whenTheNextTransactionBegins(callable $writer): void
    {
        $fired = false;

        Event::listen(TransactionBeginning::class, function () use (&$fired, $writer): void {
            if ($fired) {
                return;
            }

            $fired = true;
            $writer();
        });
    }

    /**
     * The other writer: straight to the table, as a committed transition
     * from another request would appear to this one.
     */
    private function moveOn(RegistrationApplication $application, ApplicationStatus $to): void
    {
        DB::table('registration_applications')
            ->where('id', $application->getKey())
            ->update(['status' => $to->value]);
    }
}
