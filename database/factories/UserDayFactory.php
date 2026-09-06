<?php

namespace Database\Factories;

use App\Enums\ActivityArea;
use App\Enums\ViewportBucket;
use App\Models\User;
use App\Models\UserDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserDay> */
class UserDayFactory extends Factory
{
    /**
     * One person, present on a pinned day, having read Home once.
     *
     * The bounds are pinned rather than derived from `day`, so a caller
     * moving the day gets a day it can assert on — and a caller who cares
     * about the bounds pins those too. Deriving them here would keep the
     * default day's instants under an overridden `day`, which is the
     * factory trap this suite has paid for twice.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'day' => '2026-09-02',
            'views' => 1,
            'actions' => 0,
            'areas' => ActivityArea::Home->value,
            'features' => 0,
            'first_seen_at' => '2026-09-02 16:00:00',
            'last_seen_at' => '2026-09-02 16:00:00',
            'viewport_bucket' => ViewportBucket::Phone,
        ];
    }
}
