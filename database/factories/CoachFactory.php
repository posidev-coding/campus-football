<?php

namespace Database\Factories;

use App\Models\Coach;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Career record PINNED, because `careerRecord()` renders it and a random pair
 * makes "117-21" an assertion nobody can write.
 *
 * `career_ties` is 0 rather than null on purpose: the helper appends a third
 * number only when a tie exists, and both branches want covering by callers.
 *
 * @extends Factory<Coach>
 */
class CoachFactory extends Factory
{
    protected $model = Coach::class;

    public function definition(): array
    {
        $first = fake()->firstName('male');
        $last = fake()->lastName();

        return [
            'id' => fake()->unique()->numberBetween(1, 999999),
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => "{$first} {$last}",
            'experience_years' => 12,
            'career_wins' => 117,
            'career_losses' => 21,
            'career_ties' => 0,
        ];
    }

    /** Before the coach sync has ever run: no record to print. */
    public function unsynced(): static
    {
        return $this->state([
            'career_wins' => null,
            'career_losses' => null,
            'career_ties' => null,
        ]);
    }
}
