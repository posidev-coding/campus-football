<?php

namespace Database\Factories;

use App\Models\ConversationPost;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationPost>
 */
class ConversationPostFactory extends Factory
{
    /**
     * Defaults to a group topic — the scope every test with a post also has
     * on hand. Pinned body: post text renders wherever a conversation does.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'topic_type' => 'group',
            'topic_id' => Group::factory(),
            'user_id' => User::factory(),
            'body' => 'Go Vols.',
        ];
    }
}
