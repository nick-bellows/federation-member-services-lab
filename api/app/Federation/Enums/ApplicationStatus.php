<?php

namespace App\Federation\Enums;

enum ApplicationStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case UNDER_REVIEW = 'under_review';
    case NEEDS_INFORMATION = 'needs_information';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    /**
     * A terminal status has no legal outgoing transition.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::APPROVED, self::REJECTED, self::CANCELLED => true,
            default => false,
        };
    }

    /**
     * Whether an application in this status counts against the
     * one-live-application rule (see RegistrationApplication::activeKey).
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::REJECTED, self::CANCELLED => false,
            default => true,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
