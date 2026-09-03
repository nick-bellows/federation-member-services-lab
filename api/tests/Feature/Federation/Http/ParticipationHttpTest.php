<?php

namespace Tests\Feature\Federation\Http;

use App\Federation\LearningCenter\CredentialsClient;
use App\Federation\Models\CredentialSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Federation\CredentialFactsTest;

/**
 * Participation over HTTP: filled after an approval, refreshed on a
 * reviewer's request, read from the snapshot without any provider call,
 * and honest when the provider is away.
 */
class ParticipationHttpTest extends FederationHttpTestCase
{
    private const PROVIDER = 'http://learning-center.test';

    private const TOKEN_ENDPOINT = 'http://oidc.test/default/token';

    /** @var array<string, string> subject => fixture file */
    private array $subjects = ['mock|alex' => 'alex-eligible.json'];

    private bool $providerDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(CredentialsClient::class);
        config()->set('queue.default', 'database');
        config()->set('learning_center.base_url', self::PROVIDER);
        config()->set('learning_center.token.endpoint', self::TOKEN_ENDPOINT);
        config()->set('learning_center.token.client_secret', 'test-only');

        Http::fake([
            self::TOKEN_ENDPOINT => Http::response(['access_token' => 'service-token', 'expires_in' => 300]),
            self::PROVIDER.'/*' => function (Request $request) {
                if ($this->providerDown) {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }
                foreach ($this->subjects as $subject => $file) {
                    if ($request->url() === self::PROVIDER.'/v1/members/'.rawurlencode($subject).'/credentials') {
                        return Http::response(CredentialFactsTest::fixture($file));
                    }
                }

                return Http::response(['error' => 'member not found'], 404);
            },
        ]);
    }

    public function test_an_approval_asks_the_provider_once_and_the_application_shows_participation(): void
    {
        $id = $this->approvedApplication();

        $this->assertSame(1, CredentialSnapshot::query()->where('user_id', $this->applicant->getKey())->count());
        Http::assertSent(fn (Request $request) => $request->url() === self::PROVIDER.'/v1/members/mock%7Calex/credentials');

        $this->request($this->applicant, 'GET', self::BASE."/registration-applications/{$id}")
            ->assertOk()
            ->assertJsonPath('data.attributes.participation.status', 'may_participate')
            ->assertJsonPath('data.attributes.participation.reasons', [])
            ->assertJsonPath('data.attributes.participation.stale', false)
            ->assertJsonPath('data.attributes.participation.asOf', '2026-09-03T05:00:00+00:00');
    }

    public function test_an_approval_succeeds_when_the_provider_is_away_and_participation_is_unknown(): void
    {
        $this->providerDown = true;

        $id = $this->approvedApplication();

        $this->assertSame(0, CredentialSnapshot::query()->count());
        $this->request($this->organizationAdmin, 'GET', self::BASE."/registration-applications/{$id}")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', 'approved')
            ->assertJsonPath('data.attributes.participation.status', 'unknown')
            ->assertJsonPath('data.attributes.participation.reasons', ['no_snapshot']);
    }

    public function test_a_reviewer_can_refresh_and_sees_the_new_answer(): void
    {
        $id = $this->approvedApplication();
        $this->subjects = ['mock|alex' => 'sam-suspended.json'];

        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/refresh-credentials")
            ->assertOk()
            ->assertJsonPath('data.attributes.participation.status', 'blocked')
            ->assertJsonPath('data.attributes.participation.reasons', ['hold_active']);

        $this->assertSame('suspended', CredentialSnapshot::query()->sole()->eligibility_status);
    }

    public function test_only_a_reviewer_of_that_organization_may_refresh(): void
    {
        $id = $this->approvedApplication();

        $this->request($this->applicant, 'POST', self::BASE."/registration-applications/{$id}/-actions/refresh-credentials")
            ->assertStatus(403);
        $this->request($this->otherOrganizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/refresh-credentials")
            ->assertStatus(403);
        $this->request($this->federationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/refresh-credentials")
            ->assertOk();
    }

    public function test_a_refresh_while_the_provider_is_away_is_a_503_with_a_stable_code(): void
    {
        $id = $this->approvedApplication();
        $this->providerDown = true;

        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/refresh-credentials")
            ->assertStatus(503)
            ->assertJsonPath('errors.0.code', 'learning_center_unavailable');

        // The previous snapshot still answers, marked by its age.
        $this->request($this->organizationAdmin, 'GET', self::BASE."/registration-applications/{$id}")
            ->assertOk()
            ->assertJsonPath('data.attributes.participation.status', 'may_participate');
    }

    public function test_the_queue_reads_snapshots_without_calling_the_provider(): void
    {
        $id = $this->approvedApplication();
        $this->assertSame(1, $this->providerRequests());

        $this->request($this->organizationAdmin, 'GET', self::BASE.'/registration-applications?filter[status]=approved')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.attributes.participation.status', 'may_participate');
        $this->request($this->applicant, 'GET', self::BASE."/registration-applications/{$id}")->assertOk();

        $this->assertSame(1, $this->providerRequests());
    }

    /**
     * Requests to the credential provider only; the test issuer's discovery
     * and key-set fetches for the personas' own tokens are not counted.
     */
    private function providerRequests(): int
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::PROVIDER))->count();
    }

    public function test_an_unapproved_application_reports_findings_but_is_not_participating(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant, role: 'referee');
        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/refresh-credentials")
            ->assertOk()
            ->assertJsonPath('data.attributes.participation.status', 'blocked')
            ->assertJsonPath('data.attributes.participation.reasons', ['not_approved', 'role_credential_missing']);
    }

    /**
     * Start, complete, submit, review and approve over HTTP; returns the id.
     */
    private function approvedApplication(): string
    {
        $id = $this->startApplicationOverHttp($this->applicant);
        $this->attachRequiredDocumentsOverHttp($this->applicant, $id);
        $this->submitOverHttp($this->applicant, $id)->assertOk();
        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/start-review")->assertOk();
        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/approve")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', 'approved');

        // The approval only wrote the outbox row; the worker delivers it (ADR-0010).
        $this->artisan('federation:work', ['--once' => true])->assertSuccessful();

        return $id;
    }
}
