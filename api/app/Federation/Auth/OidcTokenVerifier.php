<?php

namespace App\Federation\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Validates an access token against the configured issuer.
 *
 * Signature: RS256 against the issuer's published JWKS, fetched from the
 * discovery document (or an explicit jwks_uri) and cached. An unknown key id
 * refreshes the key set once, which is how key rotation is absorbed.
 * Then issuer, audience and the presence of a subject are checked here;
 * expiry, not-before and issued-at are checked by the library with leeway.
 */
class OidcTokenVerifier
{
    /**
     * @param  array<string, mixed>  $config  the config/oidc.php array
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly array $config,
    ) {}

    public function verify(string $token): OidcIdentity
    {
        $issuer = $this->config['issuer'] ?? null;
        $audience = $this->config['audience'] ?? null;

        if (blank($issuer) || blank($audience)) {
            throw new OidcException('OIDC is not configured: issuer and audience are required.');
        }

        $claims = $this->decode($token);

        if (($claims['iss'] ?? null) !== $issuer) {
            throw new OidcException('Issuer mismatch.');
        }

        $tokenAudiences = (array) ($claims['aud'] ?? []);

        if (! in_array($audience, $tokenAudiences, true)) {
            throw new OidcException('Audience mismatch.');
        }

        if (blank($claims['sub'] ?? null)) {
            throw new OidcException('Token has no subject.');
        }

        return OidcIdentity::fromClaims($claims);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $token): array
    {
        JWT::$leeway = (int) ($this->config['leeway'] ?? 0);

        try {
            return $this->decodeWith($this->keySet(refresh: false), $token);
        } catch (OidcException $e) {
            if (! $e->isUnknownKey) {
                throw $e;
            }

            // Key rotation: fetch a fresh key set once and try again.
            return $this->decodeWith($this->keySet(refresh: true), $token);
        }
    }

    /**
     * @param  array<string, Key>  $keys
     * @return array<string, mixed>
     */
    private function decodeWith(array $keys, string $token): array
    {
        try {
            $payload = JWT::decode($token, $keys);
        } catch (Throwable $e) {
            $exception = new OidcException('Token rejected: '.$e->getMessage());
            $exception->isUnknownKey = str_contains($e->getMessage(), '"kid"');

            throw $exception;
        }

        return json_decode(json_encode($payload), true);
    }

    /**
     * @return array<string, Key>
     */
    private function keySet(bool $refresh): array
    {
        // Only the key set is refreshed on rotation; the discovery document stays cached.
        $jwksUri = $this->jwksUri();
        $cacheKey = 'oidc:jwks:'.sha1($jwksUri);

        if ($refresh) {
            $this->cache->forget($cacheKey);
        }

        $jwks = $this->cache->remember($cacheKey, $this->ttl(), fn () => $this->fetchJson($jwksUri));

        try {
            return JWK::parseKeySet($jwks, $this->config['algorithms'][0] ?? 'RS256');
        } catch (Throwable $e) {
            throw new OidcException('Key set could not be parsed: '.$e->getMessage());
        }
    }

    private function jwksUri(): string
    {
        if (filled($this->config['jwks_uri'] ?? null)) {
            return $this->config['jwks_uri'];
        }

        $discoveryUrl = filled($this->config['discovery_url'] ?? null)
            ? $this->config['discovery_url']
            : rtrim($this->config['issuer'], '/').'/.well-known/openid-configuration';

        $cacheKey = 'oidc:discovery:'.sha1($discoveryUrl);

        $document = $this->cache->remember($cacheKey, $this->ttl(), fn () => $this->fetchJson($discoveryUrl));

        if (blank($document['jwks_uri'] ?? null)) {
            throw new OidcException('Discovery document has no jwks_uri.');
        }

        return $document['jwks_uri'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        try {
            return Http::timeout((int) ($this->config['http_timeout'] ?? 5))
                ->acceptJson()
                ->get($url)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new OidcException("Could not fetch {$url}: ".$e->getMessage());
        }
    }

    private function ttl(): int
    {
        return (int) ($this->config['cache_ttl'] ?? 3600);
    }
}
