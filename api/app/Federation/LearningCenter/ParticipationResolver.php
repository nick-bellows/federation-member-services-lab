<?php

namespace App\Federation\LearningCenter;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Enums\Participation;
use App\Federation\Models\RegistrationApplication;

/**
 * Participation = approved application + the provider's eligibility + a valid
 * role credential where the role needs one. Reads the stored snapshot only;
 * the provider is never called on a read (ADR-0009). Credential findings are
 * reported for unapproved applications too, so a reviewer sees them before
 * deciding; the status is then "blocked" with "not_approved" first.
 */
final class ParticipationResolver
{
    public function __construct(
        private readonly string $contract,
        private readonly int $snapshotTtlMinutes,
    ) {}

    public function for(RegistrationApplication $application): ParticipationStatus
    {
        $approved = $application->status === ApplicationStatus::APPROVED;
        $snapshot = $application->applicant?->credentialSnapshot;
        $reasons = $approved ? [] : [ParticipationStatus::REASON_NOT_APPROVED];

        if ($snapshot === null) {
            $reasons[] = ParticipationStatus::REASON_NO_SNAPSHOT;

            return new ParticipationStatus($approved ? Participation::UNKNOWN : Participation::BLOCKED, $reasons, null, null, false);
        }

        $stale = $snapshot->isStale($this->snapshotTtlMinutes);

        if (! $snapshot->hasFacts()) {
            $reasons[] = ParticipationStatus::REASON_NO_RECORD;

            return new ParticipationStatus($approved ? Participation::UNKNOWN : Participation::BLOCKED, $reasons, null, $snapshot->fetched_at, $stale);
        }

        $facts = CredentialFacts::fromArray($snapshot->payload, $this->contract);

        if ($facts->eligibilityStatus === CredentialFacts::STATUS_SUSPENDED) {
            $reasons[] = ParticipationStatus::REASON_HOLD;
        } elseif ($facts->eligibilityStatus === CredentialFacts::STATUS_LAPSED) {
            $reasons[] = ParticipationStatus::REASON_LAPSED;
        }

        if ($this->roleNeedsCredential($application->role) && ! $facts->hasValidRoleCredential($application->role->value)) {
            $reasons[] = ParticipationStatus::REASON_ROLE_CREDENTIAL;
        }

        return new ParticipationStatus(
            $reasons === [] ? Participation::MAY_PARTICIPATE : Participation::BLOCKED,
            $reasons,
            $snapshot->source_as_of,
            $snapshot->fetched_at,
            $stale,
        );
    }

    private function roleNeedsCredential(ApplicationRole $role): bool
    {
        return in_array($role, [ApplicationRole::COACH, ApplicationRole::REFEREE], true);
    }
}
