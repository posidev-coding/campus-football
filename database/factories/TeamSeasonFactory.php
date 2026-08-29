<?php

namespace Database\Factories;

use App\Models\Conference;
use App\Models\Team;
use App\Models\TeamSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A team's conference FOR ONE SEASON — the row that exists because membership
 * is season-scoped and a scalar `teams.conference_id` would be a lie. Oregon
 * is Pac-12 in 2021 and Big Ten in 2025.
 *
 * `season_year` is pinned rather than read from the calendar: a fixture is a
 * claim about its own data, and "never hardcode the season" governs code
 * paths, not test rows a caller can override.
 *
 * @extends Factory<TeamSeason>
 */
class TeamSeasonFactory extends Factory
{
    protected $model = TeamSeason::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'season_year' => 2026,
            'conference_id' => Conference::factory(),
            'division_id' => null,
            'classification' => 'fbs',
        ];
    }
}
