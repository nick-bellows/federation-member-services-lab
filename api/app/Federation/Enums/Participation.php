<?php

namespace App\Federation\Enums;

/**
 * Derived on read from an application's status and the applicant's credential
 * snapshot; never stored, never editable (DOMAIN_MODEL.md, "Derived status").
 */
enum Participation: string
{
    case MAY_PARTICIPATE = 'may_participate';
    case BLOCKED = 'blocked';
    case UNKNOWN = 'unknown';
}
