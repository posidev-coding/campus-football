<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\Week;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Week>
 */
class WeekFactory extends Factory
{
    /**
     * Dates PINNED to a 2026 week-one window, per the shared-fixture rule:
     * a random week range would wander into whatever date-window query a
     * sibling test runs. Nothing here derives from anything else, so every
     * column survives an override untouched.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'number' => 1,
            'name' => 'Week 1',
            'start_date' => '2026-09-01 00:00:00',
            'end_date' => '2026-09-07 23:59:59',
        ];
    }
}
