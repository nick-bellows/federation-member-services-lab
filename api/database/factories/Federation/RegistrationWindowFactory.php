<?php

namespace Database\Factories\Federation;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\RegistrationWindow;
use App\Federation\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationWindow>
 */
class RegistrationWindowFactory extends Factory
{
    protected $model = RegistrationWindow::class;

    /**
     * An open window for all roles whose season belongs to the organization's federation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_organization_id' => MemberOrganization::factory(),
            'season_id' => function (array $attributes) {
                $organization = MemberOrganization::query()->findOrFail($attributes['member_organization_id']);

                return Season::factory()->create(['federation_id' => $organization->federation_id])->getKey();
            },
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addMonth(),
            'roles' => ApplicationRole::values(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['opens_at' => now()->subMonths(2), 'closes_at' => now()->subMonth()]);
    }
}
