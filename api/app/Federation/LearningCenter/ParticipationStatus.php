<?php

namespace App\Federation\LearningCenter;

use App\Federation\Enums\Participation;
use Carbon\CarbonImmutable;

/**
 * The answer to "may this person take part", with its age and every reason
 * it is not a yes. Serialised as the `participation` attribute.
 */
final class ParticipationStatus
{
    public const REASON_NOT_APPROVED = 'not_approved';

    public const REASON_NO_SNAPSHOT = 'no_snapshot';

    public const REASON_NO_RECORD = 'no_learning_center_record';

    public const REASON_HOLD = 'hold_active';

    public const REASON_LAPSED = 'credential_lapsed';

    public const REASON_ROLE_CREDENTIAL = 'role_credential_missing';

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly Participation $status,
        public readonly array $reasons,
        public readonly ?CarbonImmutable $asOf,
        public readonly ?CarbonImmutable $fetchedAt,
        public readonly bool $stale,
    ) {}

    /**
     * @return array{status: string, reasons: list<string>, asOf: ?string, fetchedAt: ?string, stale: bool}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reasons' => $this->reasons,
            'asOf' => $this->asOf?->toIso8601String(),
            'fetchedAt' => $this->fetchedAt?->toIso8601String(),
            'stale' => $this->stale,
        ];
    }
}
