<?php

namespace Tests\Feature\Federation;

use App\Federation\Actions\StartApplication;
use App\Federation\Actions\TransitionApplication;
use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Enums\DocumentType;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;
use App\Federation\Models\Season;
use App\Models\User;
use Tests\TestCase;

/**
 * One federation with two organizations, one season, an open registration
 * window per organization, and the people who act on applications. Every
 * federation feature test starts from this world.
 */
abstract class FederationTestCase extends TestCase
{
    protected Federation $federation;

    protected Season $season;

    protected MemberOrganization $organization;

    protected MemberOrganization $otherOrganization;

    protected RegistrationWindow $window;

    protected RegistrationWindow $otherWindow;

    protected User $applicant;

    protected User $organizationAdmin;

    protected User $otherOrganizationAdmin;

    protected User $federationAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->federation = Federation::factory()->create(['name' => 'Northgate Soccer Federation', 'code' => 'NSF']);
        $this->season = Season::factory()->create(['federation_id' => $this->federation->getKey(), 'label' => '2026/27']);
        $this->organization = MemberOrganization::factory()->create(['federation_id' => $this->federation->getKey()]);
        $this->otherOrganization = MemberOrganization::factory()->create(['federation_id' => $this->federation->getKey()]);

        $this->applicant = User::factory()->create();
        $this->organizationAdmin = User::factory()->create();
        $this->otherOrganizationAdmin = User::factory()->create();
        $this->federationAdmin = User::factory()->create();

        $this->organization->administrators()->attach($this->organizationAdmin);
        $this->otherOrganization->administrators()->attach($this->otherOrganizationAdmin);
        $this->federation->administrators()->attach($this->federationAdmin);

        $this->window = RegistrationWindow::factory()->create([
            'member_organization_id' => $this->organization->getKey(),
            'season_id' => $this->season->getKey(),
            'created_by_user_id' => $this->organizationAdmin->getKey(),
        ]);
        $this->otherWindow = RegistrationWindow::factory()->create([
            'member_organization_id' => $this->otherOrganization->getKey(),
            'season_id' => $this->season->getKey(),
        ]);
    }

    /**
     * A draft application that is complete enough to submit: date of birth
     * and every required document's metadata. Pass complete: false for a bare draft.
     */
    protected function startApplication(ApplicationRole $role = ApplicationRole::PARTICIPANT, ?string $idempotencyKey = null, bool $complete = true): RegistrationApplication
    {
        $application = app(StartApplication::class)->execute(
            $this->applicant,
            $this->window,
            $role,
            $idempotencyKey,
        );

        if ($complete) {
            $this->complete($application);
        }

        return $application;
    }

    protected function complete(RegistrationApplication $application): RegistrationApplication
    {
        $application->forceFill(['date_of_birth' => '1998-04-12'])->save();

        foreach (DocumentType::requiredFor($application->role) as $type) {
            if ($application->documents()->where('document_type', $type->value)->exists()) {
                continue;
            }

            ApplicationDocument::factory()->create([
                'registration_application_id' => $application->getKey(),
                'document_type' => $type,
            ]);
        }

        return $application;
    }

    protected function transition(RegistrationApplication $application, ApplicationStatus $to, User $actor, ?string $reason = null, ?string $requestId = null): RegistrationApplication
    {
        return app(TransitionApplication::class)->execute($application, $to, $actor, $reason, $requestId);
    }

    /**
     * A submitted application that a reviewer has picked up.
     */
    protected function applicationUnderReview(): RegistrationApplication
    {
        $application = $this->startApplication();
        $this->transition($application, ApplicationStatus::SUBMITTED, $this->applicant);

        return $this->transition($application, ApplicationStatus::UNDER_REVIEW, $this->organizationAdmin);
    }
}
