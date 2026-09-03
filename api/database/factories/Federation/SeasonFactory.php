<?php

namespace Database\Factories\Federation;

use App\Federation\Models\Federation;
use App\Federation\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    protected $model = Season::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = $this->faker->unique()->numberBetween(2000, 2099);

        return [
            'federation_id' => Federation::factory(),
            'label' => $year.'/'.substr((string) ($year + 1), 2),
            'starts_on' => "{$year}-09-01",
            'ends_on' => ($year + 1).'-08-31',
        ];
    }
}
