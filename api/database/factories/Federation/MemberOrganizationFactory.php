<?php

namespace Database\Factories\Federation;

use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberOrganization>
 */
class MemberOrganizationFactory extends Factory
{
    protected $model = MemberOrganization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'federation_id' => Federation::factory(),
            'name' => $this->faker->unique()->state().' Soccer Association',
            'code' => strtoupper($this->faker->unique()->lexify('????')),
        ];
    }
}
