<?php

use App\Enums\ActivityFeature;
use App\Enums\ViewportBucket;
use App\Models\ActivityEvent;
use App\Models\Contest;
use App\Models\Game;
use App\Models\Group;
use App\Models\PageViewDaily;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use App\Models\UserDay;
use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use App\Support\Cadence;
use Carbon\CarbonImmutable;

/*
 * The named questions of the analysis table, asked directly.
 *
 * TelemetryTest reaches this class through the payload and proves the sections
 * are identity-free and null where they must be. This file holds the questions
 * whose answers do NOT go into the snapshot — the heat map and the pick timing
 * a dashboard reads — and the arithmetic underneath the ones that do.
 */

beforeEach(function () {
    $this->travelTo('2026-09-05 18:00:00');
});

function catalog(): AnalyticsCatalog
{
    return app(AnalyticsCatalog::class);
}

describe('traffic', function () {
    it('counts software apart from guests, and says how much of guest is one request', function () {
        /*
         * Guest "visitors" ran 2,894 against 2,996 views, 1.03 each, because a
         * client that never returns the session cookie is a new visitor on
         * every request (CFB-94). What said it was software is AUTOMATED;
         * what is left carries the two numbers a reader needs to judge it.
         */
        $guest = fn (string $visitor, int $views) => ActivityEvent::factory()->count($views)->create([
            'user_id' => null, 'visitor' => $visitor, 'audience' => ActivityEvent::GUEST,
        ]);

        $guest('reader', 3);
        $guest('one-a', 1);
        $guest('one-b', 1);

        ActivityEvent::factory()->count(4)->create([
            'user_id' => null, 'visitor' => 'crawler', 'audience' => ActivityEvent::AUTOMATED,
        ]);

        PageViewDaily::factory()->create(['day' => '2026-09-02', 'audience' => ActivityEvent::AUTOMATED, 'views' => 4]);

        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['visitors']['guest'])->toBe(3)
            ->and($traffic['visitors']['automated'])->toBe(1)
            ->and($traffic['views']['automated'])->toBe(4)
            ->and($traffic['guest_views_per_visitor'])->toBe(1.67)
            ->and($traffic['guest_one_view_visitors'])->toBe(2);
    });

    it('has no ratio at all with no guests, rather than a ratio of zero', function () {
        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['guest_views_per_visitor'])->toBeNull()
            ->and($traffic['guest_one_view_visitors'])->toBe(0);
    });
});

describe('adoption', function () {
    it('divides each feature by the people who were here, not by everybody', function () {
        // "Do the people who are here use this" is a different question from
        // "do the people who signed up in March", and only the first one is
        // actionable this week.
        User::factory()->count(40)->create();

        $present = User::factory()->count(10)->create();

        foreach ($present as $i => $user) {
            UserDay::factory()->create([
                'user_id' => $user->id,
                'day' => '2026-09-03',
                'features' => $i < 4 ? ActivityFeature::Picked->value : 0,
            ]);
        }

        $adoption = catalog()->adoption(AnalyticsWindow::of(7));

        expect($adoption['rolling_actives'])->toBe(10)
            ->and($adoption['features']['picked']['users'])->toBe(4)
            ->and($adoption['features']['picked']['share'])->toBe(0.4);
    });

    it('withholds every share when the week is under the floor', function () {
        // Nine people, and one of them moves the number eleven points.
        $present = User::factory()->count(9)->create();

        foreach ($present as $user) {
            UserDay::factory()->create([
                'user_id' => $user->id, 'day' => '2026-09-03',
                'features' => ActivityFeature::Picked->value,
            ]);
        }

        $adoption = catalog()->adoption(AnalyticsWindow::of(7));

        expect($adoption['rolling_actives'])->toBe(9)
            // The COUNT stays. A null share with a visible 9 is readable; a
            // null with nothing beside it is just a hole.
            ->and($adoption['features']['picked']['users'])->toBe(9)
            ->and($adoption['features']['picked']['share'])->toBeNull();
    });
});

