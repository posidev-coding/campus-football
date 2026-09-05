<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupInvite>
 */
class GroupInviteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'inviter_id' => User::factory(),
            'invitee_id' => User::factory(),
        ];
    }
}
