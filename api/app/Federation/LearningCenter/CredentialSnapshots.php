<?php

namespace App\Federation\LearningCenter;

use App\Federation\LearningCenter\Exceptions\LearningCenterMemberNotFoundException;
use App\Federation\Models\CredentialSnapshot;
use App\Federation\Support\AuditRecorder;
use App\Models\User;

/**
 * The one writer of credential snapshots. A refresh asks the provider once,
 * stores the answer (or "not found"), and audits a change of eligibility
 * status. Unavailability propagates: the caller decides whether that is a
 * 503 (explicit refresh), a log line (after approval) or a count (reconciliation).
 */
final class CredentialSnapshots
{
    public function __construct(
        private readonly CredentialsClient $client,
        private readonly AuditRecorder $audit,
        private readonly string $contract,
    ) {}

    public function current(User $user): ?CredentialSnapshot
    {
        return CredentialSnapshot::query()->where('user_id', $user->getKey())->first();
    }

    public function refresh(User $user, ?User $actor = null, ?string $requestId = null): RefreshResult
    {
        $existing = $this->current($user);
        $previousStatus = $existing?->eligibility_status;

        $status = CredentialSnapshot::STATUS_NOT_FOUND;
        $payload = null;
        $asOf = null;

        if ($user->oidc_subject !== null) {
            try {
                $facts = $this->client->fetch($user->oidc_subject);
                $status = $facts->eligibilityStatus;
                $payload = $facts->toArray();
                $asOf = $facts->asOf;
            } catch (LearningCenterMemberNotFoundException) {
                // Recorded as "not found" so reads do not ask again before reconciliation.
            }
        }

        $snapshot = CredentialSnapshot::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'subject' => $user->oidc_subject,
                'contract' => $this->contract,
                'eligibility_status' => $status,
                'payload' => $payload,
                'source_as_of' => $asOf,
                'fetched_at' => now()->toImmutable(),
            ],
        );

        $changed = $previousStatus !== null && $previousStatus !== $status;

        if ($changed) {
            $this->audit->record(
                actor: $actor,
                action: 'credentials.changed',
                auditable: $user,
                previous: ['eligibility_status' => $previousStatus],
                new: ['eligibility_status' => $status, 'as_of' => $asOf?->toIso8601String()],
                requestId: $requestId,
            );
        } elseif ($previousStatus === null) {
            $this->audit->record(
                actor: $actor,
                action: 'credentials.recorded',
                auditable: $user,
                new: ['eligibility_status' => $status, 'as_of' => $asOf?->toIso8601String()],
                requestId: $requestId,
            );
        }

        return new RefreshResult($snapshot, $changed);
    }
}
