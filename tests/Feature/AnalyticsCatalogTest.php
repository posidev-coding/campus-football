<?php

use App\Enums\ActivityFeature;
use App\Enums\ActivityKind;
use App\Enums\ViewportBucket;
use App\Http\Middleware\RecordPageView;
use App\Models\ActivityEvent;
use App\Models\Contest;
use App\Models\FeedRun;
use App\Models\Game;
use App\Models\Group;
use App\Models\PageViewDaily;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use App\Models\UserDay;
use App\Support\ActivityRollup;
use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use App\Support\Cadence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

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

/** Ten actives on 2026-09-03, the first `$readers` of them with the talk bit. */
function talkReaders(int $readers): void
{
    foreach (User::factory()->count(10)->create() as $i => $user) {
        UserDay::factory()->create([
            'user_id' => $user->id, 'day' => '2026-09-03',
            'features' => $i < $readers ? ActivityFeature::ReadTalk->value : 0,
        ]);
    }
}

/**
 * The first Talk tab switch ever recorded, on `$day` — and, unless the rollup
 * has not reached it yet, the bit it folds into for that person on that day.
 */
function firstTalkSwitch(string $day, bool $folded = true): void
{
    $event = ActivityEvent::factory()->create([
        'kind' => ActivityKind::TalkRead, 'facet' => 'talk', 'occurred_at' => $day.' 16:00:00',
    ]);

    if ($folded) {
        UserDay::factory()->create([
            'user_id' => $event->user_id, 'day' => $day, 'features' => ActivityFeature::ReadTalk->value,
        ]);
    }
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

        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['visitors']['guest'])->toBe(3)
            ->and($traffic['visitors']['automated'])->toBe(1)
            ->and($traffic['views']['automated'])->toBe(4)
            ->and($traffic['guest_views_per_visitor'])->toBe(1.67)
            ->and($traffic['guest_one_view_visitors'])->toBe(2);
    });

    it('cannot report a visitor with no view while the rollup is a day behind', function () {
        /*
         * CFB-85, the exact shape production caught. At 07:02 ET the hourly
         * rollup has not run since 03:00, so it holds through yesterday while
         * the raw stream already holds today. `views` read the rollup and
         * `visitors` read the stream: 9 automated visitors beside 0 automated
         * views, under one `since`.
         */
        $this->travelTo('2026-09-24 11:02:00');

        ActivityEvent::factory()->guest()->count(2)->create(['occurred_at' => '2026-09-22 16:00:00']);
        ActivityEvent::factory()->staff()->count(3)->create(['occurred_at' => '2026-09-23 15:00:00']);

        // Today, and not yet rolled: nine crawlers that said so at the door,
        // one member and one staff reader.
        foreach (range(1, 9) as $n) {
            ActivityEvent::factory()->create([
                'user_id' => null, 'visitor' => "crawler-{$n}", 'audience' => ActivityEvent::AUTOMATED,
                'occurred_at' => '2026-09-24 10:30:00',
            ]);
        }
        ActivityEvent::factory()->create(['occurred_at' => '2026-09-24 10:40:00']);
        $staff = ActivityEvent::factory()->staff()->create(['occurred_at' => '2026-09-24 10:45:00']);
        ActivityEvent::factory()->staff()->create(['user_id' => $staff->user_id, 'occurred_at' => '2026-09-24 10:50:00']);

        // The rollup as it stands at 07:02: through yesterday, and no further.
        foreach (['2026-09-22', '2026-09-23'] as $day) {
            app(ActivityRollup::class)->day(CarbonImmutable::parse($day, config('cfb.timezone')));
        }

        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['views']['automated'])->toBe(9)
            ->and($traffic['visitors']['automated'])->toBe(9)
            ->and($traffic['views']['member'])->toBe(1)
            ->and($traffic['views']['staff'])->toBe(5)
            ->and($traffic['since'])->toBe('2026-09-22');

        // The invariant, whatever the hour: the halves name the same
        // audiences, and no audience has people without pages.
        expect(array_keys($traffic['visitors']))->toEqualCanonicalizing(array_keys($traffic['views']));

        foreach ($traffic['visitors'] as $audience => $people) {
            expect($people > 0 ? $traffic['views'][$audience] > 0 : true)
                ->toBeTrue("{$audience} has {$people} visitors and no views");
        }
    });

    it('counts staff readers, so staff views belong to somebody', function () {
        // `views` has always split staff out. `visitors` had no staff key, so
        // this week's 181 staff views were read by nobody at all.
        $founder = User::factory()->create();

        ActivityEvent::factory()->staff()->count(3)->create(['user_id' => $founder->id]);

        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['views']['staff'])->toBe(3)
            ->and($traffic['visitors']['staff'])->toBe(1)
            ->and($traffic['visitors']['member'])->toBe(0);
    });

    it('does not call an action with no page a visit', function () {
        // Visitors are people who read a page, the same definition the
        // daily table's `visitors` uses, so a visitor always has a view.
        ActivityEvent::factory()->action(ActivityKind::Searched)->create();

        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['visitors']['member'])->toBe(0)
            ->and($traffic['views']['member'])->toBe(0);
    });

    it('starts a long window where the raw table still holds whole days', function () {
        /*
         * Both halves come off `activity_events`, which keeps thirty days. A
         * 90-day ask would count thirty under a ninety-day label, so `since`
         * moves up to the first whole day the prune left. That is the day
         * after the one the cut lands in, since the cut's own day is partly gone.
         */
        PageViewDaily::factory()->create(['day' => '2026-09-06']);

        $this->travelTo('2026-10-20 12:00:00');

        // Not pruned yet, but past the line — it must not be counted either,
        // or the number would depend on whether the prune had run.
        ActivityEvent::factory()->create(['occurred_at' => '2026-09-10 16:00:00']);
        ActivityEvent::factory()->create(['occurred_at' => '2026-10-01 16:00:00']);

        $traffic = catalog()->traffic(AnalyticsWindow::of(90));

        // 08:00 ET on Oct 20, less thirty days, is Sep 20; its first whole day is Sep 21.
        expect($traffic['since'])->toBe('2026-09-21')
            ->and($traffic['views']['member'])->toBe(1);
    });

    it('says how much of automated is one request per visitor', function () {
        /*
         * CFB-119. 24 automated views from 24 automated visitors: a client
         * that keeps no cookie is a new visitor on every request, and without
         * the pair the guest half carries, that read as 24 crawlers.
         */
        foreach (range(1, 4) as $n) {
            ActivityEvent::factory()->create([
                'user_id' => null, 'visitor' => "unfurl-{$n}", 'audience' => ActivityEvent::AUTOMATED,
            ]);
        }
        ActivityEvent::factory()->count(2)->create([
            'user_id' => null, 'visitor' => 'monitor', 'audience' => ActivityEvent::AUTOMATED,
        ]);

        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['views']['automated'])->toBe(6)
            ->and($traffic['visitors']['automated'])->toBe(5)
            ->and($traffic['automated_views_per_visitor'])->toBe(1.2)
            ->and($traffic['automated_one_view_visitors'])->toBe(4);
    });

    it('reads the automated pair off the same rows as the visitors, not the rollup', function () {
        // Rolled: one automated view yesterday. Not rolled: three today from
        // three cookieless requests. The pair has to describe all four, as
        // the visitor count does.
        ActivityEvent::factory()->create([
            'user_id' => null, 'visitor' => 'old', 'audience' => ActivityEvent::AUTOMATED,
            'occurred_at' => '2026-09-04 16:00:00',
        ]);
        app(ActivityRollup::class)->day(CarbonImmutable::parse('2026-09-04', config('cfb.timezone')));

        foreach (range(1, 3) as $n) {
            ActivityEvent::factory()->create([
                'user_id' => null, 'visitor' => "new-{$n}", 'audience' => ActivityEvent::AUTOMATED,
                'occurred_at' => '2026-09-05 14:00:00',
            ]);
        }

        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['visitors']['automated'])->toBe(4)
            ->and($traffic['automated_one_view_visitors'])->toBe(4)
            ->and($traffic['automated_views_per_visitor'])->toBe(1.0);
    });

    it('has no automated ratio with no automated traffic, rather than a ratio of zero', function () {
        ActivityEvent::factory()->guest()->create();

        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['automated_views_per_visitor'])->toBeNull()
            ->and($traffic['automated_one_view_visitors'])->toBe(0);
    });

    it('has no ratio at all with no guests, rather than a ratio of zero', function () {
        $traffic = catalog()->traffic(AnalyticsWindow::of(7));

        expect($traffic['guest_views_per_visitor'])->toBeNull()
            ->and($traffic['guest_one_view_visitors'])->toBe(0);
    });
});

