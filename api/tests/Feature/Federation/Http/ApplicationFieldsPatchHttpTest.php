<?php

namespace Tests\Feature\Federation\Http;

use App\Federation\Models\AuditEntry;
use App\Federation\Models\RegistrationApplication;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Field-level authorization on the JSON Patch action (ADR-0014): who may
 * touch which path, that one refused operation refuses the whole patch,
 * that a stale "test" stops the write, and that reviewer notes never reach
 * the applicant.
 */
class ApplicationFieldsPatchHttpTest extends FederationHttpTestCase
{
    private const MEDIA_TYPE = 'application/json-patch+json';

    public function test_the_applicant_patches_their_own_details_on_a_draft_and_the_change_is_audited(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $this->patchFields($this->applicant, $id, [
            ['op' => 'replace', 'path' => '/phone', 'value' => '+1 555 0100'],
            ['op' => 'add', 'path' => '/applicantNotes', 'value' => 'Available weekends.'],
        ], ['X-Request-Id' => 'patch-request-0001'])
            ->assertOk()
            ->assertJsonPath('data.attributes.phone', '+1 555 0100')
            ->assertJsonPath('data.attributes.applicantNotes', 'Available weekends.')
            ->assertJsonPath('data.attributes.dateOfBirth', '1998-04-12');

        $entry = AuditEntry::query()->where('action', 'application.fields_patched')->sole();
        $this->assertSame((int) $this->applicant->getKey(), (int) $entry->actor_user_id);
        $this->assertSame('patch-request-0001', $entry->request_id);
        $this->assertSame(['phone' => null, 'applicantNotes' => null], $entry->previous_state);
        $this->assertSame(['phone' => '+1 555 0100', 'applicantNotes' => 'Available weekends.'], $entry->new_state);
    }

    public function test_one_forbidden_operation_refuses_the_whole_patch(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $this->patchFields($this->applicant, $id, [
            ['op' => 'replace', 'path' => '/phone', 'value' => '+1 555 0100'],
            ['op' => 'replace', 'path' => '/reviewerNotes', 'value' => 'I approve myself.'],
        ])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'field_not_allowed')
            ->assertJsonPath('errors.0.meta.path', '/reviewerNotes')
            ->assertJsonPath('errors.0.meta.op', 'replace');

