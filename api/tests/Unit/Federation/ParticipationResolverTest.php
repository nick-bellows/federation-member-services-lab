<?php

namespace Tests\Unit\Federation;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Enums\Participation;
use App\Federation\LearningCenter\ParticipationResolver;
use App\Federation\LearningCenter\ParticipationStatus;
use App\Federation\Models\CredentialSnapshot;
use App\Federation\Models\RegistrationApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Participation is derived from an application and a snapshot, nothing else.
 * Models are built in memory; no provider, no HTTP, no clock other than the
 * snapshot's own timestamps.
 */
class ParticipationResolverTest extends TestCase
{
    private const CONTRACT = 'learning-center.credentials.v1';

    private const TTL = 60;

    public function test_an_approved_participant_with_an_eligible_snapshot_may_participate(): void
    {
        $status = $this->resolve(ApplicationStatus::APPROVED, ApplicationRole::PARTICIPANT, 'alex-eligible.json');

        $this->assertSame(Participation::MAY_PARTICIPATE, $status->status);
        $this->assertSame([], $status->reasons);
        $this->assertFalse($status->stale);
        $this->assertSame('2026-09-03T05:00:00+00:00', $status->asOf?->toIso8601String());
    }

    public function test_an_approved_coach_needs_a_valid_coach_credential(): void
    {
        $withCredential = $this->resolve(ApplicationStatus::APPROVED, ApplicationRole::COACH, 'alex-eligible.json');
        $this->assertSame(Participation::MAY_PARTICIPATE, $withCredential->status);

        // Alex is eligible for the provider, but holds no referee credential.
        $withoutCredential = $this->resolve(ApplicationStatus::APPROVED, ApplicationRole::REFEREE, 'alex-eligible.json');
        $this->assertSame(Participation::BLOCKED, $withoutCredential->status);
        $this->assertSame([ParticipationStatus::REASON_ROLE_CREDENTIAL], $withoutCredential->reasons);
    }

    public function test_a_hold_blocks_regardless_of_credentials(): void
    {
        $status = $this->resolve(ApplicationStatus::APPROVED, ApplicationRole::REFEREE, 'sam-suspended.json');

        $this->assertSame(Participation::BLOCKED, $status->status);
        $this->assertSame([ParticipationStatus::REASON_HOLD], $status->reasons);
    }

    public function test_a_lapsed_credential_blocks_with_both_reasons(): void
    {
        $status = $this->resolve(ApplicationStatus::APPROVED, ApplicationRole::REFEREE, 'riley-lapsed.json');

        $this->assertSame(Participation::BLOCKED, $status->status);
        $this->assertSame([ParticipationStatus::REASON_LAPSED, ParticipationStatus::REASON_ROLE_CREDENTIAL], $status->reasons);
    }

    public function test_an_unapproved_application_reports_findings_but_never_participates(): void
    {
        $status = $this->resolve(ApplicationStatus::UNDER_REVIEW, ApplicationRole::PARTICIPANT, 'alex-eligible.json');

        $this->assertSame(Participation::BLOCKED, $status->status);
        $this->assertSame([ParticipationStatus::REASON_NOT_APPROVED], $status->reasons);

        $blocked = $this->resolve(ApplicationStatus::UNDER_REVIEW, ApplicationRole::REFEREE, 'sam-suspended.json');
        $this->assertSame([ParticipationStatus::REASON_NOT_APPROVED, ParticipationStatus::REASON_HOLD], $blocked->reasons);
    }

    public function test_no_snapshot_is_unknown_for_an_approved_application(): void
    {
        $status = $this->resolve(ApplicationStatus::APPROVED, ApplicationRole::PARTICIPANT, null);

        $this->assertSame(Participation::UNKNOWN, $status->status);
        $this->assertSame([ParticipationStatus::REASON_NO_SNAPSHOT], $status->reasons);
        $this->assertNull($status->fetchedAt);
    }

    public function test_a_not_found_snapshot_is_unknown_with_its_age(): void
    {
        $status = $this->resolve(ApplicationStatus::APPROVED, ApplicationRole::PARTICIPANT, 'not-found');

        $this->assertSame(Participation::UNKNOWN, $status->status);
        $this->assertSame([ParticipationStatus::REASON_NO_RECORD], $status->reasons);
        $this->assertNotNull($status->fetchedAt);
    }

    public function test_a_snapshot_older_than_the_limit_is_marked_stale_and_still_answers(): void
    {
        $status = $this->resolve(ApplicationStatus::APPROVED, ApplicationRole::PARTICIPANT, 'alex-eligible.json', fetchedMinutesAgo: self::TTL + 1);

        $this->assertSame(Participation::MAY_PARTICIPATE, $status->status);
        $this->assertTrue($status->stale);
    }

    public function test_the_serialised_shape_is_stable(): void
    {
        $array = $this->resolve(ApplicationStatus::APPROVED, ApplicationRole::PARTICIPANT, 'alex-eligible.json')->toArray();

        $this->assertSame(['status', 'reasons', 'asOf', 'fetchedAt', 'stale'], array_keys($array));
    }

    private function resolve(ApplicationStatus $status, ApplicationRole $role, ?string $fixture, int $fetchedMinutesAgo = 0): ParticipationStatus
    {
        $user = new User;
        $user->setRelation('credentialSnapshot', $fixture === null ? null : $this->snapshot($fixture, $fetchedMinutesAgo));

        $application = new RegistrationApplication;
        $application->forceFill(['status' => $status, 'role' => $role]);
        $application->setRelation('applicant', $user);

        return (new ParticipationResolver(self::CONTRACT, self::TTL))->for($application);
    }

    private function snapshot(string $fixture, int $fetchedMinutesAgo): CredentialSnapshot
    {
        $fetchedAt = CarbonImmutable::now()->subMinutes($fetchedMinutesAgo);

        if ($fixture === 'not-found') {
            return (new CredentialSnapshot)->forceFill([
                'contract' => self::CONTRACT,
                'eligibility_status' => CredentialSnapshot::STATUS_NOT_FOUND,
                'payload' => null,
                'source_as_of' => null,
                'fetched_at' => $fetchedAt,
            ]);
        }

        $payload = CredentialFactsTest::fixture($fixture);

        return (new CredentialSnapshot)->forceFill([
            'contract' => self::CONTRACT,
            'eligibility_status' => $payload['eligibility']['status'],
            'payload' => $payload,
            'source_as_of' => CarbonImmutable::parse($payload['as_of']),
            'fetched_at' => $fetchedAt,
        ]);
    }
}
