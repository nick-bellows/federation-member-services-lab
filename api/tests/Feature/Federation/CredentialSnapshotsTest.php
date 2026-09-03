<?php

namespace Tests\Feature\Federation;

use App\Federation\LearningCenter\CredentialsClient;
use App\Federation\LearningCenter\CredentialSnapshots;
use App\Federation\LearningCenter\Exceptions\LearningCenterUnavailableException;
use App\Federation\Models\AuditEntry;
use App\Federation\Models\CredentialSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Federation\CredentialFactsTest;

/**
 * The snapshot writer against a faked provider fed from the contract
 * fixtures: what is stored, what is audited, and what happens when the
 * provider is absent, slow or says "not found". One fake is registered per
 * test and reads its scenario from properties, because the HTTP fake keeps
 * the first stub registered for a URL.
 */
class CredentialSnapshotsTest extends FederationTestCase
{
    private const BASE = 'http://learning-center.test';

    private const TOKEN_ENDPOINT = 'http://oidc.test/default/token';

    /** @var array<string, string> subject => fixture file */
    private array $subjects = [];

    private ?string $failure = null;

    private int $tokenRequests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // This test exercises the real HTTP client against a faked provider.
        $this->app->forgetInstance(CredentialsClient::class);

        config()->set('learning_center.base_url', self::BASE);
        config()->set('learning_center.token.endpoint', self::TOKEN_ENDPOINT);
        config()->set('learning_center.token.client_secret', 'test-only');

        $this->applicant->forceFill(['oidc_issuer' => 'https://issuer.test', 'oidc_subject' => 'mock|alex'])->save();

        Http::fake([
            self::TOKEN_ENDPOINT => function (Request $request) {
                $this->tokenRequests++;
                $this->assertSame('client_credentials', $request['grant_type']);
                $this->assertSame('credentials:read', $request['scope']);

                return Http::response(['access_token' => 'service-token-'.$this->tokenRequests, 'expires_in' => 300]);
            },
            self::BASE.'/*' => function (Request $request) {
                if ($this->failure === 'timeout') {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }
                if ($this->failure === 'server') {
                    return Http::response(['error' => 'boom'], 500);
                }
                foreach ($this->subjects as $subject => $file) {
                    if ($request->url() === self::BASE.'/v1/members/'.rawurlencode($subject).'/credentials') {
                        return Http::response(CredentialFactsTest::fixture($file));
                    }
                }

                return Http::response(['error' => 'member not found'], 404);
            },
        ]);
    }

    public function test_a_refresh_stores_the_answer_and_audits_the_first_record(): void
    {
        $this->subjects = ['mock|alex' => 'alex-eligible.json'];

        $result = app(CredentialSnapshots::class)->refresh($this->applicant, $this->organizationAdmin, 'req-1');

        $this->assertFalse($result->changed);
        $snapshot = CredentialSnapshot::query()->sole();
        $this->assertSame($this->applicant->getKey(), $snapshot->user_id);
        $this->assertSame('eligible', $snapshot->eligibility_status);
        $this->assertSame('learning-center.credentials.v1', $snapshot->contract);
        $this->assertSame('2026-09-03T05:00:00+00:00', $snapshot->source_as_of?->toIso8601String());
        $this->assertSame(CredentialFactsTest::fixture('alex-eligible.json'), $snapshot->payload);

        $audit = AuditEntry::query()->where('action', 'credentials.recorded')->sole();
        $this->assertSame($this->organizationAdmin->getKey(), $audit->actor_user_id);
        $this->assertSame('req-1', $audit->request_id);
        $this->assertSame(['eligibility_status' => 'eligible', 'as_of' => '2026-09-03T05:00:00+00:00'], $audit->new_state);

        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/v1/members/mock%7Calex/credentials'
            && $request->hasHeader('Authorization', 'Bearer service-token-1'));
    }

    public function test_a_changed_eligibility_is_audited_with_both_states(): void
    {
        $this->subjects = ['mock|alex' => 'alex-eligible.json'];
        app(CredentialSnapshots::class)->refresh($this->applicant);

        $this->subjects = ['mock|alex' => 'sam-suspended.json'];
        $result = app(CredentialSnapshots::class)->refresh($this->applicant);

        $this->assertTrue($result->changed);
        $this->assertSame('suspended', CredentialSnapshot::query()->sole()->eligibility_status);
        $audit = AuditEntry::query()->where('action', 'credentials.changed')->sole();
        $this->assertSame(['eligibility_status' => 'eligible'], $audit->previous_state);
        $this->assertSame('suspended', $audit->new_state['eligibility_status']);
        $this->assertSame('system', $audit->actor_type);
    }

    public function test_an_unchanged_answer_writes_no_audit_entry(): void
    {
        $this->subjects = ['mock|alex' => 'alex-eligible.json'];
        app(CredentialSnapshots::class)->refresh($this->applicant);
        app(CredentialSnapshots::class)->refresh($this->applicant);

        $this->assertSame(1, AuditEntry::query()->whereIn('action', ['credentials.recorded', 'credentials.changed'])->count());
        $this->assertSame(1, CredentialSnapshot::query()->count());
    }

    public function test_a_member_the_provider_does_not_know_is_recorded_as_not_found(): void
    {
        app(CredentialSnapshots::class)->refresh($this->applicant);

        $snapshot = CredentialSnapshot::query()->sole();
        $this->assertSame(CredentialSnapshot::STATUS_NOT_FOUND, $snapshot->eligibility_status);
        $this->assertNull($snapshot->payload);
        $this->assertFalse($snapshot->hasFacts());
    }

    public function test_a_user_without_a_linked_identity_is_not_asked_about(): void
    {
        $this->subjects = ['mock|alex' => 'alex-eligible.json'];
        $this->applicant->forceFill(['oidc_subject' => null])->save();

        app(CredentialSnapshots::class)->refresh($this->applicant);

        $this->assertSame(CredentialSnapshot::STATUS_NOT_FOUND, CredentialSnapshot::query()->sole()->eligibility_status);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/credentials'));
    }

    public function test_a_slow_or_absent_provider_leaves_the_previous_snapshot_untouched(): void
    {
        $this->subjects = ['mock|alex' => 'alex-eligible.json'];
        app(CredentialSnapshots::class)->refresh($this->applicant);
        $before = CredentialSnapshot::query()->sole()->fetched_at;

        $this->failure = 'timeout';
        $this->travel(1)->minutes();

        try {
            app(CredentialSnapshots::class)->refresh($this->applicant);
            $this->fail('expected the provider to be reported unavailable');
        } catch (LearningCenterUnavailableException) {
        }

        $this->assertTrue($before->equalTo(CredentialSnapshot::query()->sole()->fetched_at));
        $this->assertSame(0, AuditEntry::query()->where('action', 'credentials.changed')->count());
    }

    public function test_a_server_error_is_unavailable_not_a_new_snapshot(): void
    {
        $this->failure = 'server';

        $this->expectException(LearningCenterUnavailableException::class);
        app(CredentialSnapshots::class)->refresh($this->applicant);
    }

    public function test_the_service_token_is_fetched_once_and_reused(): void
    {
        $this->subjects = ['mock|alex' => 'alex-eligible.json'];

        app(CredentialSnapshots::class)->refresh($this->applicant);
        app(CredentialSnapshots::class)->refresh($this->applicant);

        Http::assertSentCount(3);
        $this->assertSame(1, $this->tokenRequests);
    }
}
