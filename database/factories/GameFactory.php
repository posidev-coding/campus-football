<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Game> */
class GameFactory extends Factory
{
    protected $model = Game::class;

    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(400000000, 499999999),
            'season_id' => Season::factory(),
            'home_team_id' => Team::factory(),
            'away_team_id' => Team::factory(),
            'kickoff_at' => fake()->dateTimeBetween('-4 months', '+1 month'),
            'name' => 'Away Team at Home Team',
            'completed' => false,
        ];
    }

    /**
     * Derive the stored ET day from the FINAL kickoff, after overrides.
     *
     * This lived in `definition()`, computed from the random date an override
     * was about to throw away — so `create(['kickoff_at' => '2025-09-06 …'])`
     * kept some OTHER date's weekday. Nothing reads `kickoff_day` on a pinned
     * fixture today, which is exactly why it would go unnoticed:
     * `Game::slateEligible()` filters on this column, so the first contest
     * test to use a pinned game would inherit a one-in-seven coin flip. The
     * same shape already cost a run in `SeasonFactory`, where dates derived in
     * `definition()` survived a pinned `year` and dragged every default season
     * in the app back a year.
     *
     * `afterMaking` runs AFTER overrides are applied, and `??=` leaves a
     * caller-pinned day alone — `onSaturday()` and the odds fixtures set
     * theirs deliberately.
     *
     * Read in `cfb.timezone`, never UTC, matching what `SyncGames` writes: a
     * 00:30 UTC Sunday kickoff is a Saturday night game to everyone watching.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Game $game): void {
            $game->kickoff_day ??= CarbonImmutable::parse($game->kickoff_at)
                ->setTimezone(config('cfb.timezone'))
                ->format('D');
        });
    }

    /** A finished game with a definite result. */
    public function finished(int $homeScore = 31, int $awayScore = 17): static
    {
        return $this->state(fn () => [
            'completed' => true,
            'status' => 'post',
            'home_score' => $homeScore,
            'away_score' => $awayScore,
        ]);
    }

    /** Saturday kickoff — the only day a contest may slate. */
    public function onSaturday(): static
    {
        return $this->state(function () {
            $saturday = now()->next('Saturday')->setTime(15, 30);

            return ['kickoff_at' => $saturday, 'kickoff_day' => 'Sat'];
        });
    }
}
