<?php

namespace Database\Factories;

use App\Models\Network;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Network>
 */
class NetworkFactory extends Factory
{
    /**
     * A network with NO mark — the state most rows are in. Only the name is
     * random, because it carries the unique index; a test that renders a
     * mark pins the URLs itself.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->regexify('[A-Z]{3,6}'),
            'logo' => null,
            'logo_dark' => null,
        ];
    }
}
