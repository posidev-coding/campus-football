<?php

namespace Database\Factories;

use App\Models\Athlete;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * ESPN owns `athletes.id`, so the factory mints a plausible natural key.
 *
 * Note athletes route by ID, not slug — 326 athlete slugs collide, which is
 * why `Athlete` has no `getRouteKeyName()`. The slug here is still unique so a
 * fixture cannot trip the column's own index.
 *
 * @extends Factory<Athlete>
 */
class AthleteFactory extends Factory
{
    protected $model = Athlete::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'id' => fake()->unique()->numberBetween(1, 9999999),
            'slug' => Str::slug("{$first}-{$last}-".fake()->unique()->randomNumber(5)),
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => "{$first} {$last}",
            'short_name' => Str::substr($first, 0, 1).'. '.$last,
            // Pinned: these render on the modal infolist, and a random height
            // makes any assertion about the rendered line a coin flip.
            'height_in' => 74,
            'display_height' => '6\' 2"',
            'weight_lb' => 210,
            'display_weight' => '210 lbs',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