describe('routes', function () {
    it('counts a route\'s views and visitors off the same rows while the rollup is behind', function () {
        /*
         * CFB-109. `views` came off the rollup and `visitors` off the raw
         * stream, so while the hourly pass had not reached today one row of
         * `top` described two spans: yesterday's views beside today's
         * readers. Both now count the raw page views.
         */
        [$a, $b, $c] = User::factory()->count(3)->create();

        ActivityEvent::factory()->count(2)->create(['user_id' => $a->id, 'route' => 'rankings', 'occurred_at' => '2026-09-04 16:00:00']);
        ActivityEvent::factory()->create(['user_id' => $b->id, 'route' => 'rankings', 'occurred_at' => '2026-09-04 17:00:00']);

        // Rolled through yesterday, and no further.
        app(ActivityRollup::class)->day(CarbonImmutable::parse('2026-09-04', config('cfb.timezone')));

        // Today, drained but not rolled.
        ActivityEvent::factory()->count(2)->create(['user_id' => $c->id, 'route' => 'rankings', 'occurred_at' => '2026-09-05 14:00:00']);

        // An action on the screen is not a read of it.
        ActivityEvent::factory()->action(ActivityKind::Searched)->create(['route' => 'rankings', 'occurred_at' => '2026-09-05 14:05:00']);

        $row = collect(catalog()->routes(AnalyticsWindow::of(28))['top'])->firstWhere('route', 'rankings');

        expect($row['views'])->toBe(5)
            ->and($row['visitors'])->toBe(3);
    });

    it('starts a long window where the raw table still holds whole days, and withholds quiet', function () {
        /*
         * The raw table keeps thirty days, so a 90-day ask counts thirty and
         * `since` has to say so, as traffic() does. And a screen is not quiet
         * over ninety days on a third of the evidence, however long the
         * rollup reaches back.
         */
        PageViewDaily::factory()->create(['day' => '2026-06-01', 'route' => 'home']);

        $this->travelTo('2026-10-20 12:00:00');

        // Past the line but not pruned yet: it must not count either.
        ActivityEvent::factory()->create(['route' => 'rankings', 'occurred_at' => '2026-09-10 16:00:00']);
        ActivityEvent::factory()->count(AnalyticsCatalog::QUIET_VIEWS)->create(['route' => 'rankings', 'occurred_at' => '2026-10-01 16:00:00']);

        $long = catalog()->routes(AnalyticsWindow::of(90));

        expect($long['since'])->toBe('2026-09-21')
            ->and(collect($long['top'])->firstWhere('route', 'rankings')['views'])->toBe(AnalyticsCatalog::QUIET_VIEWS)
            ->and($long['quiet'])->toBeNull();

        // Twenty-eight days fit inside thirty, so that window is whole.
        $month = catalog()->routes(AnalyticsWindow::of(28));

        expect($month['since'])->toBe('2026-09-23')
            ->and($month['quiet'])->not->toBeNull();
    });
});

