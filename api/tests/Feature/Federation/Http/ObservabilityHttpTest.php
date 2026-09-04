<?php

namespace Tests\Feature\Federation\Http;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\Models\OutboxEvent;
use App\Federation\Outbox\OutboxEventTypes;
use App\Federation\Outbox\OutboxRecorder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The probes and the metrics endpoint as a platform sees them: liveness and
 * readiness without a token, checks and metrics behind the scrape token,
 * no personal data, honest about dependencies and the queue.
 */
class ObservabilityHttpTest extends FederationHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('learning_center.base_url', 'http://learning-center.test');
        config()->set('observability.readiness.outbox_max_age_seconds', 300);
        config()->set('observability.metrics.token', null);
    }

    public function test_liveness_answers_without_touching_anything(): void
    {
        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_readiness_is_ready_with_a_healthy_provider(): void
    {
        Http::fake(['http://learning-center.test/*' => Http::response(['status' => 'ok'])]);

        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.outbox.status', 'ok')
            ->assertJsonPath('checks.learning_center.status', 'ok')
            ->assertJsonPath('checks.learning_center.required', false);
    }

    public function test_readiness_stays_ready_when_the_provider_is_away_but_reports_it(): void
    {
        Http::fake(['http://learning-center.test/*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.learning_center.status', 'degraded');
    }

    public function test_readiness_fails_when_the_outbox_is_backing_up(): void
    {
        Http::fake(['http://learning-center.test/*' => Http::response(['status' => 'ok'])]);
        DB::transaction(fn () => app(OutboxRecorder::class)->record(OutboxEventTypes::APPLICATION_SUBMITTED, $this->applicant, []));
        OutboxEvent::query()->update(['occurred_at' => now()->subMinutes(10)]);

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.outbox.status', 'failed');
    }

    public function test_checks_report_unknown_until_spatie_health_has_run(): void
    {
        $this->getJson('/api/health/checks')
            ->assertOk()
            ->assertJsonPath('status', 'unknown');
    }

    public function test_metrics_render_the_federation_numbers_in_prometheus_text(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);
        $this->attachRequiredDocumentsOverHttp($this->applicant, $id);
        $this->submitOverHttp($this->applicant, $id)->assertOk();

        $response = $this->get('/api/metrics')->assertOk();
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $body = $response->getContent();

        $this->assertStringContainsString('# TYPE federation_outbox_unpublished gauge', $body);
        $this->assertStringContainsString("federation_outbox_unpublished 1\n", $body);
        $this->assertStringContainsString('federation_applications{status="'.ApplicationStatus::SUBMITTED->value.'"} 1', $body);
        $this->assertStringContainsString('federation_applications{status="draft"} 0', $body);
        $this->assertStringContainsString("federation_jobs_failed 0\n", $body);
        $this->assertStringNotContainsString('@northgate.example', $body);
    }

    public function test_metrics_require_the_token_when_one_is_configured(): void
    {
        config()->set('observability.metrics.token', 'scrape-me');

        $this->get('/api/metrics')->assertStatus(401);
        $this->withToken('scrape-me')->get('/api/metrics')->assertOk();
    }

    public function test_checks_require_the_same_token_while_the_probes_stay_open(): void
    {
        config()->set('observability.metrics.token', 'scrape-me');
        Http::fake(['http://learning-center.test/*' => Http::response(['status' => 'ok'])]);

        $this->getJson('/api/health/checks')->assertStatus(401)->assertJsonPath('status', 'unauthorized');
        $this->withToken('scrape-me')->getJson('/api/health/checks')->assertOk();

        // A platform probes liveness and readiness before it has any secret.
        $this->getJson('/api/health/live')->assertOk();
        $this->getJson('/api/health/ready')->assertOk();
    }

    public function test_the_shipped_environment_file_sets_a_scrape_token(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^METRICS_TOKEN=\S+$/m', $example, 'checks and metrics are token-gated by default (ADR-0014)');
    }
}
