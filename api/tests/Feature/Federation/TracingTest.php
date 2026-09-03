<?php

namespace Tests\Feature\Federation;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\LearningCenter\CredentialsClient;
use App\Federation\Models\OutboxEvent;
use App\Federation\Observability\Tracing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Tests\Unit\Federation\CredentialFactsTest;

/**
 * One trace from the approval to the worker to the provider, recorded by the
 * in-memory exporter the test environment configures (phpunit.xml sets
 * OTEL_EXPORTER=memory): the transition span, the outbox row's trace
 * context, the job's span continuing it, and the traceparent header sent
 * to the Learning Center.
 */
class TracingTest extends FederationTestCase
{
    private const PROVIDER = 'http://learning-center.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tracing::resetRecordedSpans();
        config()->set('queue.default', 'database');
        config()->set('learning_center.base_url', self::PROVIDER);
        config()->set('learning_center.token.endpoint', 'http://oidc.test/default/token');
        config()->set('learning_center.token.client_secret', 'test-only');
        $this->app->forgetInstance(CredentialsClient::class);
        $this->applicant->forceFill(['oidc_issuer' => 'https://issuer.test', 'oidc_subject' => 'mock|alex'])->save();

        Http::fake([
            'http://oidc.test/*' => Http::response(['access_token' => 'service-token', 'expires_in' => 300]),
            self::PROVIDER.'/*' => Http::response(CredentialFactsTest::fixture('alex-eligible.json')),
        ]);
    }

    public function test_an_approval_and_its_worker_jobs_share_one_trace_and_the_provider_receives_it(): void
    {
        $application = $this->applicationUnderReview();
        $this->transition($application, ApplicationStatus::APPROVED, $this->organizationAdmin);

        $transition = $this->spansNamed('application.transition');
        $this->assertNotEmpty($transition, 'the transition is a span');
        $approval = end($transition);
        $traceId = $approval->getContext()->getTraceId();

        $row = OutboxEvent::query()->where('event_type', 'application.approved')->sole();
        $this->assertNotNull($row->traceparent);
        $this->assertStringContainsString($traceId, $row->traceparent);

        $this->artisan('federation:work', ['--once' => true])->assertSuccessful();

        $jobs = array_filter($this->spansNamed('outbox.process'), fn ($span) => $span->getContext()->getTraceId() === $traceId);
        $this->assertCount(2, $jobs, 'both consumers of the approval continue the approval trace');
        $consumers = array_map(fn ($span) => $span->getAttributes()->get('federation.consumer'), array_values($jobs));
        sort($consumers);
        $this->assertSame(['credential-refresh', 'notifications'], $consumers);

        $provider = array_filter($this->spansNamed('learning-center.credentials'), fn ($span) => $span->getContext()->getTraceId() === $traceId);
        $this->assertCount(1, $provider, 'the provider call is a client span in the same trace');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/credentials')
            && str_starts_with((string) $request->header('traceparent')[0], '00-'.$traceId.'-'));
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function spansNamed(string $name): array
    {
        return array_values(array_filter(Tracing::recordedSpans(), fn ($span) => $span->getName() === $name));
    }
}
