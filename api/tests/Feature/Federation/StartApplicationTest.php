<?php

namespace Tests\Feature\Federation;

use App\Federation\Actions\StartApplication;
use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Exceptions\DuplicateApplicationException;
use App\Federation\Exceptions\RoleNotOfferedException;
use App\Federation\Exceptions\WindowClosedException;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;
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
            'registration_window_id' => $first->registration_window_id,
            'applicant_user_id' => $first->applicant_user_id,
            'role' => $first->role,
        ]);
    }

    public function test_a_closed_window_refuses_new_applications(): void
    {
        $closed = RegistrationWindow::factory()->closed()->create([
            'member_organization_id' => $this->otherOrganization->getKey(),
            'season_id' => Season::factory()->create(['federation_id' => $this->federation->getKey()])->getKey(),
        ]);

        $this->expectException(WindowClosedException::class);

        app(StartApplication::class)->execute($this->applicant, $closed, ApplicationRole::PARTICIPANT);
    }

    public function test_a_window_only_accepts_the_roles_it_offers(): void
    {
        $this->otherWindow->forceFill(['roles' => [ApplicationRole::REFEREE->value]])->save();

        $this->expectException(RoleNotOfferedException::class);

        app(StartApplication::class)->execute($this->applicant, $this->otherWindow, ApplicationRole::COACH);
    }

    public function test_the_application_inherits_organization_and_season_from_the_window(): void
    {
        $application = $this->startApplication(complete: false);

        $this->assertTrue($application->registrationWindow->is($this->window));
        $this->assertSame($this->organization->getKey(), $application->member_organization_id);
        $this->assertSame($this->season->getKey(), $application->season_id);
    }
}
