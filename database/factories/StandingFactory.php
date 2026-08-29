<?php

namespace Database\Factories;

use App\Enums\StandingSource;
use App\Models\Standing;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Every column PINNED, including the derived ones.
 *
 * A standings row is read by the team page, the KPI widgets and the admin
 * table, and a random record makes any assertion about a rendered "9-1" a coin
 * flip. `win_pct` is stated rather than computed in `definition()` for the
 * usual reason: overrides are applied AFTER definition, so a computed value
 * would keep the random source's wins and quietly disagree with the pinned
 * ones. A caller wanting a different record states all of it.
 *
 * @extends Factory<Standing>
 */
class StandingFactory extends Factory
{
    protected $model = Standing::class;

    public function definition(): array
    {
        return [
            'season_year' => 2026,
            'team_id' => Team::factory(),
            'conference_id' => null,
            'source' => StandingSource::Espn,
            'overall_wins' => 9,
            'overall_losses' => 1,
            'overall_ties' => 0,
            'conf_wins' => 6,
            'conf_losses' => 1,
            'conf_ties' => 0,
            'win_pct' => 0.9,
            'conf_win_pct' => 0.857,
            'points_for' => 320,
            'points_against' => 180,
            'point_differential' => 140,
        ];
    }
}
