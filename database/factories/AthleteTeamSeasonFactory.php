<?php

namespace Database\Factories;

use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A player's team FOR ONE SEASON. There is deliberately no `athletes.team_id`
 * — a player's team is a fact about a season, so it is asked with a year.
 *
 * @extends Factory<AthleteTeamSeason>
 */
class AthleteTeamSeasonFactory extends Factory
{
    protected $model = AthleteTeamSeason::class;

    public function definition(): array
    {
        return [
            'athlete_id' => Athlete::factory(),
            'team_id' => Team::factory(),
            'season_year' => 2026,
            'jersey' => '16',
            'position_id' => null,
            'position_group' => 'QB',
            'experience_class' => 'Junior',
            'status' => 'active',
        ];
    }
}
