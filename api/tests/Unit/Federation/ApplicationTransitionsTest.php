<?php

namespace Tests\Unit\Federation;

use App\Federation\Enums\ApplicationActor;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\StateMachine\ApplicationTransitions;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The transition table is documentation that executes. These tests pin every
 * legal pair, every actor, every reason requirement, and assert that all
 * other pairs are illegal. Changing a rule means changing this file on purpose.
 */
class ApplicationTransitionsTest extends TestCase
{
    /**
     * @var array<string, array<int, string>>
     */
    private const LEGAL = [
        'draft' => ['submitted', 'cancelled'],
        'submitted' => ['under_review', 'cancelled'],
        'under_review' => ['needs_information', 'approved', 'rejected'],
        'needs_information' => ['submitted', 'cancelled'],
        'approved' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public function test_the_transition_table_matches_the_documented_rules(): void
    {
        foreach (ApplicationStatus::cases() as $from) {
            $targets = array_map(
                fn (ApplicationStatus $status) => $status->value,
                ApplicationTransitions::legalTargets($from),
            );

            $this->assertSame(self::LEGAL[$from->value], $targets, "Targets of {$from->value}");
        }
    }

    public function test_every_pair_not_in_the_table_is_illegal(): void
    {
        $checked = 0;

        foreach (ApplicationStatus::cases() as $from) {
            foreach (ApplicationStatus::cases() as $to) {
                $expected = in_array($to->value, self::LEGAL[$from->value], true);

                $this->assertSame(
                    $expected,
                    ApplicationTransitions::isLegal($from, $to),
                    "{$from->value} → {$to->value}",
                );
                $checked++;
            }
        }

        $this->assertSame(49, $checked);
    }

    public function test_terminal_statuses_have_no_outgoing_transitions(): void
    {
        foreach (ApplicationStatus::cases() as $status) {
            $this->assertSame(
                $status->isTerminal(),
                ApplicationTransitions::legalTargets($status) === [],
                $status->value,
            );
        }
    }

    public function test_applicants_move_their_own_application_and_reviewers_decide(): void
    {
        $applicant = ApplicationActor::APPLICANT;
        $reviewer = ApplicationActor::REVIEWER;

        $this->assertSame($applicant, ApplicationTransitions::actorFor(ApplicationStatus::DRAFT, ApplicationStatus::SUBMITTED));
        $this->assertSame($applicant, ApplicationTransitions::actorFor(ApplicationStatus::DRAFT, ApplicationStatus::CANCELLED));
        $this->assertSame($applicant, ApplicationTransitions::actorFor(ApplicationStatus::SUBMITTED, ApplicationStatus::CANCELLED));
        $this->assertSame($applicant, ApplicationTransitions::actorFor(ApplicationStatus::NEEDS_INFORMATION, ApplicationStatus::SUBMITTED));
        $this->assertSame($applicant, ApplicationTransitions::actorFor(ApplicationStatus::NEEDS_INFORMATION, ApplicationStatus::CANCELLED));

        $this->assertSame($reviewer, ApplicationTransitions::actorFor(ApplicationStatus::SUBMITTED, ApplicationStatus::UNDER_REVIEW));
        $this->assertSame($reviewer, ApplicationTransitions::actorFor(ApplicationStatus::UNDER_REVIEW, ApplicationStatus::NEEDS_INFORMATION));
        $this->assertSame($reviewer, ApplicationTransitions::actorFor(ApplicationStatus::UNDER_REVIEW, ApplicationStatus::APPROVED));
        $this->assertSame($reviewer, ApplicationTransitions::actorFor(ApplicationStatus::UNDER_REVIEW, ApplicationStatus::REJECTED));
    }

    public function test_only_information_requests_and_rejections_require_a_reason(): void
    {
        foreach (ApplicationStatus::cases() as $from) {
            foreach (ApplicationTransitions::legalTargets($from) as $to) {
                $expected = in_array($to, [ApplicationStatus::NEEDS_INFORMATION, ApplicationStatus::REJECTED], true);

                $this->assertSame($expected, ApplicationTransitions::requiresReason($from, $to), "{$from->value} → {$to->value}");
            }
        }
    }

    public function test_asking_for_the_rule_of_an_illegal_pair_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);

        ApplicationTransitions::actorFor(ApplicationStatus::APPROVED, ApplicationStatus::DRAFT);
    }
}
