<?php

namespace App\Federation\Outbox;

use App\Federation\Outbox\Consumers\RecordNotification;
use App\Federation\Outbox\Consumers\RefreshCredentialsForApproval;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Which consumer runs for which event type. Names are stable identifiers in
 * the processed-events ledger and in job payloads; renaming one is a migration.
 */
final class ConsumerRegistry
{
    /** @var array<string, class-string<OutboxConsumer>> */
    private const CONSUMERS = [
        'notifications' => RecordNotification::class,
        'credential-refresh' => RefreshCredentialsForApproval::class,
    ];

    /** @var array<string, list<string>> event type => consumer names */
    private const SUBSCRIPTIONS = [
        OutboxEventTypes::APPLICATION_SUBMITTED => ['notifications'],
        OutboxEventTypes::APPLICATION_APPROVED => ['notifications', 'credential-refresh'],
        OutboxEventTypes::APPLICATION_REJECTED => ['notifications'],
        OutboxEventTypes::CREDENTIALS_CHANGED => ['notifications'],
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<string>
     */
    public function consumersFor(string $eventType): array
    {
        return self::SUBSCRIPTIONS[$eventType] ?? [];
    }

    public function get(string $name): OutboxConsumer
    {
        if (! isset(self::CONSUMERS[$name])) {
            throw new InvalidArgumentException("Unknown outbox consumer {$name}");
        }

        return $this->container->make(self::CONSUMERS[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys(self::CONSUMERS);
    }
}
