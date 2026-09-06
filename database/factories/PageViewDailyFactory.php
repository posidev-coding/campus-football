<?php

namespace Database\Factories;

use App\Enums\ViewportBucket;
use App\Models\ActivityEvent;
use App\Models\PageViewDaily;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PageViewDaily> */
class PageViewDailyFactory extends Factory
{
    /**
     * One member cell on Home, pinned in every dimension.
     *
     * Nothing is drawn and nothing is derived: the six columns of the unique
     * key ARE the cell's identity, so a random one would collide with itself
     * about as often as a test made two, and a `views` count derived from
     * `visitors` would survive an override of either.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'day' => '2026-09-02',
            'route' => 'home',
            // The empty string, not null: MySQL treats nulls as distinct
            // inside the unique key this table upserts on.
            'facet' => '',
            'audience' => ActivityEvent::MEMBER,
            'viewport_bucket' => ViewportBucket::Phone,
            'installed' => PageViewDaily::BROWSER,
            'views' => 1,
            'visitors' => 1,
            'navigate_views' => 0,
        ];
    }
}
