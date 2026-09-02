<?php

namespace Database\Factories\Federation;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;
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
            'registration_window_id' => RegistrationWindow::factory(),
            'member_organization_id' => fn (array $attributes) => RegistrationWindow::query()
                ->findOrFail($attributes['registration_window_id'])->member_organization_id,
            'season_id' => fn (array $attributes) => RegistrationWindow::query()
                ->findOrFail($attributes['registration_window_id'])->season_id,
            'applicant_user_id' => User::factory(),
            'role' => $this->faker->randomElement(ApplicationRole::cases()),
            'date_of_birth' => $this->faker->dateTimeBetween('-50 years', '-6 years')->format('Y-m-d'),
        ];
    }
}