describe('actives', function () {
    it('divides the daily mean by the days it actually covered', function () {
        /*
         * Stickiness is mean daily over monthly. Dividing by 28 on a rollup
         * that has covered four days reports a ninety percent collapse in
         * daily use that never happened — the `funnel_since` bug in a rate.
         *
         * Ten people, each here on two of the two covered days: the mean daily
         * is 10, the monthly is 10, and stickiness is 1.0. Divided by 28 it
         * would read 0.071.
         */
        $people = User::factory()->count(10)->create();

        foreach ($people as $user) {
            foreach (['2026-09-04', '2026-09-05'] as $day) {
                UserDay::factory()->create(['user_id' => $user->id, 'day' => $day]);
            }
        }

        $actives = catalog()->actives();

        expect($actives['rolling_28d_actives'])->toBe(10)
            ->and($actives['stickiness_covered_days'])->toBe(2)
            ->and($actives['stickiness'])->toBe(1.0);
    });

    it('names each window, so a two-day league week never reads as a rolling seven', function () {
        /*
         * Wednesday, the day the two diverge most. The same snapshot used to
         * publish audience.actives.wau = 4 and audience.adoption.wau = 14:
         * the league week (Tuesday on, two days wide) and a rolling seven
         * days, under one name and beside the 28-day window's since (CFB-87).
         * Both numbers are right. Only the names were wrong, so the names are
         * what this pins.
         */
        $this->travelTo('2026-09-09 16:00:00'); // Wed noon ET; the week turned over Tuesday

        $days = ['2026-09-05' => 4, '2026-09-07' => 3, '2026-09-08' => 2, '2026-09-09' => 1];

        foreach ($days as $day => $people) {
            foreach (User::factory()->count($people)->create() as $user) {
                UserDay::factory()->create(['user_id' => $user->id, 'day' => $day]);
            }
        }

        $actives = catalog()->actives();
        $adoption = catalog()->adoption(AnalyticsWindow::of(7));

        // Tuesday and Wednesday only: the Monday three belong to last week.
        expect($actives['league_week_actives'])->toBe(3)
            ->and($actives['league_week_since'])->toBe('2026-09-08')
            ->and($actives['league_week_days'])->toBe(2)
            // Everybody since the 3rd, bounded by the rollup's first day.
            ->and($adoption['rolling_actives'])->toBe(10)
            ->and($adoption['window_days'])->toBe(7)
            ->and($adoption['since'])->toBe('2026-09-05')
            ->and($actives['rolling_28d_actives'])->toBe(10)
            ->and($actives['rolling_28d_since'])->toBe('2026-09-05');

        // The collision itself: no bare `wau`, and no one `since` in a
        // section that holds three windows.
        expect($actives)->not->toHaveKey('wau')
            ->and($actives)->not->toHaveKey('since')
            ->and($actives)->not->toHaveKey('stickiness_28d')
            ->and($adoption)->not->toHaveKey('wau');
    });
});

/** Eleven people who registered on Tue Sep 1, each here in every week since. */
function retainedCohort(): void
{
    foreach (User::factory()->count(11)->create(['created_at' => '2026-09-01 16:00:00']) as $user) {
        foreach (['2026-09-02', '2026-09-09', '2026-09-16', '2026-09-22'] as $day) {
            UserDay::factory()->create(['user_id' => $user->id, 'day' => $day]);
        }
    }
}

describe('retention', function () {
    it('publishes no cell for a week that has not closed', function () {
        /*
         * Wednesday Sep 23: the 2026-09-01 cohort's fourth week runs Sep 22
         * to Sep 28, and two of its seven days have happened. The grid read
         * [1, 0.636, 0.636, 0.182], a collapse that was only an unfinished
         * week (CFB-95). The count is what is asserted, because a null or a
         * zero in the fourth slot would both be the same lie.
         */
        $this->travelTo('2026-09-23 16:00:00');
        retainedCohort();

        $row = collect(catalog()->retention())->firstWhere('cohort', '2026-09-01');

        expect($row['size'])->toBe(11)
            ->and($row['weeks'])->toHaveCount(3)
            ->and($row['weeks'])->toBe([1.0, 1.0, 1.0]);
    });

    it('keeps every cell once the week has closed', function () {
        // The Tuesday after: Sep 22-28 is complete, so the row is whole.
        $this->travelTo('2026-09-29 16:00:00');
        retainedCohort();

        $row = collect(catalog()->retention())->firstWhere('cohort', '2026-09-01');

        expect($row['weeks'])->toBe([1.0, 1.0, 1.0, 1.0]);
    });
});

/** Twelve people, here on each of the given Saturdays. */
function saturdayRegulars(array $saturdays): void
{
    foreach (User::factory()->count(12)->create() as $user) {
        foreach ($saturdays as $day) {
            UserDay::factory()->create(['user_id' => $user->id, 'day' => $day]);
        }
    }
}

