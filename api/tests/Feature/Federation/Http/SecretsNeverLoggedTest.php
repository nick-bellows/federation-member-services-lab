<?php

namespace Tests\Feature\Federation\Http;

use App\Federation\LearningCenter\CredentialsClient;
use App\Federation\LearningCenter\CredentialSnapshots;
use App\Federation\Observability\Tracing;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Federation\CredentialFactsTest;

/**
 * The bearer token a person presents, the service token this application
 * obtains for the Learning Center, and the client secret it obtains it with
 * appear in no log line and no span attribute (threat model, "learn from
 * public surfaces"; ADR-0014). The access line and the spans still carry
 * what an operator needs: request id, user id, route, status.
 */
class SecretsNeverLoggedTest extends FederationHttpTestCase
{
    private const PROVIDER = 'http://learning-center.test';

    private const CLIENT_SECRET = 'client-secret-that-must-not-leak';

    private const SERVICE_TOKEN = 'service-token-that-must-not-leak';

    /** @var list<MessageLogged> */
    private array $logged = [];

    /** The bearer token presented in the requests under test. */
    private string $userToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        Tracing::resetRecordedSpans();
        config()->set('learning_center.base_url', self::PROVIDER);
        config()->set('learning_center.token.endpoint', 'http://oidc.test/default/token');
        config()->set('learning_center.token.client_secret', self::CLIENT_SECRET);
        $this->app->forgetInstance(CredentialsClient::class);

        Http::fake([
            'http://oidc.test/*' => Http::response(['access_token' => self::SERVICE_TOKEN, 'expires_in' => 300]),
            self::PROVIDER.'/*' => Http::response(CredentialFactsTest::fixture('alex-eligible.json')),
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
    }

    public function test_tokens_and_secrets_appear_in_no_log_line_and_no_span(): void
    {
        $this->userToken = $userToken = $this->tokenFor($this->applicant);
        $id = $this->startApplicationOverHttp($this->applicant);
        $this->request($this->applicant, 'GET', self::BASE."/registration-applications/{$id}")->assertOk();

        // A rejected token takes the failure path through the guard and the
        // exception handler, which log more freely than the success path.
        $this->app['auth']->forgetGuards();
        $this->withToken('not-a-token-'.$userToken)->getJson(self::BASE.'/registration-applications')->assertStatus(401);

        // The provider call: client credentials go out, a service token comes back.
        app(CredentialSnapshots::class)->refresh($this->applicant, $this->organizationAdmin, 'secrets-test-0001');

        $this->assertNotEmpty($this->logged, 'the requests produced log lines to inspect');
        $accessLines = array_filter($this->logged, fn (MessageLogged $event) => $event->message === 'request');
        $this->assertNotEmpty($accessLines, 'the access line is written');
        $this->assertArrayHasKey('request_id', reset($accessLines)->context);

        foreach ($this->logged as $event) {
            $rendered = $event->message.' '.json_encode($event->context, JSON_THROW_ON_ERROR);
            $this->assertSecretFree($rendered, 'log line "'.$event->message.'"');
        }

        $spans = Tracing::recordedSpans();
        $this->assertNotEmpty($spans, 'the requests produced spans to inspect');

        foreach ($spans as $span) {
            $rendered = $span->getName().' '.json_encode($span->getAttributes()->toArray(), JSON_THROW_ON_ERROR);
            foreach ($span->getEvents() as $spanEvent) {
                $rendered .= ' '.$spanEvent->getName().' '.json_encode($spanEvent->getAttributes()->toArray(), JSON_THROW_ON_ERROR);
            }
            $this->assertSecretFree($rendered, 'span "'.$span->getName().'"');
        }
    }

    private function assertSecretFree(string $rendered, string $where): void
    {
        foreach ([
            'client secret' => self::CLIENT_SECRET,
            'service token' => self::SERVICE_TOKEN,
            'user token' => $this->userToken,
        ] as $label => $secret) {
            $this->assertStringNotContainsString($secret, $rendered, "{$where} contains the {$label}");
        }

        // A JWT's signature segment would betray the token even without the rest.
        $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\./', $rendered, "{$where} contains a JWT");
    }
}
