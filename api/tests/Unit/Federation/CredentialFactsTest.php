<?php

namespace Tests\Unit\Federation;

use App\Federation\LearningCenter\CredentialFacts;
use App\Federation\LearningCenter\Exceptions\ContractMismatchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixture files are the contract. Every 200 fixture must parse, and the
 * consumer must refuse any other contract version or an unknown status.
 */
class CredentialFactsTest extends TestCase
{
    private const CONTRACT = 'learning-center.credentials.v1';

    public static function fixtures(): array
    {
        return [
            'alex, eligible coach' => ['alex-eligible.json', 'mock|alex', 'eligible', ['coach' => true], false],
            'sam, suspended by a hold' => ['sam-suspended.json', 'mock|sam', 'suspended', ['referee' => true], true],
            'riley, lapsed referee credential' => ['riley-lapsed.json', 'mock|riley', 'ineligible_lapsed', ['referee' => false], false],
        ];
    }

    /**
     * @param  array<string, bool>  $roleValidity
     */
    #[DataProvider('fixtures')]
    public function test_every_fixture_parses_and_reports_what_the_provider_decided(string $file, string $subject, string $status, array $roleValidity, bool $hold): void
    {
        $facts = CredentialFacts::fromArray(self::fixture($file), self::CONTRACT);

        $this->assertSame(self::CONTRACT, $facts->contract);
        $this->assertSame($subject, $facts->subject);
        $this->assertSame($status, $facts->eligibilityStatus);
        $this->assertSame($hold, $facts->hasActiveHold());
        foreach ($roleValidity as $role => $valid) {
            $this->assertSame($valid, $facts->hasValidRoleCredential($role), "role credential {$role}");
        }
        $this->assertFalse($facts->hasValidRoleCredential('participant'));
        $this->assertSame(self::fixture($file), $facts->toArray());
    }

    public function test_another_contract_version_is_refused(): void
    {
        $data = self::fixture('alex-eligible.json');
        $data['contract'] = 'learning-center.credentials.v2';

        $this->expectException(ContractMismatchException::class);
        CredentialFacts::fromArray($data, self::CONTRACT);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $data = self::fixture('alex-eligible.json');
        $data['eligibility']['status'] = 'probably_fine';

        $this->expectException(ContractMismatchException::class);
        CredentialFacts::fromArray($data, self::CONTRACT);
    }

    public function test_a_missing_field_is_refused(): void
    {
        $data = self::fixture('alex-eligible.json');
        unset($data['role_credentials']);

        $this->expectException(ContractMismatchException::class);
        CredentialFacts::fromArray($data, self::CONTRACT);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $file): array
    {
        $path = __DIR__.'/../../Fixtures/learning-center/credentials/'.$file;

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