describe('saturday retention', function () {
    it('never publishes a share for a Saturday that has not been played', function () {
        /*
         * Wednesday Sep 23. The last pair runs Sep 19 to the COMING Saturday,
         * Sep 26, and it was published as 0% retained, three days before
         * anybody could have come back (CFB-92). The 19th is real and counted;
         * the 26th is not over, so what depends on it is null, not 0.
         */
        $this->travelTo('2026-09-23 16:00:00');
        saturdayRegulars(['2026-09-12', '2026-09-19']);

        $pairs = collect(catalog()->saturdayRetention()['pairs'])->keyBy('to');

        expect($pairs['2026-09-26']['active'])->toBe(12)
            ->and($pairs['2026-09-26']['retained'])->toBeNull()
            ->and($pairs['2026-09-26']['share'])->toBeNull()
            // The pair that HAS been played still answers, in full.
            ->and($pairs['2026-09-19']['retained'])->toBe(12)
            ->and($pairs['2026-09-19']['share'])->toBe(1.0);
    });

    it('writes no zero for a Saturday the sensor never covered', function () {
        /*
         * The rollup starts on Sep 12. Every Saturday before it read
         * `active: 0`, a claim that nobody was here, when the data says
         * nothing at all. The section's since says where it starts.
         */
        $this->travelTo('2026-09-23 16:00:00');
        saturdayRegulars(['2026-09-12', '2026-09-19']);

        $section = catalog()->saturdayRetention();
        $pairs = collect($section['pairs'])->keyBy('from');

        expect($section['since'])->toBe('2026-09-12')
            ->and($pairs['2026-08-15']['active'])->toBeNull()
            ->and($pairs['2026-08-15']['retained'])->toBeNull()
            ->and($pairs['2026-08-15']['share'])->toBeNull()
            // Uncovered at the FROM end and covered at the TO end is still
            // unanswerable: there is nobody to have retained.
            ->and($pairs['2026-09-05']['active'])->toBeNull()
            ->and($pairs['2026-09-05']['retained'])->toBeNull()
            ->and($pairs['2026-09-12']['active'])->toBe(12);
    });

    it('counts a covered, finished Saturday nobody came to as a real zero', function () {
        // Not every zero is a substitution. With the sensor running and the
        // Saturday over, an empty bucket IS the measurement.
        $this->travelTo('2026-09-23 16:00:00');
        saturdayRegulars(['2026-09-12']);

        $pair = collect(catalog()->saturdayRetention()['pairs'])->firstWhere('from', '2026-09-12');

        expect($pair['active'])->toBe(12)
            ->and($pair['retained'])->toBe(0)
            ->and($pair['share'])->toBe(0.0);
    });
});

describe('cohorts', function () {
    it('counts arrival at Picks, so the funnel never narrows below entered', function () {
        /*
         * The Sep 1 cohort read reached_picks 8, entered 11 (CFB-89), which is
         * impossible as a funnel. `picks_first_seen_at` is stamped only by the
         * /picks HOME screen, so a member who went straight into a clubhouse
         * from an invite and picked the whole card was not counted.
         *
         * Twelve people. 0-2 opened /picks; 2-6 entered a slate, and 3-6 of
         * those never went near /picks. Seven reached Picks.
         */
        $people = User::factory()->count(12)->create(['created_at' => '2026-09-01 16:00:00']);

        foreach ($people->take(3) as $user) {
            $user->forceFill(['picks_first_seen_at' => '2026-09-02 12:00:00'])->save();
        }

        foreach ($people->slice(2, 5) as $user) {
            SlateEntry::factory()->create(['user_id' => $user->id]);
        }

        $row = collect(catalog()->cohorts())->firstWhere('week', '2026-09-01');

        expect($row['reached_picks'])->toBe(7)
            ->and($row['entered'])->toBe(5)
            // The column is still published, under the name of what it means.
            ->and($row['picks_home_seen'])->toBe(3)
            // And its meaning did not move: entering a slate stamps nothing.
            ->and($people[4]->fresh()->picks_first_seen_at)->toBeNull();

        // The invariant, across the whole grid and the lifecycle funnel,
        // rather than the one cell the fixture is about.
        foreach (catalog()->cohorts() as $week) {
            expect($week['reached_picks'])->toBeGreaterThanOrEqual($week['entered']);
        }

        $funnel = catalog()->lifecycle(AnalyticsWindow::of(7));

        expect($funnel['reached_picks'])->toBe(7)
            ->and($funnel['picks_home_seen'])->toBe(3)
            ->and($funnel['reached_picks'])->toBeGreaterThanOrEqual($funnel['entered']);
    });

    it('withholds activation until the LAST registrant has had seven days', function () {
        /*
         * The cohort week is Tue Sep 1 to Mon Sep 7. Somebody who registered
         * on the Monday has had one day when the week's start is seven days
         * old, and was counted as not activated anyway (CFB-95). Ten people,
         * so the floor is not what is doing the work.
         */
        User::factory()->count(9)->create(['created_at' => '2026-09-01 16:00:00']);
        User::factory()->create(['created_at' => '2026-09-07 23:00:00']);

        // Eight days after the week began, and two after it ended.
        $this->travelTo('2026-09-09 16:00:00');

        expect(collect(catalog()->cohorts())->firstWhere('week', '2026-09-01')['activated_7d'])->toBeNull();

        // A week after the week ended: everybody has had their seven days.
        $this->travelTo('2026-09-15 16:00:00');

        expect(collect(catalog()->cohorts())->firstWhere('week', '2026-09-01')['activated_7d'])->toBe(0.0);
    });
});

