<?php

namespace Database\Factories;

use App\Models\Pick;
use App\Models\SlateGame;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pick>
 */
class PickFactory extends Factory
{
    /**
     * Ungraded by default: result and points are null, the same statement
     * the schema makes. Grading states exist so a test never hand-writes
     * the result/points pairing and gets it half right.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slate_game_id' => SlateGame::factory(),
            'user_id' => User::factory(),
            'picked_team_id' => Team::factory(),
            'locked' => false,
            'result' => null,
            'points' => null,
        ];
    }

    public function won(int $points = 1): static
    {
        return $this->state(['result' => Pick::WIN, 'points' => $points]);
    }

    /** The Woodshed's Lock wager, staked. */
    public function locked(): static
    {
        return $this->state(['locked' => true]);
    }

    public function lost(): static
    {
        return $this->state(['result' => Pick::LOSS, 'points' => 0]);
    }

    public function pushed(): static
    {
        return $this->state(['result' => Pick::PUSH, 'points' => 0]);
    }
}
