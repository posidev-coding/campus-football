<?php

namespace Database\Factories;

use App\Models\Conference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conference> */
class ConferenceFactory extends Factory
{
    protected $model = Conference::class;

    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 60000),
            'name' => fake()->words(2, true).' Conference',
            'abbreviation' => strtoupper(fake()->lexify('???')),
            'is_conference' => true,
        ];
    }
}
