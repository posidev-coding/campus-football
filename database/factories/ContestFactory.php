<?php

namespace Database\Factories;

use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contest>
 */
class ContestFactory extends Factory
{
    /**
     * The year is pinned, not read from CfbCalendar: a fixture is a claim
     * about its own data, and the app rule ("never hardcode the current
     * season") governs code paths, not test rows a caller can override.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'season_year' => 2026,
            'mode' => ContestMode::Classic,
            'settings' => null,
        ];
    }

    public function tiered(): static
    {
        return $this->state(['mode' => ContestMode::Tiered]);
    }

    public function woodshed(): static
    {
        return $this->state(['mode' => ContestMode::Woodshed]);
    }
}
