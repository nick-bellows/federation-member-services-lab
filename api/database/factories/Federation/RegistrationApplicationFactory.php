<?php

namespace Database\Factories\Federation;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationApplication>
 */
class RegistrationApplicationFactory extends Factory
{
    protected $model = RegistrationApplication::class;

    /**
     * Creates a DRAFT application whose season belongs to the organization's
     * federation. Status is never set here: use TransitionApplication.
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
            'applicant_user_id' => User::factory(),
            'role' => $this->faker->randomElement(ApplicationRole::cases()),
        ];
    }
}
