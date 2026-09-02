<?php

namespace App\Federation\Actions;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Exceptions\DuplicateApplicationException;
use App\Federation\Exceptions\SeasonNotInFederationException;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\Season;
use App\Federation\Support\AuditRecorder;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a DRAFT application, or returns the existing one when the same
 * idempotency key is presented again.
 *
 * Duplicate protection has two layers: this action checks for a live
 * application first and raises a domain exception; the unique active_key
 * column is the backstop when two requests race, and its violation is
 * translated into the same exception.
 */
class StartApplication
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function execute(
        User $applicant,
        MemberOrganization $organization,
        Season $season,
        ApplicationRole $role,
        ?string $idempotencyKey = null,
        ?string $requestId = null,
    ): RegistrationApplication {
        if ((int) $season->federation_id !== (int) $organization->federation_id) {
            throw new SeasonNotInFederationException;
        }

        if ($idempotencyKey !== null) {
            $existing = RegistrationApplication::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($applicant, $organization, $season, $role, $idempotencyKey, $requestId) {
            $application = new RegistrationApplication([
                'member_organization_id' => $organization->getKey(),
                'season_id' => $season->getKey(),
                'applicant_user_id' => $applicant->getKey(),
                'role' => $role,
                'idempotency_key' => $idempotencyKey,
            ]);

            if (RegistrationApplication::query()->where('active_key', $application->activeKey())->exists()) {
                throw new DuplicateApplicationException;
            }

            try {
                $application->save();
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateApplicationException;
            }

            $this->audit->record(
                actor: $applicant,
                action: 'application.created',
                auditable: $application,
                previous: null,
                new: ['status' => ApplicationStatus::DRAFT->value, 'role' => $role->value],
                requestId: $requestId,
            );

            return $application;
        });
    }
}
