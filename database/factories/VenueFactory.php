<?php

namespace Database\Factories;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ESPN owns `venues.id`, so the factory mints a plausible natural key the
 * same way the team and conference factories do.
 *
 * Capacity, indoor and grass are pinned: they are sortable columns on the
 * admin table and a random capacity makes an ordering assertion a coin flip.
 *
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    protected $model = Venue::class;

    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 999999),
            'name' => fake()->unique()->lastName().' Stadium',
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'capacity' => 60_000,
            'indoor' => false,
            'grass' => true,
        ];
    }
}
