<?php

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Season> */
class SeasonFactory extends Factory
{
    protected $model = Season::class;

    public function definition(): array
    {
        $year = fake()->numberBetween(2015, 2026);

        return [
            'year' => $year,
            'type' => Season::REGULAR,
            'name' => "{$year} Regular Season",
            'start_date' => "{$year}-08-24",
            'end_date' => "{$year}-12-14",
            'is_current' => false,
        ];
    }
}
