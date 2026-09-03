<?php

namespace App\Federation\LearningCenter;

use App\Federation\LearningCenter\Exceptions\LearningCenterUnavailableException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * OAuth2 client-credentials token for calling the Learning Center as a
 * service (ADR-0009). Cached until shortly before it expires; the token value
 * is never logged.
 */
final class ServiceTokenProvider
{
    private const CACHE_KEY = 'learning_center.service_token';

    /**
     * @param  array{endpoint: string, client_id: string, client_secret: ?string, audience: string, scope: string, refresh_margin: int}  $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly array $config,
        private readonly int $timeoutMs,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->timeout($this->timeoutMs / 1000)
                ->post($this->config['endpoint'], [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config['client_id'],
                    'client_secret' => (string) $this->config['client_secret'],
                    'scope' => $this->config['scope'],
                    'audience' => $this->config['audience'],
                ]);
        } catch (ConnectionException $e) {
            throw new LearningCenterUnavailableException('Token endpoint unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new LearningCenterUnavailableException('Token endpoint answered '.$response->status().' without an access token');
        }

        $token = $response->json('access_token');
        $ttl = max(1, (int) $response->json('expires_in', 300) - $this->config['refresh_margin']);
        $this->cache->put(self::CACHE_KEY, $token, $ttl);

        return $token;
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
