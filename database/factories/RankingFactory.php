<?php

namespace Database\Factories;

use App\Models\Ranking;
use App\Models\Team;
use App\Models\Week;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pinned, including the poll name — "AP Top 25" is the string every ranking
 * surface actually looks for, and a random one silently matches nothing.
 *
 * @extends Factory<Ranking>
 */
class RankingFactory extends Factory
{
    protected $model = Ranking::class;

    public function definition(): array
    {
        return [
            'week_id' => Week::factory(),
            /*
             * Taken from the week, NOT a second Season::factory().
             *
             * WeekFactory already mints a season, so two independent factory
             * calls here would create two — and SeasonFactory draws from a
             * twelve-year range against a (year, type) unique, so a fixture
             * reaching Season twice collides about one run in twelve. That
             * fails in the full suite and passes under --filter, because the
             * faker sequence differs.
             *
             * A closure resolves AFTER `week_id` does, so it follows a week
             * the caller pinned; an explicitly passed `season_id` still wins,
             * because overrides are applied over the whole definition.
             */
            'season_id' => fn (array $attributes): int => Week::findOrFail($attributes['week_id'])->season_id,
            'poll' => 'AP Top 25',
            'team_id' => Team::factory(),
            'rank' => 1,
            'previous_rank' => 1,
            'points' => 1_500,
            'first_place_votes' => 40,
            'record' => '9-1',
        ];
    }
}
