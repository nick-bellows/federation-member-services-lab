<?php

namespace App\Federation\Enums;

/**
 * The kinds of document an application can carry, and which of them each
 * role requires before submission. Rules live here, in one place.
 */
enum DocumentType: string
{
    case PROOF_OF_AGE = 'proof_of_age';
    case PHOTO = 'photo';
    case COACHING_LICENCE = 'coaching_licence';
    case REFEREE_CERTIFICATE = 'referee_certificate';
    case BACKGROUND_CHECK_CONSENT = 'background_check_consent';

    /**
     * @return array<int, self>
     */
    public static function requiredFor(ApplicationRole $role): array
    {
        return match ($role) {
            ApplicationRole::PARTICIPANT => [self::PROOF_OF_AGE, self::PHOTO],
            ApplicationRole::COACH => [self::PROOF_OF_AGE, self::PHOTO, self::COACHING_LICENCE, self::BACKGROUND_CHECK_CONSENT],
            ApplicationRole::REFEREE => [self::PROOF_OF_AGE, self::PHOTO, self::REFEREE_CERTIFICATE, self::BACKGROUND_CHECK_CONSENT],
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
