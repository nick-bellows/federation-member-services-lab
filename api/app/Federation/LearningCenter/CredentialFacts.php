<?php

namespace App\Federation\LearningCenter;

use App\Federation\LearningCenter\Exceptions\ContractMismatchException;
use Carbon\CarbonImmutable;

/**
 * One response of the credentials contract, validated and read-only.
 * The consumer branches on the provider's statuses and flags only; it never
 * re-derives validity from the dates (docs/contracts/learning-center-credentials-v1.md).
 */
final class CredentialFacts
{
    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_LAPSED = 'ineligible_lapsed';

    private const STATUSES = [self::STATUS_ELIGIBLE, self::STATUS_SUSPENDED, self::STATUS_LAPSED];

    /**
     * @param  list<string>  $roles
     * @param  list<array{source: string, active: bool}>  $holds
     * @param  list<array{role: string, credential_type: string, issued_at: string, expires_at: string, valid: bool}>  $roleCredentials
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly string $contract,
        public readonly string $memberId,
        public readonly string $subject,
        public readonly array $roles,
        public readonly CarbonImmutable $asOf,
        public readonly string $eligibilityStatus,
        public readonly string $eligibilityReason,
        public readonly array $holds,
        public readonly array $roleCredentials,
        private readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $expectedContract): self
    {
        if (($data['contract'] ?? null) !== $expectedContract) {
            throw new ContractMismatchException(sprintf(
                'Expected contract %s, received %s',
                $expectedContract,
                var_export($data['contract'] ?? null, true),
            ));
        }

        foreach (['member', 'as_of', 'eligibility', 'holds', 'safeguarding', 'role_credentials'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new ContractMismatchException("Missing field {$key}");
            }
        }

        $status = $data['eligibility']['status'] ?? null;
        if (! in_array($status, self::STATUSES, true)) {
            throw new ContractMismatchException('Unknown eligibility status '.var_export($status, true));
        }

        foreach ($data['role_credentials'] as $credential) {
            foreach (['role', 'credential_type', 'issued_at', 'expires_at', 'valid'] as $key) {
                if (! array_key_exists($key, $credential)) {
                    throw new ContractMismatchException("Missing field role_credentials[].{$key}");
                }
            }
        }

        return new self(
            contract: $data['contract'],
            memberId: (string) ($data['member']['id'] ?? ''),
            subject: (string) ($data['member']['subject'] ?? ''),
            roles: array_values(array_map('strval', $data['member']['roles'] ?? [])),
            asOf: CarbonImmutable::parse($data['as_of']),
            eligibilityStatus: $status,
            eligibilityReason: (string) ($data['eligibility']['reason'] ?? ''),
            holds: array_values($data['holds']),
            roleCredentials: array_values($data['role_credentials']),
            raw: $data,
        );
    }

    public function hasValidRoleCredential(string $role): bool
    {
        foreach ($this->roleCredentials as $credential) {
            if ($credential['role'] === $role && $credential['valid'] === true) {
                return true;
            }
        }

        return false;
    }

    public function hasActiveHold(): bool
    {
        foreach ($this->holds as $hold) {
            if (($hold['active'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
