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
        $facts = CredentialFacts::fromArray(self::fixture($file), self::CONTRACT, $subject);

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
        CredentialFacts::fromArray($data, self::CONTRACT, 'mock|alex');
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $data = self::fixture('alex-eligible.json');
        $data['eligibility']['status'] = 'probably_fine';

        $this->expectException(ContractMismatchException::class);
        CredentialFacts::fromArray($data, self::CONTRACT, 'mock|alex');
    }

    public function test_a_missing_field_is_refused(): void
    {
        $data = self::fixture('alex-eligible.json');
        unset($data['role_credentials']);

        $this->expectException(ContractMismatchException::class);
        CredentialFacts::fromArray($data, self::CONTRACT, 'mock|alex');
    }

    public function test_an_answer_about_another_subject_is_refused(): void
    {
        // The provider's answer is bound to the subject that was asked for; a
        // response about someone else (a routing or caching fault upstream)
        // must never be stored as this person's facts.
        $this->expectException(ContractMismatchException::class);
        CredentialFacts::fromArray(self::fixture('sam-suspended.json'), self::CONTRACT, 'mock|alex');
    }

    /**
     * The provider's answer for $subject, shaped like another fixture: the
     * same person, now with that fixture's eligibility.
     *
     * @return array<string, mixed>
     */
    public static function answerFor(string $subject, string $shapedLike): array
    {
        $data = self::fixture($shapedLike);
        $data['member']['subject'] = $subject;

        return $data;
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
