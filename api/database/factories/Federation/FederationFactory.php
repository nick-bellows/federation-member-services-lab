<?php

namespace Database\Factories\Federation;

use App\Federation\Models\Federation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Federation>
 */
class FederationFactory extends Factory
{
    protected $model = Federation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->city().' Soccer Federation',
            'code' => strtoupper($this->faker->unique()->lexify('???')),
        ];
    }
}
