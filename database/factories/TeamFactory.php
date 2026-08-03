<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        $location = fake()->unique()->city();
        $nickname = fake()->word();

        return [
            // ESPN ids are natural keys, so factories mint plausible ones.
            'id' => fake()->unique()->numberBetween(1, 999999),
            'slug' => Str::slug("{$location}-{$nickname}-".fake()->unique()->randomNumber(5)),
            'location' => $location,
            'name' => ucfirst($nickname),
            'abbreviation' => strtoupper(Str::substr($location, 0, 3)),
            'display_name' => "{$location} ".ucfirst($nickname),
            'color' => ltrim(fake()->hexColor(), '#'),
            'alt_color' => ltrim(fake()->hexColor(), '#'),
        ];
    }
}
