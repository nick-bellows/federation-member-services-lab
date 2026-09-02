<?php

namespace Tests\Feature\Federation\Http;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Http\Middleware\AssignRequestId;
use App\Federation\Models\AuditEntry;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;

class RegistrationApplicationsHttpTest extends FederationHttpTestCase
{
    public function test_an_applicant_starts_a_draft_inside_an_open_window(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $application = RegistrationApplication::query()->findOrFail($id);
        $this->assertSame('draft', $application->status->value);
        $this->assertSame($this->window->getKey(), $application->registration_window_id);
        $this->assertSame('1998-04-12', $application->date_of_birth->toDateString());
    }

    public function test_the_response_lists_what_is_still_missing_before_submission(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant, role: 'coach');

        $this->request($this->applicant, 'GET', self::BASE."/registration-applications/{$id}")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', 'draft')
            ->assertJsonPath('data.attributes.missingRequiredDocuments', ['proof_of_age', 'photo', 'coaching_licence', 'background_check_consent']);
    }

    public function test_submitting_an_incomplete_application_is_a_422_with_the_missing_pieces(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $this->submitOverHttp($this->applicant, $id)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'application_incomplete')
            ->assertJsonPath('errors.0.meta.missingDocuments', ['proof_of_age', 'photo'])
            ->assertJsonPath('errors.0.meta.missingDateOfBirth', false);

        $this->assertSame('draft', RegistrationApplication::query()->findOrFail($id)->status->value);
    }

    public function test_a_complete_application_submits_and_a_retry_with_the_same_key_is_idempotent(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);
        $this->attachRequiredDocumentsOverHttp($this->applicant, $id);

        $headers = ['Idempotency-Key' => 'submit-attempt-0001', AssignRequestId::HEADER => 'req-submit-0001'];

        $first = $this->submitOverHttp($this->applicant, $id, $headers);
        $first->assertOk()->assertJsonPath('data.attributes.status', 'submitted');
        $this->assertSame('req-submit-0001', $first->headers->get(AssignRequestId::HEADER));

        $auditRowsAfterFirst = AuditEntry::count();

        // The double-click, the flaky network, the impatient user: same key, same answer, no new audit row.
        $this->submitOverHttp($this->applicant, $id, $headers)
            ->assertOk()
            ->assertJsonPath('data.attributes.status', 'submitted');
        $this->assertSame($auditRowsAfterFirst, AuditEntry::count());

        // A genuinely new attempt is an illegal transition from "submitted".
        $this->submitOverHttp($this->applicant, $id, ['Idempotency-Key' => 'submit-attempt-0002'])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'illegal_transition');

        $submitted = AuditEntry::query()->where('action', 'application.submitted')->firstOrFail();
        $this->assertSame('req-submit-0001', $submitted->request_id);
    }

    public function test_applicants_only_see_their_own_applications(): void
    {
        $mine = $this->startApplicationOverHttp($this->applicant);
        $theirs = $this->startApplicationOverHttp($this->otherApplicant);

        $this->request($this->applicant, 'GET', self::BASE.'/registration-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine);

        $this->request($this->applicant, 'GET', self::BASE."/registration-applications/{$theirs}")
            ->assertStatus(403);
    }

    public function test_a_reviewer_sees_the_queue_for_their_organization_only(): void
    {
        $atMine = $this->startApplicationOverHttp($this->applicant);
        $this->attachRequiredDocumentsOverHttp($this->applicant, $atMine);
        $this->submitOverHttp($this->applicant, $atMine)->assertOk();

        $otherWindow = RegistrationWindow::factory()->create([
            'member_organization_id' => $this->otherOrganization->getKey(),
            'season_id' => $this->season->getKey(),
        ]);
        $atOther = $this->startApplicationOverHttp($this->otherApplicant, $otherWindow);

        $this->request($this->organizationAdmin, 'GET', self::BASE.'/registration-applications?filter[status]=submitted&include=applicant,documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $atMine)
            ->assertJsonPath('data.0.relationships.applicant.data.type', 'federation-users');

        $this->request($this->organizationAdmin, 'GET', self::BASE."/registration-applications/{$atOther}")
            ->assertStatus(403);

        $this->request($this->federationAdmin, 'GET', self::BASE.'/registration-applications')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_review_path_over_http_with_reasons_and_actor_rules(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);
        $this->attachRequiredDocumentsOverHttp($this->applicant, $id);
        $this->submitOverHttp($this->applicant, $id)->assertOk();

        // The applicant may not review their own application.
        $this->request($this->applicant, 'POST', self::BASE."/registration-applications/{$id}/-actions/start-review")
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'transition_not_allowed_for_actor');

        // Another organization's administrator cannot even see it.
        $this->request($this->otherOrganizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/start-review")
            ->assertStatus(403);

        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/start-review")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', 'under_review');

        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/reject")
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'reason_required');

        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/request-information", ['meta' => ['reason' => 'Photo is unreadable.']])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', 'needs_information')
            ->assertJsonPath('data.attributes.statusReason', 'Photo is unreadable.');

        $this->submitOverHttp($this->applicant, $id)->assertOk();
        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/start-review")->assertOk();

        $this->request($this->federationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/approve")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', 'approved');

        $this->assertSame(
            ['application.created', 'document.attached', 'document.attached', 'application.submitted', 'application.under_review', 'application.needs_information', 'application.submitted', 'application.under_review', 'application.approved'],
            RegistrationApplication::query()->findOrFail($id)->auditEntries()->pluck('action')->all(),
        );

        // The same trail is exposed to the applicant as "history": actor names and
        // reasons, never request ids or internal identifiers.
        $history = $this->request($this->applicant, 'GET', self::BASE."/registration-applications/{$id}")
            ->assertOk()
            ->json('data.attributes.history');

        $this->assertCount(9, $history);
        $this->assertSame('application.needs_information', $history[5]['action']);
        $this->assertSame('Photo is unreadable.', $history[5]['reason']);
        $this->assertSame($this->organizationAdmin->name, $history[5]['actor']);
        $this->assertSame(['action', 'occurredAt', 'actor', 'from', 'to', 'reason', 'documentType'], array_keys($history[5]));
    }

    public function test_the_applicant_can_edit_details_while_editable_and_not_afterwards(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $this->request($this->applicant, 'PATCH', self::BASE."/registration-applications/{$id}", $this->resource('registration-applications', ['phone' => '+1 555 0100'], id: $id))
            ->assertOk()
            ->assertJsonPath('data.attributes.phone', '+1 555 0100');
        $this->assertSame('application.details_updated', AuditEntry::query()->latest('id')->first()->action);

        // Role and window are fixed once started: the schema marks them read-only
        // on update, so a PATCH that names them changes nothing.
        $this->request($this->applicant, 'PATCH', self::BASE."/registration-applications/{$id}", $this->resource('registration-applications', ['role' => 'coach'], id: $id))
            ->assertJsonPath('data.attributes.role', 'participant');
        $this->assertSame('participant', RegistrationApplication::query()->findOrFail($id)->role->value);

        $this->attachRequiredDocumentsOverHttp($this->applicant, $id);
        $this->submitOverHttp($this->applicant, $id)->assertOk();

        $this->request($this->applicant, 'PATCH', self::BASE."/registration-applications/{$id}", $this->resource('registration-applications', ['phone' => '+1 555 0199'], id: $id))
            ->assertStatus(403);
    }

    public function test_a_closed_window_and_a_duplicate_are_conflicts(): void
    {
        $closed = RegistrationWindow::factory()->closed()->create([
            'member_organization_id' => $this->otherOrganization->getKey(),
            'season_id' => $this->season->getKey(),
        ]);

        $this->request($this->applicant, 'POST', self::BASE.'/registration-applications', $this->resource(
            'registration-applications',
            ['role' => ApplicationRole::PARTICIPANT->value],
            ['registrationWindow' => ['type' => 'registration-windows', 'id' => (string) $closed->getKey()]],
        ))->assertStatus(409)->assertJsonPath('errors.0.code', 'window_closed');

        $this->startApplicationOverHttp($this->applicant);

        $this->request($this->applicant, 'POST', self::BASE.'/registration-applications', $this->resource(
            'registration-applications',
            ['role' => ApplicationRole::PARTICIPANT->value],
            ['registrationWindow' => ['type' => 'registration-windows', 'id' => (string) $this->window->getKey()]],
        ))->assertStatus(409)->assertJsonPath('errors.0.code', 'duplicate_application');
    }
}
