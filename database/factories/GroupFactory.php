<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * Pinned except the code, which carries a unique index — the one column
     * where sameness across rows would be the flake, not randomness.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Test Group',
            'code' => Str::upper(Str::random(8)),
            'kind' => Group::KIND_PRIVATE,
        ];
    }

    public function lobby(): static
    {
        return $this->state(['kind' => Group::KIND_LOBBY]);
    }

    /** A transient weekly room: lobby kind plus a week and a seat cap. */
    public function room(int $weekId, int $cap = Group::DEFAULT_LOBBY_CAP): static
    {
        return $this->lobby()->state(['week_id' => $weekId, 'member_cap' => $cap]);
    }
}
