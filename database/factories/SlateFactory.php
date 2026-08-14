<?php

namespace Database\Factories;

use App\Models\Contest;
use App\Models\Slate;
use App\Models\Week;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Slate>
 */
class SlateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contest_id' => Contest::factory(),
            'week_id' => Week::factory(),
            'status' => Slate::DRAFT,
            'published_at' => null,
            'settled_at' => null,
            'tiebreaker_slate_game_id' => null,
        ];
    }

    /**
     * Published, with the timestamp PINNED — a `now()` here would make any
     * assertion about publish-relative behavior drift with the clock.
     */
    public function published(): static
    {
        return $this->state([
            'status' => Slate::PUBLISHED,
            'published_at' => '2026-09-02 18:00:00',
        ]);
    }
}