        $application = RegistrationApplication::query()->findOrFail($id);
        $this->assertNull($application->phone, 'the allowed operation was not applied either');
        $this->assertNull($application->reviewer_notes);
        $this->assertSame(0, AuditEntry::query()->where('action', 'application.fields_patched')->count());
    }

    public function test_a_reviewer_writes_reviewer_notes_that_the_applicant_never_sees(): void
    {
        $id = $this->submittedApplication();

        $this->patchFields($this->organizationAdmin, $id, [
            ['op' => 'add', 'path' => '/reviewerNotes', 'value' => 'Birth certificate legible; waiting on the second reference.'],
        ])
            ->assertOk()
            ->assertJsonPath('data.attributes.reviewerNotes', 'Birth certificate legible; waiting on the second reference.');

        $this->request($this->federationAdmin, 'GET', self::BASE."/registration-applications/{$id}")
            ->assertOk()
            ->assertJsonPath('data.attributes.reviewerNotes', 'Birth certificate legible; waiting on the second reference.');

        $this->request($this->applicant, 'GET', self::BASE."/registration-applications/{$id}")
            ->assertOk()
            ->assertJsonPath('data.attributes.reviewerNotes', null);

        $this->assertSame(
            'Birth certificate legible; waiting on the second reference.',
            RegistrationApplication::query()->findOrFail($id)->reviewer_notes,
        );
    }

    public function test_a_reviewer_may_not_touch_the_applicants_fields(): void
    {
        $id = $this->submittedApplication();

        $this->patchFields($this->organizationAdmin, $id, [
            ['op' => 'replace', 'path' => '/dateOfBirth', 'value' => '2000-01-01'],
        ])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'field_not_allowed')
            ->assertJsonPath('errors.0.meta.path', '/dateOfBirth');

        $this->assertSame('1998-04-12', RegistrationApplication::query()->findOrFail($id)->date_of_birth->toDateString());
    }

    public function test_an_administrator_of_another_organization_cannot_reach_the_application(): void
    {
        $id = $this->submittedApplication();

        $response = $this->patchFields($this->otherOrganizationAdmin, $id, [
            ['op' => 'add', 'path' => '/reviewerNotes', 'value' => 'Not my organization.'],
        ]);

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertNull(RegistrationApplication::query()->findOrFail($id)->reviewer_notes);
    }

    public function test_the_applicant_can_no_longer_patch_details_once_submitted(): void
    {
        $id = $this->submittedApplication();

        $this->patchFields($this->applicant, $id, [
            ['op' => 'replace', 'path' => '/phone', 'value' => '+1 555 0199'],
        ])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'application_not_editable');

        $this->assertNull(RegistrationApplication::query()->findOrFail($id)->phone);
    }

    public function test_a_test_operation_guards_against_a_stale_view(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);
        RegistrationApplication::query()->whereKey($id)->update(['phone' => '+1 555 0100']);

        $this->patchFields($this->applicant, $id, [
            ['op' => 'test', 'path' => '/phone', 'value' => '+1 555 0000'],
            ['op' => 'replace', 'path' => '/phone', 'value' => '+1 555 0200'],
        ])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'patch_test_failed')
            ->assertJsonPath('errors.0.meta.path', '/phone');

        $this->assertSame('+1 555 0100', RegistrationApplication::query()->findOrFail($id)->phone);

        $this->patchFields($this->applicant, $id, [
            ['op' => 'test', 'path' => '/phone', 'value' => '+1 555 0100'],
            ['op' => 'replace', 'path' => '/phone', 'value' => '+1 555 0200'],
            ['op' => 'test', 'path' => '/dateOfBirth', 'value' => '1998-04-12'],
        ])
            ->assertOk()
            ->assertJsonPath('data.attributes.phone', '+1 555 0200');
    }

    public function test_remove_clears_a_field_and_values_are_validated(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);
        RegistrationApplication::query()->whereKey($id)->update(['phone' => '+1 555 0100']);

        $this->patchFields($this->applicant, $id, [['op' => 'remove', 'path' => '/phone']])
            ->assertOk()
            ->assertJsonPath('data.attributes.phone', null);

        $this->patchFields($this->applicant, $id, [['op' => 'replace', 'path' => '/dateOfBirth', 'value' => '2999-01-01']])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'invalid_patch');

        $this->assertSame('1998-04-12', RegistrationApplication::query()->findOrFail($id)->date_of_birth->toDateString());
    }

    public function test_the_wrong_media_type_is_refused_before_anything_is_read(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $this->request($this->applicant, 'PATCH', self::BASE."/registration-applications/{$id}/-actions/fields", [
            ['op' => 'replace', 'path' => '/phone', 'value' => '+1 555 0100'],
        ])
            ->assertStatus(415)
            ->assertJsonPath('errors.0.code', 'unsupported_media_type');

        $this->assertNull(RegistrationApplication::query()->findOrFail($id)->phone);
    }

    public function test_a_malformed_document_is_refused_as_a_whole(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        // An object, not a list of operations.
        $this->patchFields($this->applicant, $id, ['op' => 'replace', 'path' => '/phone', 'value' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'invalid_patch');

        // An operation RFC 6902 knows but this resource does not offer.
        $this->patchFields($this->applicant, $id, [['op' => 'move', 'from' => '/phone', 'path' => '/applicantNotes']])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'invalid_patch');

        // A nested path: only top-level fields exist here.
        $this->patchFields($this->applicant, $id, [['op' => 'replace', 'path' => '/phone/0', 'value' => 'x']])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'invalid_patch');

        // A value-taking operation without a value.
        $this->patchFields($this->applicant, $id, [['op' => 'replace', 'path' => '/phone']])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'invalid_patch');

        $this->assertSame(0, AuditEntry::query()->where('action', 'application.fields_patched')->count());
    }

    public function test_the_resource_update_cannot_write_reviewer_notes(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $this->request($this->applicant, 'PATCH', self::BASE."/registration-applications/{$id}", $this->resource(
            'registration-applications',
            ['reviewerNotes' => 'Approve me.'],
            id: $id,
        ));

        $this->assertNull(RegistrationApplication::query()->findOrFail($id)->reviewer_notes);
    }

    /**
     * @param  array<int|string, mixed>  $document
     * @param  array<string, string>  $headers
     */
    private function patchFields(User $as, string $id, array $document, array $headers = []): TestResponse
    {
        return $this->request(
            $as,
            'PATCH',
            self::BASE."/registration-applications/{$id}/-actions/fields",
            $document,
            array_merge(['Content-Type' => self::MEDIA_TYPE], $headers),
        );
    }

    private function submittedApplication(): string
    {
        $id = $this->startApplicationOverHttp($this->applicant);
        $this->attachRequiredDocumentsOverHttp($this->applicant, $id);
        $this->submitOverHttp($this->applicant, $id)->assertOk();

        return $id;
    }
}
