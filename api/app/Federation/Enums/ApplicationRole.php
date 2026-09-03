<?php

namespace App\Federation\Enums;

/**
 * The member roles a person can register for. Administrative roles are not
 * applied for; they are granted (see federation_administrators and
 * organization_administrators).
 */
enum ApplicationRole: string
{
    case PARTICIPANT = 'participant';
    case COACH = 'coach';
    case REFEREE = 'referee';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