describe('devices', function () {
    it('keeps "not reported" as its own bucket and out of the installed rate', function () {
        /*
         * The first HTML response of a session is sent before the client
         * cookie exists, so a real share of views genuinely have no width and
         * no standalone flag. Bucketing those as Phone, or counting them as
         * "not installed", would invent the exact number the bucket measures.
         */
        PageViewDaily::factory()->create([
            'day' => '2026-09-04', 'route' => 'home', 'views' => 7,
            'viewport_bucket' => ViewportBucket::Unknown,
            'installed' => PageViewDaily::UNKNOWN,
        ]);
        PageViewDaily::factory()->create([
            'day' => '2026-09-04', 'route' => 'home', 'views' => 3,
            'viewport_bucket' => ViewportBucket::Compact,
            'installed' => PageViewDaily::STANDALONE,
        ]);
        PageViewDaily::factory()->create([
            'day' => '2026-09-04', 'route' => 'home', 'views' => 1,
            'viewport_bucket' => ViewportBucket::Desktop,
            'installed' => PageViewDaily::BROWSER,
        ]);

        $devices = catalog()->devices(AnalyticsWindow::of(28));

        expect($devices['by_bucket']['unknown'])->toBe(7)
            ->and($devices['by_bucket']['compact'])->toBe(3)
            // 3 of the 4 views that reported anything — the 7 unknowns are in
            // neither side of the rate.
            ->and($devices['reported_views'])->toBe(4)
            ->and($devices['installed_share'])->toBe(0.75);
    });

    it('reports no installed share at all when nothing reported', function () {
        PageViewDaily::factory()->create([
            'day' => '2026-09-04', 'views' => 9, 'installed' => PageViewDaily::UNKNOWN,
        ]);

        expect(catalog()->devices(AnalyticsWindow::of(28))['installed_share'])->toBeNull();
    });
});

describe('the time-of-week heat', function () {
    it('reads the hour the drain stored rather than asking MySQL to convert one', function () {
        /*
         * 01:00 UTC on a Sunday is Saturday at 21:00 in league time, and
         * `CONVERT_TZ` does not know about DST the way the drain did when it
         * wrote the columns. This is the 168-cell question, which is why it
         * never enters the snapshot — a model handed 168 numbers finds a
         * pattern in them whether or not one is there.
         */
        ActivityEvent::factory()->count(2)->create(['occurred_at' => '2026-09-06 01:00:00']);
        ActivityEvent::factory()->create(['occurred_at' => '2026-09-04 15:00:00']);

        $heat = collect(catalog()->timeOfWeek(AnalyticsWindow::of(28)));

        $saturday = $heat->firstWhere('hour', 21);

        expect($saturday['views'])->toBe(2)
            // Carbon's Saturday is 6, and dayofweek() - 1 is the same scale.
            ->and($saturday['weekday'])->toBe(6)
            ->and($heat->firstWhere('hour', 11)['views'])->toBe(1);
    });
});

