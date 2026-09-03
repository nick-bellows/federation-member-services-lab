<?php

namespace Tests\Feature\Federation;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A fake OpenID Connect issuer: an RSA key pair generated per test, a JWKS
 * document and a discovery document served through Http::fake(), and a
 * helper that signs tokens with the private key. No network, no provider.
 */
abstract class OidcTestCase extends TestCase
{
    protected const ISSUER = 'https://issuer.example';

    protected const AUDIENCE = 'https://api.northgate.example';

    protected const KID = 'test-key-1';

    protected string $privateKey;

    /**
     * @var array<string, mixed>
     */
    protected array $jwks;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.issuer' => self::ISSUER,
            'oidc.audience' => self::AUDIENCE,
            'oidc.discovery_url' => null,
            'oidc.jwks_uri' => null,
            'oidc.leeway' => 0,
            'oidc.provision_users' => true,
        ]);

        [$this->privateKey, $this->jwks] = $this->generateKeyPair(self::KID);

        $this->fakeIssuer();
    }

    /**
     * @param  array<string, mixed>  $overrides  claims to add or replace
     * @param  array<string, mixed>  $headers  JWT header fields to add or replace
     */
    protected function token(array $overrides = [], array $headers = [], ?string $privateKey = null): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|alex',
            'iat' => time(),
            'exp' => time() + 600,
            'email' => 'alex.participant@northgate.example',
            'email_verified' => true,
            'name' => 'Alex Participant',
        ], $overrides);

        return JWT::encode(
            $claims,
            $privateKey ?? $this->privateKey,
            $headers['alg'] ?? 'RS256',
            $headers['kid'] ?? self::KID,
        );
    }

    /**
     * Registered once: Http::fake answers with the first matching stub, so the
     * JWKS stub reads $this->jwks at request time. Key rotation in a test is
     * "assign a new key set to $this->jwks".
     */
    protected function fakeIssuer(): void
    {
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            self::ISSUER.'/jwks' => fn () => Http::response($this->jwks),
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function generateKeyPair(string $kid): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        $jwks = ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => $this->base64Url($details['rsa']['n']),
            'e' => $this->base64Url($details['rsa']['e']),
        ]]];

        return [$privateKey, $jwks];
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
