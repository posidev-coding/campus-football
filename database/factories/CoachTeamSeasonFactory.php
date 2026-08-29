<?php

namespace Database\Factories;

use App\Models\Coach;
use App\Models\CoachTeamSeason;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A coach's team for one season, with that season's record pinned.
 *
 * @extends Factory<CoachTeamSeason>
 */
class CoachTeamSeasonFactory extends Factory
{
    protected $model = CoachTeamSeason::class;

    public function definition(): array
    {
        return [
            'coach_id' => Coach::factory(),
            'team_id' => Team::factory(),
            'season_year' => 2026,
            'experience' => 12,
            'wins' => 9,
            'losses' => 1,
            'ties' => 0,
        ];
    }
}