describe('pick timing', function () {
    it('carries both stamps and the slate clock a rate would divide by', function () {
        // `created_at` is when somebody committed and `updated_at` is when
        // they last changed their mind; the gap between them is most of what
        // a slate's Saturday looks like.
        $slate = Slate::factory()->create(['picks_reminded_at' => '2026-09-05 12:00:00']);

        expect(catalog()->pickTiming($slate->id))
            ->toHaveKeys(['slate_id', 'picks', 'first_at', 'last_at', 'published_at', 'picks_reminded_at', 'last_call'])
            ->and(catalog()->pickTiming($slate->id)['picks'])->toBe(0);
    });

    it('answers for a slate that is not there without inventing one', function () {
        expect(catalog()->pickTiming(9_999_999)['picks'])->toBe(0);
    });
});

describe("pick'em health", function () {
    it('counts the members who could have entered, at first kickoff', function () {
        /*
         * Somebody who joined on Sunday could not have entered on Saturday,
         * and counting them turns growth into a participation problem. The
         * denominator is who was in the room when the games started.
         */
        $group = Group::factory()->create();
        $contest = Contest::factory()->create(['group_id' => $group->id]);

        $saturday = Cadence::currentSaturday();

        $slate = Slate::factory()->create([
            'contest_id' => $contest->id,
            'saturday' => $saturday->toDateString(),
        ]);

        // A real kickoff, pinned: without one there is no moment to count the
        // room at, and the catalog reports no members rather than today's.
        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => Game::factory()->create(['kickoff_at' => $saturday->setTime(16, 0)])->id,
        ]);

        foreach (range(1, 3) as $i) {
            $group->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => 'member'])
                ->forceFill(['created_at' => '2026-08-01 00:00:00'])->save();
        }

        // Joined after the fact, and so cannot be in the denominator of
        // anything about this Saturday.
        $group->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => 'member'])
            ->forceFill(['created_at' => $saturday->addDays(2)])->save();

        $row = collect(catalog()->pickemHealth())
            ->firstWhere('saturday', $saturday->toDateString());

        expect($row)->not->toBeNull()
            // The machine skin drops the one user-written field on the row.
            ->and($row['group'])->toBeNull()
            ->and($row)->toHaveKeys(['members', 'late_share', 'reminder_lift'])
            // Three at first kickoff, and the Sunday joiner is not one of them.
            ->and($row['members'])->toBe(3);
    });

    it('reports no members at all for a slate with no kickoff to count at', function () {
        // A slate with no games has no moment to count the room at, and
        // "everybody in the group right now" is a different number wearing the
        // same name — in the denominator of the one rate that can earn `high`.
        $slate = Slate::factory()->create(['saturday' => Cadence::currentSaturday()->toDateString()]);

        $row = collect(catalog()->pickemHealth())->firstWhere('slate_id', $slate->id);

        expect($row['first_kickoff'])->toBeNull()
            ->and($row['members'])->toBeNull();
    });

    it('withholds a slate rate below the entries floor', function () {
        /*
         * Four entries is not a low late-pick share, it is one person changing
         * their mind. The floor is applied where the rows are assembled rather
         * than wherever the rates are eventually computed, so the phase that
         * adds them cannot ship one unfloored.
         */
        $slate = Slate::factory()->create(['saturday' => Cadence::currentSaturday()->toDateString()]);

        SlateEntry::factory()->count(4)->create(['slate_id' => $slate->id]);

        $row = collect(catalog()->pickemHealth())->firstWhere('slate_id', $slate->id);

        expect($row['entries'])->toBe(4)
            ->and($row['late_share'])->toBeNull()
            ->and($row['reminder_lift'])->toBeNull();
    });
});

describe('resolving a path to a route', function () {
    it('names the route and never echoes the path back', function () {
        // A path carries ids, and an invite code or a signed link riding into
        // the payload is the one thing the sensor design refuses.
        $group = Group::factory()->create();

        expect(catalog()->routeFor("/groups/{$group->id}"))->toBe('pickem.group')
            ->and(catalog()->routeFor('/nothing-here'))->toBeNull()
            ->and(catalog()->routeFor(null))->toBeNull()
            ->and(catalog()->routeFor(''))->toBeNull();
    });

    it('reports no denominator rather than a zero one', function () {
        expect(catalog()->routeViews('scoreboard', 24))->toBeNull();

        ActivityEvent::factory()->create([
            'route' => 'scoreboard',
            'occurred_at' => CarbonImmutable::parse('2026-09-05 17:00:00'),
        ]);

        expect(catalog()->routeViews('scoreboard', 24))->toBe(1)
            ->and(catalog()->routeViews(null, 24))->toBeNull();
    });
});
