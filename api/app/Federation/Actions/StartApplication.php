<?php

namespace App\Federation\Actions;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Exceptions\DuplicateApplicationException;
use App\Federation\Exceptions\RoleNotOfferedException;
use App\Federation\Exceptions\WindowClosedException;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;
use App\Federation\Support\AuditRecorder;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a DRAFT application inside an open registration window, or returns
 * the existing one when the same idempotency key is presented again.
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
        RegistrationWindow $window,
        ApplicationRole $role,
        ?string $idempotencyKey = null,
        ?string $requestId = null,
    ): RegistrationApplication {
        if (! $window->isOpenAt(now())) {
            throw new WindowClosedException;
        }

        if (! $window->offers($role)) {
            throw new RoleNotOfferedException;
        }

        if ($idempotencyKey !== null) {
            $existing = RegistrationApplication::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($applicant, $window, $role, $idempotencyKey, $requestId) {
            $application = new RegistrationApplication([
                'member_organization_id' => $window->member_organization_id,
                'season_id' => $window->season_id,
                'registration_window_id' => $window->getKey(),
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
