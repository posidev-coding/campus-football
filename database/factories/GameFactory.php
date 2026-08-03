<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Game> */
class GameFactory extends Factory
{
    protected $model = Game::class;

    public function definition(): array
    {
        $kickoff = fake()->dateTimeBetween('-4 months', '+1 month');

        return [
            'id' => fake()->unique()->numberBetween(400000000, 499999999),
            'season_id' => Season::factory(),
            'home_team_id' => Team::factory(),
            'away_team_id' => Team::factory(),
            'kickoff_at' => $kickoff,
            'kickoff_day' => $kickoff->format('D'),
            'name' => 'Away Team at Home Team',
            'completed' => false,
        ];
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
