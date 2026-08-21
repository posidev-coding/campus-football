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
            /*
             * PINNED, and derived from nothing. The fixture week's Saturday
             * is 2026-09-05, and a slate dated by anything live — the
             * week's range, `now()` — is a fixture that moves under every
             * assertion about the weekly clock. A caller building a slate on
             * another Saturday passes it explicitly.
             */
            'saturday' => '2026-09-05',
            'status' => Slate::DRAFT,
            'exhibition' => false,
            'published_at' => null,
            'settled_at' => null,
            'tiebreaker_slate_game_id' => null,
        ];
    }

    /** A practice slate: graded and paid, never counted. */
    public function exhibition(): static
    {
        return $this->state(['exhibition' => true]);
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
