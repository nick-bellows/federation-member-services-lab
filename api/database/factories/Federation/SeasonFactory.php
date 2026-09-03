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
    /**
     * Labels must be unique per federation; the test world also creates
     * "2026/27" by hand, so generated seasons start above that range and
     * count up instead of drawing at random (a random draw collided once in CI).
     */
    private static int $nextYear = 2100;

    public function definition(): array
    {
        $year = self::$nextYear++;

        return [
            'federation_id' => Federation::factory(),
            'label' => $year.'/'.substr((string) ($year + 1), 2),
            'starts_on' => "{$year}-09-01",
            'ends_on' => ($year + 1).'-08-31',
        ];
    }
}