describe('quiet screens', function () {
    it('counts guests on a screen only a guest can open', function () {
        /*
         * CFB-115. Quiet counted members only, so login read 0 for 28 days
         * in the same payload that put 14 views on it in a day. A signed-out
         * screen is opened by guests, and they count here. Software and the
         * staff still do not: a crawler on the door is not somebody using it.
         */
        PageViewDaily::factory()->create(['day' => '2026-08-01', 'route' => 'home']);

        ActivityEvent::factory()->guest()->count(AnalyticsCatalog::QUIET_VIEWS)->create([
            'route' => 'login', 'occurred_at' => '2026-09-04 16:00:00',
        ]);
        ActivityEvent::factory()->count(AnalyticsCatalog::QUIET_VIEWS)->create([
            'user_id' => null, 'visitor' => 'crawler', 'audience' => ActivityEvent::AUTOMATED,
            'route' => 'register', 'occurred_at' => '2026-09-04 16:00:00',
        ]);
        ActivityEvent::factory()->staff()->count(AnalyticsCatalog::QUIET_VIEWS)->create([
            'route' => 'password.request', 'occurred_at' => '2026-09-04 16:00:00',
        ]);

        $routes = catalog()->routes(AnalyticsWindow::of(28));
        $quiet = collect($routes['quiet'])->pluck('views', 'route');

        expect($quiet)->not->toHaveKey('login')
            ->and($quiet['register'])->toBe(0)
            ->and($quiet['password.request'])->toBe(0)
            // Guests open the door; they do not rank the screens members read.
            ->and(collect($routes['top'])->pluck('route'))->not->toContain('login');
    });

    it('does not let guest views hide a public screen members never open', function () {
        /*
         * Guests count on the signed-out screens and nowhere else. A crawler
         * that poses as a browser lands in GUEST, and guest traffic is mostly
         * one request per visitor (CFB-94), so five cookieless hits on
         * Rankings would otherwise erase "members do not read this".
         */
        PageViewDaily::factory()->create(['day' => '2026-08-01', 'route' => 'home']);

        ActivityEvent::factory()->guest()->count(AnalyticsCatalog::QUIET_VIEWS)->create([
            'route' => 'rankings', 'occurred_at' => '2026-09-04 16:00:00',
        ]);

        $quiet = collect(catalog()->routes(AnalyticsWindow::of(28))['quiet'])->keyBy('route');

        expect($quiet['rankings'])->toBe(['route' => 'rankings', 'views' => 0, 'audience' => 'member']);
    });

    it('reads the signed-out screens off the guest middleware', function () {
        /*
         * Off the route table, not a hand list: every screen behind `guest`
         * is judged by guests, and no screen a member can open is.
         */
        $routes = catalog()->routes(AnalyticsWindow::of(28));

        $behindGuest = collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn ($route): bool => in_array('guest', $route->gatherMiddleware(), true))
            ->keys();

        expect($routes['quiet_signed_out'])->toContain('login', 'register', 'password.request')
            ->not->toContain('rankings', 'scoreboard', 'privacy', 'home')
            ->and($behindGuest->diff($routes['quiet_signed_out'])->filter(
                fn (string $name): bool => RecordPageView::isScreenRoute($name),
            )->all())->toBe([]);
    });

    it('names the audience each list counted, beside since', function () {
        PageViewDaily::factory()->create(['day' => '2026-08-01', 'route' => 'home']);

        $routes = catalog()->routes(AnalyticsWindow::of(28));
        $quiet = collect($routes['quiet'])->keyBy('route');

        expect($routes)->toHaveKeys(['since', 'top_audience', 'quiet_audience', 'quiet_signed_out'])
            ->and($routes['top_audience'])->toBe(['member'])
            ->and($routes['quiet_audience'])->toBe(['member'])
            ->and($quiet['login']['audience'])->toBe('guest')
            ->and($quiet['rankings']['audience'])->toBe('member');
    });

    it('still withholds quiet on a window the rollup does not cover, guests or not', function () {
        PageViewDaily::factory()->create(['day' => '2026-09-04', 'route' => 'home']);

        ActivityEvent::factory()->guest()->count(9)->create([
            'route' => 'login', 'occurred_at' => '2026-09-04 16:00:00',
        ]);

        expect(catalog()->routes(AnalyticsWindow::of(28))['quiet'])->toBeNull();
    });
});

