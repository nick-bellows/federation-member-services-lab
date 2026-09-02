<?php

namespace Tests\Feature\Federation;

use App\Federation\Actions\StartApplication;
use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Exceptions\DuplicateApplicationException;
use App\Federation\Exceptions\SeasonNotInFederationException;
use App\Federation\Models\Federation;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\Season;
use Illuminate\Database\UniqueConstraintViolationException;

class StartApplicationTest extends FederationTestCase
{
    public function test_a_new_application_starts_as_a_draft_with_an_audit_entry(): void
    {
        $application = $this->startApplication();

        $this->assertSame(ApplicationStatus::DRAFT, $application->status);
        $this->assertSame($application->activeKey(), $application->active_key);
        $this->assertSame('application.created', $application->auditEntries()->first()->action);
    }

    public function test_a_second_live_application_for_the_same_person_organization_season_and_role_is_rejected(): void
    {
        $this->startApplication();

        $this->expectException(DuplicateApplicationException::class);

        $this->startApplication();

        $this->assertSame(1, RegistrationApplication::count());
    }

    public function test_a_different_role_in_the_same_season_is_a_separate_application(): void
    {
        $this->startApplication(ApplicationRole::PARTICIPANT);
        $this->startApplication(ApplicationRole::COACH);

        $this->assertSame(2, RegistrationApplication::count());
    }

    public function test_a_new_application_is_allowed_after_a_cancellation(): void
    {
        $first = $this->startApplication();
        $this->transition($first, ApplicationStatus::CANCELLED, $this->applicant);

        $second = $this->startApplication();

        $this->assertNull($first->fresh()->active_key);
        $this->assertNotNull($second->active_key);
        $this->assertSame(2, RegistrationApplication::count());
    }

    public function test_the_same_idempotency_key_returns_the_existing_application(): void
    {
        $first = $this->startApplication(idempotencyKey: 'client-key-1');
        $second = $this->startApplication(idempotencyKey: 'client-key-1');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, RegistrationApplication::count());
    }

    public function test_the_database_rejects_a_duplicate_that_bypasses_the_action(): void
    {
        $first = $this->startApplication();

        $this->expectException(UniqueConstraintViolationException::class);

        RegistrationApplication::factory()->create([
            'member_organization_id' => $first->member_organization_id,
            'season_id' => $first->season_id,
            'applicant_user_id' => $first->applicant_user_id,
            'role' => $first->role,
        ]);
    }

    public function test_the_season_must_belong_to_the_organizations_federation(): void
    {
        $foreignSeason = Season::factory()->create(['federation_id' => Federation::factory()->create()->getKey()]);

        $this->expectException(SeasonNotInFederationException::class);

        app(StartApplication::class)->execute($this->applicant, $this->organization, $foreignSeason, ApplicationRole::PARTICIPANT);
    }
}
