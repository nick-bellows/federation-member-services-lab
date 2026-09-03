<?php

namespace App\Federation\StateMachine;

use App\Federation\Enums\ApplicationActor;
use App\Federation\Enums\ApplicationStatus;

/**
 * The single source of truth for legal application transitions.
 *
 * Every transition names the actor that may perform it and whether a reason
 * is required. Anything not listed here is illegal, whatever the caller's
 * privileges. Controllers never set a status; they ask TransitionApplication.
 */
final class ApplicationTransitions
{
    /**
     * @var array<string, array<string, array{actor: ApplicationActor, reason_required: bool}>>
     */
    private const TABLE = [
        'draft' => [
            'submitted' => ['actor' => ApplicationActor::APPLICANT, 'reason_required' => false],
            'cancelled' => ['actor' => ApplicationActor::APPLICANT, 'reason_required' => false],
        ],
        'submitted' => [
            'under_review' => ['actor' => ApplicationActor::REVIEWER, 'reason_required' => false],
            'cancelled' => ['actor' => ApplicationActor::APPLICANT, 'reason_required' => false],
        ],
        'under_review' => [
            'needs_information' => ['actor' => ApplicationActor::REVIEWER, 'reason_required' => true],
            'approved' => ['actor' => ApplicationActor::REVIEWER, 'reason_required' => false],
            'rejected' => ['actor' => ApplicationActor::REVIEWER, 'reason_required' => true],
        ],
        'needs_information' => [
            'submitted' => ['actor' => ApplicationActor::APPLICANT, 'reason_required' => false],
            'cancelled' => ['actor' => ApplicationActor::APPLICANT, 'reason_required' => false],
        ],
        'approved' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public static function isLegal(ApplicationStatus $from, ApplicationStatus $to): bool
    {
        return isset(self::TABLE[$from->value][$to->value]);
    }

    public static function actorFor(ApplicationStatus $from, ApplicationStatus $to): ApplicationActor
    {
        return self::rule($from, $to)['actor'];
    }

    public static function requiresReason(ApplicationStatus $from, ApplicationStatus $to): bool
    {
        return self::rule($from, $to)['reason_required'];
    }

    /**
     * @return array<int, ApplicationStatus>
     */
    public static function legalTargets(ApplicationStatus $from): array
    {
        return array_map(
            fn (string $value) => ApplicationStatus::from($value),
            array_keys(self::TABLE[$from->value]),
        );
    }

    /**
     * @return array{actor: ApplicationActor, reason_required: bool}
     */
    private static function rule(ApplicationStatus $from, ApplicationStatus $to): array
    {
        if (! self::isLegal($from, $to)) {
            throw new \LogicException("No transition rule from {$from->value} to {$to->value}.");
        }

        return self::TABLE[$from->value][$to->value];
    }
}