describe('top and quiet', function () {
    beforeEach(function () {
        // A rollup a month old, so the 28-day window is covered and quiet is
        // published rather than withheld.
        PageViewDaily::factory()->create(['day' => '2026-08-01', 'route' => 'home']);
    });

    $views = fn (string $route, int $count, string $at = '2026-09-04 16:00:00') => ActivityEvent::factory()
        ->count($count)->create(['route' => $route, 'occurred_at' => $at]);

    it('never names the same route in both lists', function () use ($views) {
        /*
         * CFB-117. Top was the twenty busiest routes and quiet every screen
         * under five, so with twenty routes carrying any traffic, ten were
         * at once among the most read and ones nobody opens.
         */
        $views('scoreboard', 12);
        $views('team', AnalyticsCatalog::QUIET_VIEWS);
        $views('rankings', AnalyticsCatalog::QUIET_VIEWS - 1);
        $views('stats', 1);

        $routes = catalog()->routes(AnalyticsWindow::of(28));
        $top = collect($routes['top'])->pluck('route');
        $quiet = collect($routes['quiet'])->pluck('route');

        expect($top->intersect($quiet))->toBeEmpty()
            ->and($top->all())->toBe(['scoreboard', 'team'])
            ->and($quiet)->toContain('rankings')
            ->and($quiet)->toContain('stats');
    });

    it('returns a short top short, and caps a long one', function () use ($views) {
        $views('scoreboard', 7);
        $views('rankings', 2);

        $short = catalog()->routes(AnalyticsWindow::of(28));

        // One route cleared the line: one row, not twenty, and no zeros.
        expect($short['top'])->toBe([['route' => 'scoreboard', 'views' => 7, 'visitors' => 7]])
            ->and($short['top_eligible'])->toBe(1)
            ->and($short['top_min_views'])->toBe(AnalyticsCatalog::QUIET_VIEWS);

        foreach (range(1, AnalyticsCatalog::TOP_ROUTES + 2) as $n) {
            $views("route-{$n}", AnalyticsCatalog::QUIET_VIEWS);
        }

        $long = catalog()->routes(AnalyticsWindow::of(28));

        expect($long['top'])->toHaveCount(AnalyticsCatalog::TOP_ROUTES)
            ->and($long['top_eligible'])->toBe(AnalyticsCatalog::TOP_ROUTES + 3);
    });

    it('applies the line to views inside the window since reports', function () use ($views) {
        // The window opens Aug 9. Views the day before do not lift a screen
        // out of quiet or into top.
        $views('rankings', AnalyticsCatalog::QUIET_VIEWS - 1, '2026-08-09 16:00:00');
        $views('rankings', 3, '2026-08-08 16:00:00');

        $routes = catalog()->routes(AnalyticsWindow::of(28));

        expect($routes['since'])->toBe('2026-08-09')
            ->and(collect($routes['quiet'])->firstWhere('route', 'rankings')['views'])->toBe(AnalyticsCatalog::QUIET_VIEWS - 1)
            ->and(collect($routes['top'])->pluck('route'))->not->toContain('rankings');
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

    it('reports read_talk as no data until the talk tab switch has been recorded', function () {
        /*
         * CFB-107. A 0 beside a share of 0 is what got the talk filed as
         * "nobody reads it", when the tab switch most readers take had never
         * left a row at all. Until it has, the facet is uninstrumented — a
         * null the advisor skips — even with a `?view=talk` load in the week.
         */
        talkReaders(3);

        $before = catalog()->adoption(AnalyticsWindow::of(7))['features'];

        expect($before['read_talk'])->toBe(['users' => null, 'share' => null, 'since' => null])
            // Only the uninstrumented facet: a posted talk is a truth-table
            // fact and its 0 is a real zero.
            ->and($before['talked'])->toBe(['users' => 0, 'share' => 0.0]);

        // Counting since before the window opened (2026-08-30), and folded.
        firstTalkSwitch('2026-08-29');

        expect(catalog()->adoption(AnalyticsWindow::of(7))['features']['read_talk'])
            ->toBe(['users' => 3, 'share' => 0.3, 'since' => '2026-08-29']);
    });

    it('withholds read_talk for a window that opens before the switch was counted', function () {
        /*
         * Shipped mid-window. Three readers on the 3rd is a real count of the
         * days since the 2nd and an unknown for the four before it, so a
         * share over all seven reads low for a reason nobody can see — the
         * `funnel_since` mistake. The day it started is still published.
         */
        talkReaders(3);
        firstTalkSwitch('2026-09-02');

        expect(catalog()->adoption(AnalyticsWindow::of(7))['features']['read_talk'])
            ->toBe(['users' => null, 'share' => null, 'since' => '2026-09-02']);
    });

    it('reads read_talk once the window opens on or after the first counted day', function () {
        talkReaders(3);
        // The window's own first day: 2026-08-30 is counted whole, and the
        // switcher is in it — an eleventh active and a fourth reader.
        firstTalkSwitch('2026-08-30');

        expect(catalog()->adoption(AnalyticsWindow::of(7))['features']['read_talk'])
            ->toBe(['users' => 4, 'share' => 0.364, 'since' => '2026-08-30']);
    });

    it('keeps read_talk null while the first switch is recorded but not yet folded', function () {
        /*
         * The raw row lands before the rollup sets the bit. Read off the raw
         * row alone, the gap publishes a share near zero for however long the
         * fold takes; the first switch counts once its person carries it.
         */
        talkReaders(3);
        firstTalkSwitch('2026-08-29', folded: false);

        expect(catalog()->adoption(AnalyticsWindow::of(7))['features']['read_talk'])
            ->toBe(['users' => null, 'share' => null, 'since' => null]);
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

describe('actives today', function () {
    /** A completed hourly `--today` pass, started at the given instant. */
    function todayPass(string $at): void
    {
        test()->travelTo($at);
        FeedRun::begin(ActivityRollup::TODAY_LEDGER, null)->complete(1, 0, 5);
    }

    it('reads null, not 0, when today has not been rolled — and names the last day that was', function () {
        /*
         * 06:11 ET, inside the 03:00-08:00 gap the hourly pass is gated off
         * for, and the last pass ran on the 4th: today has no rows because
         * nothing has looked, not because nobody came. A 0 here — or
         * yesterday's number — is a default written over missing data.
         */
        todayPass('2026-09-05 02:00:00'); // Fri Sep 4, 22:00 ET

        foreach (User::factory()->count(3)->create() as $user) {
            UserDay::factory()->create(['user_id' => $user->id, 'day' => '2026-09-04']);
        }

        $this->travelTo('2026-09-05 10:11:00'); // Sat Sep 5, 06:11 ET

        $actives = catalog()->actives();

        expect($actives['dau'])->toBeNull()
            ->and($actives['dau_day'])->toBe('2026-09-05')
            ->and($actives['dau_as_of'])->toBe('2026-09-04T22:00:00-04:00');
    });

    it('ignores a pass that failed or is still running', function () {
        todayPass('2026-09-05 02:00:00');

        $this->travelTo('2026-09-05 13:00:00');
        FeedRun::begin(ActivityRollup::TODAY_LEDGER, null)->fail('lost the connection', 0, 5);
        FeedRun::begin(ActivityRollup::TODAY_LEDGER, null);

        $actives = catalog()->actives();

        expect($actives['dau'])->toBeNull()
            ->and($actives['dau_as_of'])->toBe('2026-09-04T22:00:00-04:00');
    });

    it('counts today once the hourly pass has rolled it, and says as of when', function () {
        todayPass('2026-09-05 06:00:00'); // Sat 02:00 ET

        foreach (User::factory()->count(2)->create() as $user) {
            UserDay::factory()->create(['user_id' => $user->id, 'day' => '2026-09-05']);
        }
        UserDay::factory()->create(['user_id' => User::factory(), 'day' => '2026-09-04']);

        $this->travelTo('2026-09-05 10:11:00'); // 06:11 ET, still the gap

        $actives = catalog()->actives();

        expect($actives['dau'])->toBe(2)
            ->and($actives['dau_day'])->toBe('2026-09-05')
            ->and($actives['dau_as_of'])->toBe('2026-09-05T02:00:00-04:00');
    });

    it('reads a rolled day with nobody in it as a real 0', function () {
        // The ledger, not `user_days`, is what tells "rolled and empty" from
        // "not rolled": an empty day writes no row either way.
        todayPass('2026-09-05 13:00:00');

        expect(catalog()->actives()['dau'])->toBe(0);
    });

    it('says off season that today is not rolled hourly at all, and still reads null', function () {
        /*
         * The hourly pass is gated on the schedule's season, so in March no
         * pass is ever due and `dau` is null all day. That is not a fault,
         * and the payload has to say so — still without writing a number.
         */
        todayPass('2027-01-31 20:00:00'); // Sun Jan 31, 15:00 ET, the last in-season day
        UserDay::factory()->create(['user_id' => User::factory(), 'day' => '2027-03-10']);

        $this->travelTo('2027-03-10 17:00:00'); // Wed Mar 10, 12:00 ET

        $actives = catalog()->actives();

        expect($actives['dau'])->toBeNull()
            ->and($actives['dau_hourly'])->toBeFalse()
            ->and($actives['dau_as_of'])->toBe('2027-01-31T15:00:00-05:00');
    });

    it('rolls hourly in season', function () {
        expect(catalog()->actives()['dau_hourly'])->toBeTrue();
    });

    it('carries a bound beside every number in the section', function () {
        /*
         * `dau` was the one count here with nothing saying what it covered.
         * Pin the whole key set, so the next number added has to arrive with
         * its window or fail here.
         */
        $bounds = [
            'dau' => ['dau_day', 'dau_as_of', 'dau_hourly'],
            'league_week_actives' => ['league_week_since', 'league_week_days'],
            'rolling_28d_actives' => ['rolling_28d_since'],
            'stickiness' => ['stickiness_covered_days'],
        ];

        $keys = array_keys(catalog()->actives());

        expect($keys)->toEqualCanonicalizing(array_merge(array_keys($bounds), ...array_values($bounds)));
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

    it('states the audience it counted beside routes\' own, under the same window', function () {
        /*
         * devices and routes both said window_days 28 and the same since,
         * and devices summed 15,313 views while routes summed 590, because
         * only routes filters to members (CFB-133). Neither number is wrong;
         * the label was borrowed by two populations. Each now names its own.
         */
        $seed = fn (int $audience, int $views, ViewportBucket $bucket) => PageViewDaily::factory()->create([
            'day' => '2026-09-04', 'route' => 'scoreboard', 'views' => $views,
            'audience' => $audience, 'viewport_bucket' => $bucket,
            'installed' => $bucket === ViewportBucket::Unknown ? PageViewDaily::UNKNOWN : PageViewDaily::BROWSER,
        ]);

        $seed(ActivityEvent::AUTOMATED, 40, ViewportBucket::Unknown);
        $seed(ActivityEvent::GUEST, 6, ViewportBucket::Phone);
        $seed(ActivityEvent::MEMBER, 5, ViewportBucket::Compact);
        $seed(ActivityEvent::STAFF, 2, ViewportBucket::Desktop);

        ActivityEvent::factory()->count(5)->create(['route' => 'scoreboard', 'occurred_at' => '2026-09-04 16:00:00']);
        ActivityEvent::factory()->guest()->count(6)->create(['route' => 'scoreboard', 'occurred_at' => '2026-09-04 16:00:00']);

        $devices = catalog()->devices(AnalyticsWindow::of(28));
        $routes = catalog()->routes(AnalyticsWindow::of(28));

        expect($devices['audience'])->toBe(['guest', 'member', 'staff', 'automated'])
            // Everybody, crawlers included — and the crawlers stay visible
            // as `unknown` rather than being dealt out to a width.
            ->and(array_sum($devices['by_bucket']))->toBe(53)
            ->and($devices['by_bucket']['unknown'])->toBe(40)
            ->and($devices['installed_share'])->toBe(0.0)
            ->and($routes['top_audience'])->toBe(['member'])
            ->and(collect($routes['top'])->firstWhere('route', 'scoreboard')['views'])->toBe(5)
            ->and($devices['window_days'])->toBe($routes['window_days']);
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

    it('counts every row\'s room in one statement, keeping a 0 a 0 and a null a null', function () {
        /*
         * membersAt was one COUNT per contest row, which made the snapshot the
         * second-slowest request in the window it reports (CFB-135). Folded
         * into one statement, the trap is the grouped count's missing row: a
         * group nobody had joined by kickoff returns nothing, exactly like a
         * slate with no kickoff — and those are 0 and null.
         */
        $current = Cadence::currentSaturday();
        $last = $current->subWeek();

        // A slate on each Saturday with a kickoff, all in one group: the same
        // group asked about at two different kickoffs.
        $group = Group::factory()->create();
        $contest = Contest::factory()->create(['group_id' => $group->id]);
        $slates = [];

        foreach ([$last, $current] as $saturday) {
            $slates[$saturday->toDateString()] = $slate = Slate::factory()->create([
                'contest_id' => $contest->id, 'saturday' => $saturday->toDateString(),
            ]);
            SlateGame::factory()->create([
                'slate_id' => $slate->id,
                'game_id' => Game::factory()->create(['kickoff_at' => $saturday->setTime(16, 0)])->id,
            ]);
        }

        $join = fn (Group $group, $at) => $group->memberships()
            ->create(['user_id' => User::factory()->create()->id, 'role' => 'member'])
            ->forceFill(['created_at' => $at])->save();

        $join($group, $last->subDay());
        $join($group, $last->addDays(2)); // in the room for this Saturday only

        // Everybody joined after kickoff: a real 0, never a null.
        $late = Group::factory()->create();
        $lateSlate = Slate::factory()->create([
            'contest_id' => Contest::factory()->create(['group_id' => $late->id])->id,
            'saturday' => $current->toDateString(),
        ]);
        SlateGame::factory()->create([
            'slate_id' => $lateSlate->id,
            'game_id' => Game::factory()->create(['kickoff_at' => $current->setTime(16, 0)])->id,
        ]);
        $join($late, $current->addDays(1));

        // No games, so no kickoff: null, never today's roster of one.
        $empty = Group::factory()->create();
        $emptySlate = Slate::factory()->create([
            'contest_id' => Contest::factory()->create(['group_id' => $empty->id])->id,
            'saturday' => $current->toDateString(),
        ]);
        $join($empty, $last);

        DB::enableQueryLog();
        $rows = collect(catalog()->pickemHealth())->keyBy('slate_id');
        $roomCounts = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'group_members') && str_contains($query['query'], 'asked'))
            ->count();
        DB::disableQueryLog();

        expect($rows[$slates[$last->toDateString()]->id]['members'])->toBe(1)
            ->and($rows[$slates[$current->toDateString()]->id]['members'])->toBe(2)
            ->and($rows[$lateSlate->id]['members'])->toBe(0)
            ->and($rows[$emptySlate->id]['first_kickoff'])->toBeNull()
            ->and($rows[$emptySlate->id]['members'])->toBeNull()
            // Four rows, one count.
            ->and($rows)->toHaveCount(4)
            ->and($roomCounts)->toBe(1);
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
