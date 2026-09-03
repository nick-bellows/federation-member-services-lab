<?php

namespace App\Federation\LearningCenter;

use App\Federation\LearningCenter\Exceptions\LearningCenterMemberNotFoundException;
use App\Federation\LearningCenter\Exceptions\LearningCenterUnauthorizedException;
use App\Federation\LearningCenter\Exceptions\LearningCenterUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * The contract over HTTP with the federation's own service token. Timeouts
 * are short by design: a slow credential service degrades a page, it never
 * holds it (INCIDENT-001).
 */
final class HttpCredentialsClient implements CredentialsClient
{
    public function __construct(
        private readonly Http $http,
        private readonly ServiceTokenProvider $tokens,
        private readonly string $baseUrl,
        private readonly string $contract,
        private readonly int $connectTimeoutMs,
        private readonly int $timeoutMs,
    ) {}

    public function fetch(string $subject): CredentialFacts
    {
        $url = rtrim($this->baseUrl, '/').'/v1/members/'.rawurlencode($subject).'/credentials';

        try {
            $response = $this->http
                ->withToken($this->tokens->token())
                ->acceptJson()
                ->connectTimeout($this->connectTimeoutMs / 1000)
                ->timeout($this->timeoutMs / 1000)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new LearningCenterUnavailableException('Learning Center unreachable or too slow: '.$e->getMessage(), previous: $e);
        }

        return match (true) {
            $response->successful() => CredentialFacts::fromArray((array) $response->json(), $this->contract),
            $response->status() === 404 => throw new LearningCenterMemberNotFoundException('No Learning Center member for the subject'),
            in_array($response->status(), [401, 403], true) => throw new LearningCenterUnauthorizedException('Learning Center rejected the service token with '.$response->status()),
            $response->serverError() => throw new LearningCenterUnavailableException('Learning Center answered '.$response->status()),
            default => throw new LearningCenterUnavailableException('Unexpected Learning Center status '.$response->status(), previous: $this->asException($response)),
        };
    }

    private function asException(Response $response): ?RequestException
    {
        return $response->toException();
    }
}
