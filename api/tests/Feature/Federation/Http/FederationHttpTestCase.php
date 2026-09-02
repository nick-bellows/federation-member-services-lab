<?php

namespace Tests\Feature\Federation\Http;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\DocumentType;
use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\RegistrationWindow;
use App\Federation\Models\Season;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Federation\OidcTestCase;

/**
 * The federation world (one federation, two organizations, open windows,
 * the people) plus bearer tokens from the in-test issuer for each of them.
 * Requests go through the real guard, policies, schemas and controllers.
 */
abstract class FederationHttpTestCase extends OidcTestCase
{
    protected const BASE = '/api/v1/federation';

    protected Federation $federation;

    protected Season $season;

    protected MemberOrganization $organization;

    protected MemberOrganization $otherOrganization;

    protected RegistrationWindow $window;

    protected User $applicant;

    protected User $otherApplicant;

    protected User $organizationAdmin;

    protected User $otherOrganizationAdmin;

    protected User $federationAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->federation = Federation::factory()->create(['name' => 'Northgate Soccer Federation', 'code' => 'NSF']);
        $this->season = Season::factory()->create(['federation_id' => $this->federation->getKey(), 'label' => '2026/27']);
        $this->organization = MemberOrganization::factory()->create(['federation_id' => $this->federation->getKey(), 'code' => 'NYSA']);
        $this->otherOrganization = MemberOrganization::factory()->create(['federation_id' => $this->federation->getKey(), 'code' => 'NASL']);

        $this->applicant = $this->federationUser('alex', 'alex.participant@northgate.example');
        $this->otherApplicant = $this->federationUser('jordan', 'jordan.newcomer@northgate.example');
        $this->organizationAdmin = $this->federationUser('nysa-admin', 'nysa-admin@northgate.example');
        $this->otherOrganizationAdmin = $this->federationUser('nasl-admin', 'nasl-admin@northgate.example');
        $this->federationAdmin = $this->federationUser('federation-admin', 'federation-admin@northgate.example');

        $this->organization->administrators()->attach($this->organizationAdmin);
        $this->otherOrganization->administrators()->attach($this->otherOrganizationAdmin);
        $this->federation->administrators()->attach($this->federationAdmin);

        $this->window = RegistrationWindow::factory()->create([
            'member_organization_id' => $this->organization->getKey(),
            'season_id' => $this->season->getKey(),
            'roles' => ApplicationRole::values(),
        ]);
    }

    protected function federationUser(string $subject, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => ucwords(str_replace(['.', '-'], ' ', explode('@', $email)[0]))]);
        $user->forceFill(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'mock|'.$subject])->save();

        return $user;
    }

    protected function tokenFor(User $user): string
    {
        return $this->token(['sub' => $user->oidc_subject, 'email' => $user->email, 'name' => $user->name]);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, string>  $headers
     */
    protected function request(User $as, string $method, string $uri, array $document = [], array $headers = []): TestResponse
    {
        // A request guard caches its resolved user for the application's lifetime.
        // In production that is one HTTP request; in a feature test it would be
        // every request this test makes, so the guard is reset between them.
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenFor($as))->json($method, $uri, $document, array_merge([
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
        ], $headers));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $relationships
     * @return array<string, mixed>
     */
    protected function resource(string $type, array $attributes, array $relationships = [], ?string $id = null): array
    {
        $data = ['type' => $type, 'attributes' => $attributes];

        if ($id !== null) {
            $data['id'] = $id;
        }

        if ($relationships !== []) {
            $data['relationships'] = array_map(
                static fn (array $target) => ['data' => $target],
                $relationships,
            );
        }

        return ['data' => $data];
    }

    /**
     * Start an application over HTTP as the applicant and return its id.
     */
    protected function startApplicationOverHttp(User $as, ?RegistrationWindow $window = null, string $role = 'participant'): string
    {
        $response = $this->request($as, 'POST', self::BASE.'/registration-applications', $this->resource(
            'registration-applications',
            ['role' => $role, 'dateOfBirth' => '1998-04-12'],
            ['registrationWindow' => ['type' => 'registration-windows', 'id' => (string) ($window ?? $this->window)->getKey()]],
        ));

        $response->assertStatus(201);

        return $response->json('data.id');
    }

    protected function attachRequiredDocumentsOverHttp(User $as, string $applicationId, ApplicationRole $role = ApplicationRole::PARTICIPANT): void
    {
        foreach (DocumentType::requiredFor($role) as $type) {
            $this->request($as, 'POST', self::BASE.'/application-documents', $this->resource(
                'application-documents',
                [
                    'documentType' => $type->value,
                    'fileName' => $type->value.'.pdf',
                    'mimeType' => 'application/pdf',
                    'sizeBytes' => 120000,
                    'checksumSha256' => hash('sha256', $applicationId.$type->value),
                ],
                ['application' => ['type' => 'registration-applications', 'id' => $applicationId]],
            ))->assertStatus(201);
        }
    }

    protected function submitOverHttp(User $as, string $applicationId, array $headers = []): TestResponse
    {
        return $this->request($as, 'POST', self::BASE."/registration-applications/{$applicationId}/-actions/submit", [], $headers);
    }
}
