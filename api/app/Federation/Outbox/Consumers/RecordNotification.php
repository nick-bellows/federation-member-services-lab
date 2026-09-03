<?php

namespace App\Federation\Outbox\Consumers;

use App\Federation\Models\FederationNotification;
use App\Federation\Models\OutboxEvent;
use App\Federation\Outbox\OutboxConsumer;

/**
 * Writes one notification row for the person an event concerns. A row is
 * what the stack can show without a mail service; a mailer would read the
 * same rows. Idempotent twice over: the consumer ledger, and the unique key
 * on (user, event).
 */
final class RecordNotification implements OutboxConsumer
{
    public function handle(OutboxEvent $event): void
    {
        $userId = $event->payload['applicant_user_id'] ?? $event->payload['user_id'] ?? null;

        if ($userId === null) {
            return;
        }

        FederationNotification::query()->firstOrCreate(
            ['user_id' => (int) $userId, 'event_id' => $event->event_id],
            [
                'template' => $event->event_type,
                'payload' => $this->summary($event),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(OutboxEvent $event): array
    {
        return match ($event->event_type) {
            'credentials.changed' => [
                'previous' => $event->payload['previous'] ?? null,
                'current' => $event->payload['current'] ?? null,
                'as_of' => $event->payload['as_of'] ?? null,
            ],
            default => [
                'application_id' => $event->aggregate_id,
                'status' => $event->payload['to'] ?? null,
                'reason' => $event->payload['reason'] ?? null,
                'role' => $event->payload['role'] ?? null,
            ],
        };
    }
}
