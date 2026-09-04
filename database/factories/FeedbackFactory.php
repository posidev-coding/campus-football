<?php

namespace Database\Factories;

use App\Enums\FeedbackKind;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feedback> */
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => FeedbackKind::Idea,
            'body' => 'The week band could say when the next game kicks off.',
            'path' => '/picks',
            'release' => 'v4.0.0-beta.11',
            'viewport' => 390,
            'standalone' => false,
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        ];
    }

    public function handled(): static
    {
        return $this->state(fn (): array => ['handled_at' => now()]);
    }
}
