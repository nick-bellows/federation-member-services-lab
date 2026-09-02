<?php

namespace Tests\Feature\Federation\Http;

use App\Federation\Models\AuditEntry;
use App\Federation\Models\RegistrationWindow;

class RegistrationWindowsHttpTest extends FederationHttpTestCase
{
    private function windowDocument(string $organizationId): array
    {
        return $this->resource(
            'registration-windows',
            ['opensAt' => now()->subDay()->toIso8601String(), 'closesAt' => now()->addMonth()->toIso8601String(), 'roles' => ['participant', 'coach']],
            [
                'memberOrganization' => ['type' => 'member-organizations', 'id' => $organizationId],
                'season' => ['type' => 'seasons', 'id' => (string) $this->season->getKey()],
            ],
        );
    }

    public function test_an_organization_administrator_opens_a_window_for_their_organization(): void
    {
        $this->window->delete();

        $response = $this->request($this->organizationAdmin, 'POST', self::BASE.'/registration-windows', $this->windowDocument((string) $this->organization->getKey()));

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'registration-windows')
            ->assertJsonPath('data.attributes.roles', ['participant', 'coach'])
            ->assertJsonPath('data.attributes.isOpen', 'true');

        $window = RegistrationWindow::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->organizationAdmin->getKey(), $window->created_by_user_id);
        $this->assertSame('window.opened', AuditEntry::query()->latest('id')->first()->action);
    }

    public function test_an_applicant_cannot_open_a_window(): void
    {
        $this->request($this->applicant, 'POST', self::BASE.'/registration-windows', $this->windowDocument((string) $this->otherOrganization->getKey()))
            ->assertStatus(403);
    }

    public function test_an_administrator_cannot_open_a_window_for_another_organization(): void
    {
        $this->request($this->organizationAdmin, 'POST', self::BASE.'/registration-windows', $this->windowDocument((string) $this->otherOrganization->getKey()))
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'organization_not_administered');
    }

    public function test_a_federation_administrator_can_open_a_window_for_any_organization(): void
    {
        $this->request($this->federationAdmin, 'POST', self::BASE.'/registration-windows', $this->windowDocument((string) $this->otherOrganization->getKey()))
            ->assertStatus(201);
    }

    public function test_windows_are_listed_and_filtered_by_openness(): void
    {
        RegistrationWindow::factory()->closed()->create([
            'member_organization_id' => $this->otherOrganization->getKey(),
            'season_id' => $this->season->getKey(),
        ]);

        $this->request($this->applicant, 'GET', self::BASE.'/registration-windows')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->request($this->applicant, 'GET', self::BASE.'/registration-windows?filter[open]=true&include=memberOrganization')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $this->window->getKey())
            ->assertJsonPath('included.0.attributes.code', 'NYSA');
    }

    public function test_validation_rejects_a_window_that_closes_before_it_opens(): void
    {
        $document = $this->windowDocument((string) $this->organization->getKey());
        $document['data']['attributes']['closesAt'] = now()->subYear()->toIso8601String();

        $this->request($this->organizationAdmin, 'POST', self::BASE.'/registration-windows', $document)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/closesAt');
    }
}
