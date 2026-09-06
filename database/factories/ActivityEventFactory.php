<?php

namespace Database\Factories;

use App\Enums\ActivityKind;
use App\Models\ActivityEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ActivityEvent> */
class ActivityEventFactory extends Factory
{
    /**
     * A member's page view of Home, at a pinned instant.
     *
     * The instant is pinned rather than drawn, because `day` and `hour` are
     * derived from it: a random `occurred_at` would make every rollup fixture
     * land on a different league day, and a test asserting one day's totals
     * would fail one run in however many days the range covered. Callers who
     * want a different moment pass `occurred_at` and get the day and hour
     * that go with it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stream_id' => fake()->unique()->numerify('17571########-#'),
            'kind' => ActivityKind::PageView,
            'user_id' => User::factory(),
            'visitor' => null,
            'audience' => ActivityEvent::MEMBER,
            'route' => 'home',
            'facet' => null,
            'subject_type' => null,
            'subject_id' => null,
            'occurred_at' => '2026-09-02 16:00:00',
            'viewport' => 390,
            'standalone' => false,
            'via_navigate' => false,
            'release' => null,
        ];
    }

    /**
     * Derive the league day and hour from the FINAL `occurred_at`, after
     * overrides — the rule `.ai/rules/data-model.md` states and GameFactory
     * already pays for. Computed in `definition()` they would keep the
     * default instant's day while `occurred_at` moved, and every rollup
     * fixture would quietly roll into a day it does not belong to.
     *
     * `??=` leaves a caller-pinned day or hour alone, which is what a test
     * for the drain's own derivation needs.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ActivityEvent $event): void {
            $league = CarbonImmutable::parse($event->occurred_at)->setTimezone(config('cfb.timezone'));

            $event->day ??= $league->toDateString();
            $event->hour ??= $league->hour;
        });
    }

    /** A guest: no user id, a session hash instead. Exactly one of the two. */
    public function guest(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'visitor' => substr(hash('sha256', fake()->unique()->uuid()), 0, 32),
            'audience' => ActivityEvent::GUEST,
        ]);
    }

    /** The founder's own browsing, which every dashboard excludes. */
    public function staff(): static
    {
        return $this->state(fn () => ['audience' => ActivityEvent::STAFF]);
    }

    /** A moment with no truth table, rather than a screen. */
    public function action(ActivityKind $kind, ?string $facet = null): static
    {
        return $this->state(fn () => ['kind' => $kind, 'facet' => $facet]);
    }
}
