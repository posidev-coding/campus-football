<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlateGame>
 */
class SlateGameFactory extends Factory
{
    /**
     * Defaults are the DRAFT state: no tier, no frozen line — null means no
     * data, exactly as it does in the schema. Remember GameFactory's kickoff
     * is random; pin `kickoff_at` on the game wherever a test's assertions
     * touch lock state or slate eligibility.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slate_id' => Slate::factory(),
            'game_id' => Game::factory(),
            'tier' => null,
            'position' => 1,
            'spread' => null,
            'favorite_team_id' => null,
            'odds_provider' => null,
            'odds_captured_at' => null,
        ];
    }

    /** A line frozen onto the row, the way PublishSlate leaves it. */
    public function frozen(): static
    {
        return $this->state([
            'spread' => -6.5,
            'favorite_team_id' => Team::factory(),
            'odds_provider' => 'ESPN BET',
            'odds_captured_at' => '2026-09-02 18:00:00',
        ]);
    }
}
