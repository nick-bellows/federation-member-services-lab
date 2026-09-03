<?php

namespace Tests\Feature\Federation;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\LearningCenter\CredentialsClient;
use App\Federation\LearningCenter\CredentialSnapshots;
use App\Federation\Models\AuditEntry;
use App\Federation\Models\CredentialSnapshot;
use App\Federation\Models\FederationNotification;
use App\Federation\Models\OutboxEvent;
use App\Federation\Models\ProcessedEvent;
use App\Federation\Outbox\ConsumerRegistry;
use App\Federation\Outbox\OutboxEventTypes;
use App\Federation\Outbox\OutboxRecorder;
use App\Federation\Outbox\ProcessOutboxEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Unit\Federation\CredentialFactsTest;

/**
 * The outbox end to end on the database queue: facts written with the state
 * change, relayed as one job per consumer, consumed once, retried with
 * backoff, parked with a reason, replayed. INCIDENT-003 is the failing path.
 */
class OutboxTest extends FederationTestCase
{
    private const PROVIDER = 'http://learning-center.test';

    private bool $providerDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'database');
        config()->set('learning_center.base_url', self::PROVIDER);
        config()->set('learning_center.token.endpoint', 'http://oidc.test/default/token');
        config()->set('learning_center.token.client_secret', 'test-only');

        $this->app->forgetInstance(CredentialsClient::class);
        $this->applicant->forceFill(['oidc_issuer' => 'https://issuer.test', 'oidc_subject' => 'mock|alex'])->save();

        Http::fake([
            'http://oidc.test/*' => Http::response(['access_token' => 'service-token', 'expires_in' => 300]),
            self::PROVIDER.'/*' => function (Request $request) {
                if ($this->providerDown) {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return Http::response(CredentialFactsTest::fixture('alex-eligible.json'));
            },
        ]);
    }

    public function test_an_outbox_event_cannot_be_recorded_outside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        app(OutboxRecorder::class)->record(OutboxEventTypes::APPLICATION_SUBMITTED, $this->applicant, []);
    }

    public function test_a_rolled_back_transaction_leaves_no_event_behind(): void
    {
        try {
            DB::transaction(function (): void {
                app(OutboxRecorder::class)->record(OutboxEventTypes::APPLICATION_SUBMITTED, $this->applicant, ['x' => 1]);
                throw new \RuntimeException('after the fact was written');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_transitions_write_facts_only_for_the_published_types(): void
    {
        $application = $this->applicationUnderReview();
        $this->transition($application, ApplicationStatus::NEEDS_INFORMATION, $this->organizationAdmin, 'Missing licence');
        $this->transition($application, ApplicationStatus::SUBMITTED, $this->applicant);
        $this->transition($application, ApplicationStatus::UNDER_REVIEW, $this->organizationAdmin);
        $this->transition($application, ApplicationStatus::APPROVED, $this->organizationAdmin, null, 'req-9');

        $types = OutboxEvent::query()->orderBy('id')->pluck('event_type')->all();
        $this->assertSame(['application.submitted', 'application.submitted', 'application.approved'], $types);

        $approved = OutboxEvent::query()->where('event_type', 'application.approved')->sole();
        $this->assertSame($application->getKey(), $approved->aggregate_id);
        $this->assertSame('req-9', $approved->request_id);
        $this->assertSame($this->applicant->getKey(), $approved->payload['applicant_user_id']);
        $this->assertNull($approved->published_at);
    }

    public function test_the_relay_dispatches_one_job_per_consumer_and_marks_the_row_published(): void
    {
        Queue::fake();
        $this->transition($this->applicationUnderReview(), ApplicationStatus::APPROVED, $this->organizationAdmin);

        $this->artisan('federation:outbox-relay', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(ProcessOutboxEvent::class, 3);
        Queue::assertPushed(ProcessOutboxEvent::class, fn (ProcessOutboxEvent $job) => $job->consumer === 'credential-refresh');
        $this->assertSame(0, OutboxEvent::query()->unpublished()->count());

        $this->artisan('federation:outbox-relay', ['--once' => true])->assertSuccessful();
        Queue::assertPushed(ProcessOutboxEvent::class, 3);
    }

    public function test_the_worker_delivers_an_approval_to_both_consumers_exactly_once(): void
    {
        $application = $this->applicationUnderReview();
        $this->transition($application, ApplicationStatus::APPROVED, $this->organizationAdmin);

        $this->artisan('federation:work', ['--once' => true])->assertSuccessful();

        $this->assertSame('eligible', CredentialSnapshot::query()->sole()->eligibility_status);
        $notification = FederationNotification::query()->where('template', 'application.approved')->sole();
        $this->assertSame($this->applicant->getKey(), $notification->user_id);
        $this->assertSame('approved', $notification->payload['status']);
        $this->assertSame(3, ProcessedEvent::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());

        // Deliver again: the ledger makes the second delivery a no-op.
        $event = OutboxEvent::query()->where('event_type', 'application.approved')->sole();
        (new ProcessOutboxEvent($event->event_id, 'notifications'))->handle(app(ConsumerRegistry::class));
        (new ProcessOutboxEvent($event->event_id, 'credential-refresh'))->handle(app(ConsumerRegistry::class));

        $this->assertSame(1, FederationNotification::query()->where('template', 'application.approved')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'credentials.recorded')->count());
        Http::assertSentCount(2);
    }

    public function test_a_credential_change_is_itself_a_fact_with_a_notification(): void
    {
        $this->transition($this->applicationUnderReview(), ApplicationStatus::APPROVED, $this->organizationAdmin);
        $this->artisan('federation:work', ['--once' => true])->assertSuccessful();

        CredentialSnapshot::query()->sole()->forceFill(['eligibility_status' => 'suspended'])->save();
        app(CredentialSnapshots::class)->refresh($this->applicant);
        $this->artisan('federation:work', ['--once' => true])->assertSuccessful();

        $change = OutboxEvent::query()->where('event_type', 'credentials.changed')->sole();
        $this->assertSame(['previous' => 'suspended', 'current' => 'eligible'], [
            'previous' => $change->payload['previous'],
            'current' => $change->payload['current'],
        ]);
        $this->assertSame(1, FederationNotification::query()->where('template', 'credentials.changed')->count());
    }

    public function test_incident_003_the_worker_fails_after_an_approval_and_the_event_is_parked_then_replayed(): void
    {
        $this->providerDown = true;
        $application = $this->applicationUnderReview();
        $this->transition($application, ApplicationStatus::APPROVED, $this->organizationAdmin);

        // Four attempts with backoff 2 s, 10 s, 60 s; time moves past each window.
        foreach ([0, 3, 11, 61] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->artisan('federation:work', ['--once' => true])->assertSuccessful();
        }

        $event = OutboxEvent::query()->where('event_type', 'application.approved')->sole();
        $this->assertNotNull($event->failed_at);
        // Attempts count every consumer's try on the row: one for notifications, four for the refresh.
        $this->assertSame(5, $event->attempts);
        $this->assertStringStartsWith('credential-refresh: ', $event->last_error);
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, CredentialSnapshot::query()->count());
        // The other consumer of the same event was not held hostage.
        $this->assertSame(1, FederationNotification::query()->where('template', 'application.approved')->count());
        $this->artisan('federation:outbox-status')->assertFailed();

        // The provider recovers; an operator replays the parked event.
        $this->providerDown = false;
        $this->artisan('federation:outbox-replay', ['--all' => true])->expectsOutputToContain('replayed 1')->assertSuccessful();
        $this->artisan('federation:work', ['--once' => true])->assertSuccessful();

        $this->assertNull($event->fresh()->failed_at);
        $this->assertSame('eligible', CredentialSnapshot::query()->sole()->eligibility_status);
        $this->assertSame(1, FederationNotification::query()->where('template', 'application.approved')->count());
    }

    public function test_status_reports_the_queue_and_succeeds_when_nothing_failed(): void
    {
        $this->transition($this->applicationUnderReview(), ApplicationStatus::APPROVED, $this->organizationAdmin);

        $this->artisan('federation:outbox-status')
            ->expectsOutputToContain('unpublished=2')
            ->assertSuccessful();

        $this->artisan('federation:work', ['--once' => true])->assertSuccessful();

        // One expectation per output line: Laravel matches them in order against separate writes.
        $this->artisan('federation:outbox-status')
            ->expectsOutputToContain('unpublished=0 oldest=- queued_jobs=0 failed_jobs=0 failed_events=0 processed=3')
            ->assertSuccessful();
    }
}
