<?php

namespace Tests\Feature\Federation;

use App\Federation\Auth\OidcUserResolver;
use App\Federation\Models\AuditEntry;
use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use App\Models\User;

class OidcGuardTest extends OidcTestCase
{
    private const ME = '/api/v1/federation/me';

    public function test_a_request_without_a_token_is_unauthenticated(): void
    {
        $this->getJson(self::ME)->assertStatus(401);
    }

    public function test_a_request_with_a_rejected_token_is_unauthenticated_and_says_nothing_specific(): void
    {
        $response = $this->withToken($this->token(['aud' => 'https://other-api.example']))->getJson(self::ME);

        $response->assertStatus(401);
        $this->assertStringNotContainsString('Audience', $response->getContent());
    }

    public function test_an_unknown_subject_with_a_verified_email_is_provisioned(): void
    {
        $this->assertDatabaseMissing('users', ['email' => 'alex.participant@northgate.example']);

        $this->withToken($this->token())->getJson(self::ME)
            ->assertOk()
            ->assertJsonPath('data.attributes.email', 'alex.participant@northgate.example')
            ->assertJsonPath('data.attributes.subject', 'auth0|alex')
            ->assertJsonPath('data.attributes.scopes', ['member:read:self', 'member:update:self', 'application:create']);

        $user = User::where('email', 'alex.participant@northgate.example')->firstOrFail();
        $this->assertSame(self::ISSUER, $user->oidc_issuer);
        $this->assertSame('auth0|alex', $user->oidc_subject);
        $this->assertSame('user.provisioned', AuditEntry::query()->latest('id')->first()->action);
    }

    public function test_a_known_subject_is_matched_without_touching_the_email(): void
    {
        $user = User::factory()->create(['email' => 'old-address@example.test']);
        $user->forceFill(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'auth0|alex'])->save();

        $this->withToken($this->token())->getJson(self::ME)
            ->assertOk()
            ->assertJsonPath('data.id', (string) $user->getKey())
            ->assertJsonPath('data.attributes.email', 'old-address@example.test');

        $this->assertSame(1, User::count());
    }

    public function test_an_existing_user_is_linked_by_verified_email_once(): void
    {
        $user = User::factory()->create(['email' => 'alex.participant@northgate.example']);

        $this->withToken($this->token())->getJson(self::ME)->assertOk();

        $this->assertSame('auth0|alex', $user->fresh()->oidc_subject);
        $this->assertSame('user.identity_linked', AuditEntry::query()->latest('id')->first()->action);
        $this->assertSame(1, User::count());
    }

    public function test_an_unverified_email_never_links_or_provisions(): void
    {
        User::factory()->create(['email' => 'alex.participant@northgate.example']);

        $this->withToken($this->token(['email_verified' => false]))->getJson(self::ME)->assertStatus(401);

        $this->assertNull(User::first()->oidc_subject);
        $this->assertSame(1, User::count());
    }

    public function test_an_email_already_linked_to_another_subject_is_a_conflict(): void
    {
        $user = User::factory()->create(['email' => 'alex.participant@northgate.example']);
        $user->forceFill(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'auth0|someone-else'])->save();

        $this->withToken($this->token())->getJson(self::ME)->assertStatus(401);

        $this->assertSame('auth0|someone-else', $user->fresh()->oidc_subject);
    }

    public function test_provisioning_can_be_disabled(): void
    {
        config(['oidc.provision_users' => false]);
        $this->app->forgetInstance(OidcUserResolver::class);

        $this->withToken($this->token())->getJson(self::ME)->assertStatus(401);

        $this->assertSame(0, User::count());
    }

    public function test_scopes_and_administered_bodies_come_from_the_database(): void
    {
        $federation = Federation::factory()->create(['code' => 'NSF']);
        $organization = MemberOrganization::factory()->create(['federation_id' => $federation->getKey(), 'code' => 'NYSA']);
        $admin = User::factory()->create(['email' => 'nysa-admin@northgate.example']);
        $organization->administrators()->attach($admin);

        // The token claims a scope it does not hold; the database decides.
        $token = $this->token([
            'sub' => 'auth0|nysa-admin',
            'email' => 'nysa-admin@northgate.example',
            'scope' => 'organization:manage federation:everything',
        ]);

        $this->withToken($token)->getJson(self::ME)
            ->assertOk()
            ->assertJsonPath('data.attributes.scopes', [
                'member:read:self', 'member:update:self', 'application:create', 'application:review', 'organization:manage',
            ])
            ->assertJsonPath('data.attributes.administeredMemberOrganizations.0.code', 'NYSA')
            ->assertJsonPath('data.attributes.administeredFederations', []);
    }

    public function test_upstream_sanctum_login_still_works_beside_the_new_guard(): void
    {
        // The public JSON:API surface is unaffected: no token → 401 from upstream's own guard.
        $this->getJson('/api/v1/clubs', ['Accept' => 'application/vnd.api+json'])->assertStatus(401);
    }
}
