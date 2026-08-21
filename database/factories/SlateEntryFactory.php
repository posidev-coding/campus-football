<?php

namespace Database\Factories;

use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlateEntry>
 */
class SlateEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slate_id' => Slate::factory(),
            'user_id' => User::factory(),
            'tiebreaker_total' => null,
        ];
    }
}
