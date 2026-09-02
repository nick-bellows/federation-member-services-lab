<?php

namespace App\Federation\Auth;

/**
 * What a verified token says about the caller. Nothing here is authorization.
 */
final class OidcIdentity
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly array $claims,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaims(array $claims): self
    {
        return new self(
            issuer: (string) $claims['iss'],
            subject: (string) $claims['sub'],
            email: isset($claims['email']) ? (string) $claims['email'] : null,
            emailVerified: (bool) ($claims['email_verified'] ?? false),
            name: isset($claims['name']) ? (string) $claims['name'] : null,
            claims: $claims,
        );
    }
}
