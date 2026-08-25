<?php

namespace Database\Factories;

use App\Enums\GamedayStatus;
use App\Models\GamedayWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GamedayWeek>
 */
class GamedayWeekFactory extends Factory
{
    protected $model = GamedayWeek::class;

    /**
     * The default is the honest one: a Saturday nobody has announced yet.
     * A test that wants a site says so.
     */
    public function definition(): array
    {
        return [
            'season_year' => 2026,
            'saturday' => '2026-09-05',
            'status' => GamedayStatus::Unknown,
            'checked_at' => now(),
        ];
    }

    public function proposed(string $site = 'LSU', string $city = 'Baton Rouge', string $state = 'LA'): static
    {
        return $this->state(fn (): array => [
            'site' => $site,
            'city' => $city,
            'state' => $state,
            'status' => GamedayStatus::Proposed,
        ]);
    }

    public function confirmed(): static
    {
        return $this->proposed()->state(fn (): array => [
            'status' => GamedayStatus::Confirmed,
        ]);
    }
}
