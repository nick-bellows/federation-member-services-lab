<?php

namespace Tests\Feature\Federation;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\LearningCenter\CredentialsClient;
use App\Federation\Models\AuditEntry;
use App\Federation\Models\CredentialSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Federation\CredentialFactsTest;

/**
 * Reconciliation is the backstop: it refreshes the snapshot of every
 * approved applicant whose snapshot is missing or old, reports what changed,
 * and fails loudly when the provider is away so a scheduler notices.
 */
class ReconcileCredentialsCommandTest extends FederationTestCase
{
    private const PROVIDER = 'http://learning-center.test';

    /** @var array<string, string> */
    private array $subjects = [];

    private bool $providerDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(CredentialsClient::class);
        config()->set('learning_center.base_url', self::PROVIDER);
        config()->set('learning_center.token.endpoint', 'http://oidc.test/default/token');
        config()->set('learning_center.token.client_secret', 'test-only');
        config()->set('learning_center.snapshot_ttl_minutes', 60);
        config()->set('queue.default', 'database');

        $this->applicant->forceFill(['oidc_issuer' => 'https://issuer.test', 'oidc_subject' => 'mock|alex'])->save();

        Http::fake([
            'http://oidc.test/*' => Http::response(['access_token' => 'service-token', 'expires_in' => 300]),
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

    public function test_it_fills_a_missing_snapshot_for_an_approved_applicant_and_skips_a_fresh_one(): void
    {
        $this->subjects = ['mock|alex' => 'alex-eligible.json'];
        $this->approve();
        // The approval refreshed once; remove that row to stand in for a snapshot that never happened.
        $this->assertSame(1, CredentialSnapshot::query()->count());
        CredentialSnapshot::query()->delete();

        $this->artisan('federation:reconcile-credentials')
            ->expectsOutputToContain('refreshed=1 changed=0 unavailable=0 skipped=0')
            ->assertSuccessful();
        $this->assertSame('eligible', CredentialSnapshot::query()->sole()->eligibility_status);

        $this->artisan('federation:reconcile-credentials')
            ->expectsOutputToContain('refreshed=0 changed=0 unavailable=0 skipped=1')
            ->assertSuccessful();
    }

    public function test_it_refreshes_old_snapshots_and_reports_a_change(): void
    {
        $this->subjects = ['mock|alex' => 'alex-eligible.json'];
        $this->approve();
        $this->artisan('federation:reconcile-credentials')->assertSuccessful();

        $this->subjects = ['mock|alex' => 'riley-lapsed.json'];
        $this->travel(2)->hours();

        $this->artisan('federation:reconcile-credentials')
            ->expectsOutputToContain('eligibility changed to ineligible_lapsed')
            ->expectsOutputToContain('refreshed=1 changed=1')
            ->assertSuccessful();

        $this->assertSame(1, AuditEntry::query()->where('action', 'credentials.changed')->count());
    }

    public function test_it_ignores_applicants_without_an_approved_application(): void
    {
        $this->subjects = ['mock|alex' => 'alex-eligible.json'];
        $this->applicationUnderReview();

        $this->artisan('federation:reconcile-credentials')
            ->expectsOutputToContain('refreshed=0')
            ->assertSuccessful();
        $this->assertSame(0, CredentialSnapshot::query()->count());
    }

    public function test_an_absent_provider_is_counted_and_fails_the_command(): void
    {
        $this->providerDown = true;
        $this->approve();

        $this->artisan('federation:reconcile-credentials')
            ->expectsOutputToContain('unavailable=1')
            ->assertFailed();
    }

    private function approve(): void
    {
        $this->transition($this->applicationUnderReview(), ApplicationStatus::APPROVED, $this->organizationAdmin);
        // The approval's refresh happens through the outbox worker (ADR-0010).
        $this->artisan('federation:work', ['--once' => true])->assertSuccessful();
    }
}
