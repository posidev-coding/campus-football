<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletEntry>
 */
class WalletEntryFactory extends Factory
{
    /**
     * Pinned values, no faker: a random XP amount is a coin flip in any test
     * that asserts a rendered total, and the ledger has no column where
     * randomness buys realism.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'xp' => 10,
            'lattes' => 0,
            'reason' => 'test-grant',
            'key' => null,
        ];
    }
}
