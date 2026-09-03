<?php

namespace App\Federation\Outbox\Consumers;

use App\Federation\LearningCenter\CredentialSnapshots;
use App\Federation\Models\OutboxEvent;
use App\Federation\Outbox\OutboxConsumer;
use App\Models\User;

/**
 * After an approval, ask the Learning Center once. A slow or absent provider
 * throws, the job retries with backoff and finally parks the event: this is
 * INCIDENT-003's path. The provider call is a read, so a retry after a crash
 * between the call and the commit is harmless.
 */
final class RefreshCredentialsForApproval implements OutboxConsumer
{
    public function __construct(private readonly CredentialSnapshots $snapshots) {}

    public function handle(OutboxEvent $event): void
    {
        $applicant = User::query()->withoutGlobalScopes()->find($event->payload['applicant_user_id'] ?? null);

        if (! $applicant instanceof User) {
            return;
        }

        $actor = isset($event->payload['actor_user_id'])
            ? User::query()->withoutGlobalScopes()->find($event->payload['actor_user_id'])
            : null;

        $this->snapshots->refresh($applicant, $actor, $event->request_id);
    }
}
