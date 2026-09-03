<?php

namespace App\Federation\LearningCenter;

use App\Federation\LearningCenter\Exceptions\LearningCenterMemberNotFoundException;
use App\Federation\LearningCenter\Exceptions\LearningCenterUnauthorizedException;
use App\Federation\LearningCenter\Exceptions\LearningCenterUnavailableException;
use App\Federation\Observability\Tracing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

/**
 * The contract over HTTP with the federation's own service token. Timeouts
 * are short by design: a slow credential service degrades a page, it never
 * holds it (INCIDENT-001). Every call is a client span and carries the W3C
 * trace context to the provider (ADR-0012).
 */
final class HttpCredentialsClient implements CredentialsClient
{
    public function __construct(
        private readonly Http $http,
        private readonly ServiceTokenProvider $tokens,
        private readonly TracerInterface $tracer,
        private readonly string $baseUrl,
        private readonly string $contract,
        private readonly int $connectTimeoutMs,
        private readonly int $timeoutMs,
    ) {}

    public function fetch(string $subject): CredentialFacts
    {
        $url = rtrim($this->baseUrl, '/').'/v1/members/'.rawurlencode($subject).'/credentials';
        $span = $this->tracer->spanBuilder('learning-center.credentials')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, 'GET')
            ->setAttribute(TraceAttributes::SERVER_ADDRESS, (string) parse_url($this->baseUrl, PHP_URL_HOST))
            ->startSpan();
        $scope = $span->activate();

        try {
            $response = $this->http
                ->withToken($this->tokens->token())
                ->withHeaders(array_filter([Tracing::TRACEPARENT => Tracing::traceparent($span)]))
                ->acceptJson()
                ->connectTimeout($this->connectTimeoutMs / 1000)
                ->timeout($this->timeoutMs / 1000)
                ->get($url);
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->status());

            return $this->interpret($response);
        } catch (ConnectionException $e) {
            $span->recordException($e)->setStatus(StatusCode::STATUS_ERROR);
            throw new LearningCenterUnavailableException('Learning Center unreachable or too slow: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            $span->recordException($e)->setStatus(StatusCode::STATUS_ERROR);
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function interpret(Response $response): CredentialFacts
    {
        return match (true) {
            $response->successful() => CredentialFacts::fromArray((array) $response->json(), $this->contract),
            $response->status() === 404 => throw new LearningCenterMemberNotFoundException('No Learning Center member for the subject'),
            in_array($response->status(), [401, 403], true) => throw new LearningCenterUnauthorizedException('Learning Center rejected the service token with '.$response->status()),
            $response->serverError() => throw new LearningCenterUnavailableException('Learning Center answered '.$response->status()),
            default => throw new LearningCenterUnavailableException('Unexpected Learning Center status '.$response->status()),
        };
    }
}
