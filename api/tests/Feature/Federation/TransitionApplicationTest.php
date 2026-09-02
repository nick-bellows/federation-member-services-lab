<?php

namespace Tests\Feature\Federation;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\Events\ApplicationTransitioned;
use App\Federation\Exceptions\IllegalTransitionException;
use App\Federation\Exceptions\ReasonRequiredException;
use App\Federation\Exceptions\TransitionNotAllowedForActorException;
use App\Federation\Models\AuditEntry;
use Illuminate\Support\Facades\Event;
use LogicException;

class TransitionApplicationTest extends FederationTestCase
{
    public function test_the_applicant_submits_a_draft_and_the_transition_is_audited(): void
    {
        $application = $this->startApplication();

        $submitted = $this->transition($application, ApplicationStatus::SUBMITTED, $this->applicant, requestId: 'req-42');

        $this->assertSame(ApplicationStatus::SUBMITTED, $submitted->status);
        $this->assertNotNull($submitted->submitted_at);

        $entry = $submitted->auditEntries->last();
        $this->assertSame('application.submitted', $entry->action);
        $this->assertSame(['status' => 'draft'], $entry->previous_state);
        $this->assertSame(['status' => 'submitted'], $entry->new_state);
        $this->assertSame($this->applicant->getKey(), $entry->actor_user_id);
        $this->assertSame('user', $entry->actor_type);
        $this->assertSame('req-42', $entry->request_id);
    }

    public function test_an_illegal_transition_is_rejected_and_leaves_no_trace(): void
    {
        $application = $this->startApplication();
        $auditRowsBefore = AuditEntry::count();

        try {
            $this->transition($application, ApplicationStatus::APPROVED, $this->organizationAdmin);
            $this->fail('Expected IllegalTransitionException');
        } catch (IllegalTransitionException $e) {
            $this->assertSame(ApplicationStatus::DRAFT, $e->from);
            $this->assertSame(ApplicationStatus::APPROVED, $e->to);
        }

        $this->assertSame(ApplicationStatus::DRAFT, $application->fresh()->status);
        $this->assertSame($auditRowsBefore, AuditEntry::count());
    }

    public function test_the_applicant_cannot_perform_reviewer_transitions(): void
    {
        $application = $this->startApplication();
        $this->transition($application, ApplicationStatus::SUBMITTED, $this->applicant);

        $this->expectException(TransitionNotAllowedForActorException::class);

        $this->transition($application, ApplicationStatus::UNDER_REVIEW, $this->applicant);
    }

    public function test_a_reviewer_cannot_perform_applicant_transitions(): void
    {
        $application = $this->startApplication();

        $this->expectException(TransitionNotAllowedForActorException::class);

        $this->transition($application, ApplicationStatus::SUBMITTED, $this->organizationAdmin);
    }

    public function test_an_administrator_of_another_organization_cannot_review(): void
    {
        $application = $this->startApplication();
        $this->transition($application, ApplicationStatus::SUBMITTED, $this->applicant);

        $this->expectException(TransitionNotAllowedForActorException::class);

        $this->transition($application, ApplicationStatus::UNDER_REVIEW, $this->otherOrganizationAdmin);
    }

    public function test_a_federation_administrator_can_review_for_any_organization(): void
    {
        $application = $this->startApplication();
        $this->transition($application, ApplicationStatus::SUBMITTED, $this->applicant);

        $reviewed = $this->transition($application, ApplicationStatus::UNDER_REVIEW, $this->federationAdmin);

        $this->assertSame(ApplicationStatus::UNDER_REVIEW, $reviewed->status);
    }

    public function test_a_rejection_requires_a_reason_and_records_it(): void
    {
        $application = $this->applicationUnderReview();

        try {
            $this->transition($application, ApplicationStatus::REJECTED, $this->organizationAdmin);
            $this->fail('Expected ReasonRequiredException');
        } catch (ReasonRequiredException) {
            $this->assertSame(ApplicationStatus::UNDER_REVIEW, $application->fresh()->status);
        }

        $rejected = $this->transition($application, ApplicationStatus::REJECTED, $this->organizationAdmin, 'Missing proof of age');

        $this->assertSame(ApplicationStatus::REJECTED, $rejected->status);
        $this->assertSame('Missing proof of age', $rejected->status_reason);
        $this->assertNotNull($rejected->decided_at);
        $this->assertSame('Missing proof of age', $rejected->auditEntries->last()->reason);
    }

    public function test_the_happy_path_ends_approved_with_a_complete_audit_trail(): void
    {
        $application = $this->applicationUnderReview();

        $approved = $this->transition($application, ApplicationStatus::APPROVED, $this->organizationAdmin);

        $this->assertSame(ApplicationStatus::APPROVED, $approved->status);
        $this->assertNotNull($approved->decided_at);
        $this->assertSame(
            ['application.created', 'application.submitted', 'application.under_review', 'application.approved'],
            $approved->auditEntries()->pluck('action')->all(),
        );
    }

    public function test_an_information_request_round_trips_back_to_review(): void
    {
        $application = $this->applicationUnderReview();

        $this->transition($application, ApplicationStatus::NEEDS_INFORMATION, $this->organizationAdmin, 'Please upload a photo');
        $this->transition($application, ApplicationStatus::SUBMITTED, $this->applicant);
        $this->transition($application, ApplicationStatus::UNDER_REVIEW, $this->organizationAdmin);
        $approved = $this->transition($application, ApplicationStatus::APPROVED, $this->organizationAdmin);

        $this->assertSame(ApplicationStatus::APPROVED, $approved->status);
        $this->assertCount(7, $approved->auditEntries);
    }

    public function test_status_cannot_be_assigned_outside_the_transition_service(): void
    {
        $application = $this->startApplication();

        $this->expectException(LogicException::class);

        $application->status = ApplicationStatus::APPROVED;
        $application->save();
    }

    public function test_the_domain_event_carries_the_transition(): void
    {
        Event::fake([ApplicationTransitioned::class]);

        $application = $this->startApplication();
        $this->transition($application, ApplicationStatus::SUBMITTED, $this->applicant);

        Event::assertDispatched(ApplicationTransitioned::class, function (ApplicationTransitioned $event) use ($application) {
            return $event->application->is($application)
                && $event->from === ApplicationStatus::DRAFT
                && $event->to === ApplicationStatus::SUBMITTED
                && $event->actorUserId === $this->applicant->getKey();
        });
    }
}
