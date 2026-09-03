<?php

namespace App\Federation\Outbox;

/**
 * The facts this repository publishes (ADR-0010). Names are part of the
 * contract with consumers and with the processed-events ledger.
 */
final class OutboxEventTypes
{
    public const APPLICATION_SUBMITTED = 'application.submitted';

    public const APPLICATION_APPROVED = 'application.approved';

    public const APPLICATION_REJECTED = 'application.rejected';

    public const CREDENTIALS_CHANGED = 'credentials.changed';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::APPLICATION_SUBMITTED,
            self::APPLICATION_APPROVED,
            self::APPLICATION_REJECTED,
            self::CREDENTIALS_CHANGED,
        ];
    }
}
