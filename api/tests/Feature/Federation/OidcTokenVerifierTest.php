<?php

namespace Tests\Feature\Federation;

use App\Federation\Auth\OidcException;
use App\Federation\Auth\OidcTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

class OidcTokenVerifierTest extends OidcTestCase
{
    private function verifier(): OidcTokenVerifier
    {
        return app(OidcTokenVerifier::class);
    }

    public function test_a_valid_token_yields_the_identity(): void
    {
        $identity = $this->verifier()->verify($this->token());

        $this->assertSame(self::ISSUER, $identity->issuer);
        $this->assertSame('auth0|alex', $identity->subject);
        $this->assertSame('alex.participant@northgate.example', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('Alex Participant', $identity->name);
    }

    public function test_the_audience_may_be_a_list_that_contains_ours(): void
    {
        $identity = $this->verifier()->verify($this->token(['aud' => ['https://other.example', self::AUDIENCE]]));

        $this->assertSame('auth0|alex', $identity->subject);
    }

    public function test_a_token_for_another_audience_is_rejected(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('Audience mismatch');

        $this->verifier()->verify($this->token(['aud' => 'https://other-api.example']));
    }

    public function test_a_token_from_another_issuer_is_rejected(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('Issuer mismatch');

        $this->verifier()->verify($this->token(['iss' => 'https://evil.example']));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('Expired token');

        $this->verifier()->verify($this->token(['exp' => time() - 60]));
    }

    public function test_a_token_signed_with_an_unknown_key_is_rejected_after_one_refresh(): void
    {
        [$otherPrivateKey] = $this->generateKeyPair('rogue-key');

        try {
            $this->verifier()->verify($this->token(headers: ['kid' => 'rogue-key'], privateKey: $otherPrivateKey));
            $this->fail('Expected OidcException');
        } catch (OidcException $e) {
            $this->assertStringContainsString('Token rejected', $e->getMessage());
        }

        // discovery + jwks, then a jwks refresh for the unknown kid: three requests, no more.
        Http::assertSentCount(3);
    }

    public function test_key_rotation_is_absorbed_by_refreshing_the_key_set(): void
    {
        // Warm the cache with the first key set.
        $this->verifier()->verify($this->token());

        // The issuer rotates keys: a new pair is published, tokens are signed with it.
        [$newPrivateKey, $this->jwks] = $this->generateKeyPair('test-key-2');

        $identity = $this->verifier()->verify($this->token(headers: ['kid' => 'test-key-2'], privateKey: $newPrivateKey));

        $this->assertSame('auth0|alex', $identity->subject);
    }

    public function test_a_symmetric_algorithm_is_rejected_even_with_the_public_key_as_secret(): void
    {
        $publicPem = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey))['key'];
        $forged = JWT::encode(
            ['iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'sub' => 'auth0|alex', 'exp' => time() + 600],
            $publicPem,
            'HS256',
            self::KID,
        );

        $this->expectException(OidcException::class);

        $this->verifier()->verify($forged);
    }

    public function test_the_key_set_is_cached_between_requests(): void
    {
        $this->verifier()->verify($this->token());
        $this->verifier()->verify($this->token(['sub' => 'auth0|sam']));

        Http::assertSentCount(2);
    }

    public function test_missing_configuration_fails_closed(): void
    {
        config(['oidc.audience' => null]);

        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('not configured');

        $this->verifier()->verify($this->token());
    }
}
