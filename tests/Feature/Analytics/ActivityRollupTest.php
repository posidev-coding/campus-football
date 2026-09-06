<?php

use App\Enums\ActivityArea;
use App\Enums\ActivityFeature;
use App\Enums\ActivityKind;
use App\Enums\ViewportBucket;
use App\Models\ActivityEvent;
use App\Models\ConversationPost;
use App\Models\FeedRun;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\PageViewDaily;
use App\Models\Pick;
use App\Models\Team;
use App\Models\User;
use App\Models\UserDay;
use App\Support\ActivityRollup;
use App\Support\AnalyticsWindow;
use Carbon\CarbonImmutable;

/*
 * Phase 3 of docs/plans/analytics.md: the fold from thirty days of raw rows
 * into the two tables that live on.
 *
 * The suite's clock is 2026-09-02 12:00 UTC, which is 08:00 on the same
 * league day — so "today" here is 2026-09-02 and the factory's pinned
 * `occurred_at` lands on it.
 */

/** The league day every fixture below belongs to. */
const ROLLUP_DAY = '2026-09-02';

function rollDay(string $day = ROLLUP_DAY): array
{
    return app(ActivityRollup::class)->day(CarbonImmutable::parse($day, config('cfb.timezone')));
}

/** One page view, in the cell the caller names. */
function screenView(array $attributes = []): ActivityEvent
{
    return ActivityEvent::factory()->create($attributes);
}

describe('the page-view cells', function () {
    it('counts views, hops and the people inside one cell', function () {
        $reader = User::factory()->create();

        screenView(['user_id' => $reader->id, 'route' => 'scoreboard', 'viewport' => 390, 'via_navigate' => true]);
        screenView(['user_id' => $reader->id, 'route' => 'scoreboard', 'viewport' => 390, 'via_navigate' => false]);
        screenView(['user_id' => User::factory(), 'route' => 'scoreboard', 'viewport' => 390, 'via_navigate' => true]);

        expect(rollDay()['page_views'])->toBe(1);

        $cell = PageViewDaily::sole();

        expect($cell->route)->toBe('scoreboard')
            ->and($cell->day->toDateString())->toBe(ROLLUP_DAY)
            ->and($cell->viewport_bucket)->toBe(ViewportBucket::Compact)
            ->and($cell->views)->toBe(3)
            ->and($cell->visitors)->toBe(2)
            // views - navigate_views is cold loads, which is the only way to
            // tell a hop apart from somebody arriving.
            ->and($cell->navigate_views)->toBe(2);
    });

    it('splits a cell on every dimension of its key', function () {
        // One route, one day, four cells: the width, the audience, the
        // installed state and the facet each cut it.
        screenView(['route' => 'scoreboard', 'viewport' => 390]);
        screenView(['route' => 'scoreboard', 'viewport' => 1_400]);
        screenView(['route' => 'scoreboard', 'viewport' => 390, 'standalone' => true]);
        ActivityEvent::factory()->guest()->create(['route' => 'scoreboard', 'viewport' => 390]);

        rollDay();

        expect(PageViewDaily::count())->toBe(4)
            ->and((int) PageViewDaily::sum('views'))->toBe(4);
    });

    it('counts a person once per cell, which is why visitors never adds up', function () {
        /*
         * `visitors` is a distinct count INSIDE a cell. Somebody who read the
         * same screen on a phone in the morning and a laptop at night is two
         * rows and one person, so summing the column over a window
         * double-counts them — the reason the raw table keeps thirty days and
         * any wider distinct count is recomputed from it.
         */
        $reader = User::factory()->create();

        screenView(['user_id' => $reader->id, 'route' => 'home', 'viewport' => 390]);
        screenView(['user_id' => $reader->id, 'route' => 'home', 'viewport' => 1_400]);

        rollDay();

        expect((int) PageViewDaily::sum('visitors'))->toBe(2)
            ->and(UserDay::count())->toBe(1);
    });

    it('keeps the clubhouse stop as its own cell, with an empty string for none', function () {
        screenView(['route' => 'pickem.group', 'facet' => 'talk']);
        screenView(['route' => 'pickem.group', 'facet' => null]);

        rollDay();

        // The empty string, not null: MySQL treats nulls as distinct inside a
        // unique key, so a null facet would let a re-run write a second row
        // for a cell it already had.
        expect(PageViewDaily::pluck('facet')->sort()->values()->all())->toBe(['', 'talk']);
    });

    it('counts a moment with no truth table as an action, never as a view', function () {
        $asker = User::factory()->create();

        ActivityEvent::factory()->action(ActivityKind::Searched)->create([
            'user_id' => $asker->id, 'route' => 'home',
        ]);

        rollDay();

        expect(PageViewDaily::count())->toBe(0)
            ->and(UserDay::sole())
            ->views->toBe(0)
            ->actions->toBe(1);
    });
});

describe('the presence rows', function () {
    it('reads the areas off the nav rather than a second map', function () {
        $reader = User::factory()->create();

        screenView(['user_id' => $reader->id, 'route' => 'home']);
        screenView(['user_id' => $reader->id, 'route' => 'scoreboard']);
        // Not in any area's routes: an auth screen is not Home, and the mask
        // must not gain a bit for it.
        screenView(['user_id' => $reader->id, 'route' => 'login']);

        rollDay();

        $day = UserDay::sole();

        expect(ActivityArea::Home->in($day->areas))->toBeTrue()
            ->and(ActivityArea::Scores->in($day->areas))->toBeTrue()
            ->and($day->areas)->toBe(ActivityArea::Home->value | ActivityArea::Scores->value)
            ->and($day->views)->toBe(3);
    });

    it('sets the bits only the clickstream can prove', function () {
        $reader = User::factory()->create();

        screenView(['user_id' => $reader->id, 'route' => 'pickem.group', 'facet' => 'talk']);
        screenView(['user_id' => $reader->id, 'route' => 'pickem.lobby']);
        screenView(['user_id' => $reader->id, 'route' => 'stats', 'standalone' => true]);

        rollDay();

        $features = UserDay::sole()->features;

        // Reading the talk, opening the Lobby, looking at stats and reading
        // from the installed app: four facts no table in the database holds.
        expect(ActivityFeature::ReadTalk->in($features))->toBeTrue()
            ->and(ActivityFeature::Lobby->in($features))->toBeTrue()
            ->and(ActivityFeature::Stats->in($features))->toBeTrue()
            ->and(ActivityFeature::Installed->in($features))->toBeTrue()
            // Nothing was posted, so the bit beside ReadTalk stays clear —
            // the two are what tell a quiet room from an empty one.
            ->and(ActivityFeature::Talked->in($features))->toBeFalse();
    });

    it('leaves Installed clear for a browser and for a client that never said', function () {
        /*
         * Three states, and only ONE of them is the app. A first load has no
         * cookie yet, so null is "not reported"; false is a browser, which is
         * the state most page views are in. A bit set on "we were told
         * something" rather than on "we were told yes" would report the whole
         * audience as installed.
         */
        $browser = User::factory()->create();
        $unknown = User::factory()->create();

        screenView(['user_id' => $browser->id, 'route' => 'home', 'standalone' => false]);
        screenView(['user_id' => $unknown->id, 'route' => 'home', 'standalone' => null]);

        rollDay();

        expect(UserDay::count())->toBe(2)
            ->and(UserDay::get()->filter(fn (UserDay $day) => ActivityFeature::Installed->in($day->features)))
            ->toBeEmpty();
    });

    it('takes the width the day was mostly read at', function () {
        $reader = User::factory()->create();

        screenView(['user_id' => $reader->id, 'viewport' => 390]);
        screenView(['user_id' => $reader->id, 'viewport' => 375]);
        screenView(['user_id' => $reader->id, 'viewport' => 1_400]);

        rollDay();

        expect(UserDay::sole()->viewport_bucket)->toBe(ViewportBucket::Compact);
    });

    it('bounds the day with the first and last thing that happened', function () {
        $reader = User::factory()->create();

        screenView(['user_id' => $reader->id, 'occurred_at' => '2026-09-02 14:00:00']);
        screenView(['user_id' => $reader->id, 'occurred_at' => '2026-09-02 23:00:00']);

        rollDay();

        expect(UserDay::sole())
            ->first_seen_at->toDateTimeString()->toBe('2026-09-02 14:00:00')
            ->last_seen_at->toDateTimeString()->toBe('2026-09-02 23:00:00');
    });

    it('writes no row for a guest, who is counted and never followed', function () {
        ActivityEvent::factory()->guest()->create(['route' => 'home']);

        rollDay();

        expect((int) PageViewDaily::sum('views'))->toBe(1)
            ->and(UserDay::count())->toBe(0);
    });
});

describe('the truth tables', function () {
    it('gives a person who picked from a deep link a day of their own', function () {
        /*
         * The whole reason `user_days` is not built from the clickstream
         * alone. Somebody who tapped a pick straight out of a push
         * notification rendered no screen the sensor saw, and a presence
         * table that missed them would under-report exactly the people the
         * product works best for.
         */
        $player = User::factory()->create();

        Pick::factory()->create([
            'user_id' => $player->id,
            'created_at' => '2026-09-02 18:00:00',
            'updated_at' => '2026-09-02 18:00:00',
        ]);

        expect(ActivityEvent::count())->toBe(0)
            ->and(rollDay()['user_days'])->toBe(1);

        $day = UserDay::sole();

        expect($day->user_id)->toBe($player->id)
            ->and(ActivityFeature::Picked->in($day->features))->toBeTrue()
            ->and($day->views)->toBe(0)
            ->and($day->areas)->toBe(0)
            // The bounds come from the pick, because it is the only moment
            // there is — and the columns are not nullable.
            ->and($day->first_seen_at->toDateTimeString())->toBe('2026-09-02 18:00:00')
            // No view, so no width was ever reported.
            ->and($day->viewport_bucket)->toBe(ViewportBucket::Unknown);
    });

    it('reads a post, a join and a follow out of the tables that hold them', function () {
        $member = User::factory()->create();
        $group = Group::factory()->create();

        ConversationPost::factory()->create([
            'user_id' => $member->id, 'topic_type' => 'group', 'topic_id' => $group->id,
            'created_at' => '2026-09-02 19:00:00',
        ]);

        GroupMember::factory()->create([
            'user_id' => $member->id, 'group_id' => $group->id,
            'created_at' => '2026-09-02 19:30:00', 'updated_at' => '2026-09-02 19:30:00',
        ]);

        $member->followedTeams()->attach(Team::factory()->create()->id, ['position' => 1]);

        rollDay();

        $features = UserDay::sole()->features;

        // No emitter for any of these three, on purpose: a second row for a
        // fact `conversation_posts` already holds is a second counter that
        // can disagree with it.
        expect(ActivityFeature::Talked->in($features))->toBeTrue()
            ->and(ActivityFeature::Joined->in($features))->toBeTrue()
            ->and(ActivityFeature::Followed->in($features))->toBeTrue();
    });

    it('ignores a pick made on another league day', function () {
        // The window is the LEAGUE day, half-open. A pick at 01:00 UTC on the
        // 3rd is 21:00 on the 2nd, and it belongs to the Saturday somebody
        // was watching rather than to the calendar date in UTC.
        $player = User::factory()->create();

        Pick::factory()->create([
            'user_id' => $player->id,
            'created_at' => '2026-09-03 01:00:00',
            'updated_at' => '2026-09-03 01:00:00',
        ]);

        expect(rollDay()['user_days'])->toBe(1)
            ->and(rollDay('2026-09-03')['user_days'])->toBe(0);
    });
});

describe('re-running a day', function () {
    it('corrects a cell rather than doubling it', function () {
        screenView(['route' => 'home', 'viewport' => 390]);

        rollDay();
        rollDay();

        expect(PageViewDaily::count())->toBe(1)
            ->and(PageViewDaily::sole()->views)->toBe(1);

        // A late drain lands one more view of the same screen; the second
        // pass is a correction, which is what makes --day= a repair.
        screenView(['route' => 'home', 'viewport' => 390]);

        rollDay();

        expect(PageViewDaily::count())->toBe(1)
            ->and(PageViewDaily::sole()->views)->toBe(2);
    });

    it('corrects a presence row rather than doubling it', function () {
        $reader = User::factory()->create();

        screenView(['user_id' => $reader->id, 'route' => 'home']);

        rollDay();
        rollDay();

        expect(UserDay::count())->toBe(1)
            ->and(UserDay::sole()->views)->toBe(1);
    });
});

describe('today and since', function () {
    it('rolls the day in progress on the same code path', function () {
        // 12:00 UTC is 08:00 in the league's own morning, so today's partial
        // is a real day with hours left in it — which is why the dashboards
        // label it "so far".
        screenView(['route' => 'home', 'occurred_at' => '2026-09-02 11:00:00']);

        expect(app(ActivityRollup::class)->today()['page_views'])->toBe(1)
            ->and(PageViewDaily::sole()->day->toDateString())->toBe(ROLLUP_DAY);
    });

    it('reports no first day at all before anything is rolled', function () {
        // Null, not today: a window with no data is not a window that
        // measured zero.
        expect(app(ActivityRollup::class)->since())->toBeNull();
    });

    it('reports the first league day either table holds', function () {
        screenView(['occurred_at' => '2026-08-30 16:00:00']);
        screenView(['occurred_at' => '2026-09-01 16:00:00']);

        rollDay('2026-08-30');
        rollDay('2026-09-01');

        expect(app(ActivityRollup::class)->since())->toBe('2026-08-30');
    });
});

describe('the command', function () {
    it('drains before it rolls, so the day it states is complete', function () {
        // Anything still buffered when the rollup runs would simply be
        // missing from the day, and no later pass would notice: the day
        // would already have rows in it.
        $this->travelTo('2026-09-02 16:00:00');

        $this->get(route('scoreboard'))->assertOk();

        expect(ActivityEvent::count())->toBe(0);

        $this->artisan('cfb:activity-rollup --today')->assertSuccessful();

        expect(ActivityEvent::count())->toBe(1)
            ->and(PageViewDaily::sole()->route)->toBe('scoreboard');
    });

    it('rolls yesterday by default, which is the only day it can state whole', function () {
        screenView(['occurred_at' => '2026-09-01 16:00:00']);

        $this->artisan('cfb:activity-rollup')->assertSuccessful();

        expect(PageViewDaily::sole()->day->toDateString())->toBe('2026-09-01');
    });

    it('repairs one named day and writes a feed run for it', function () {
        screenView(['occurred_at' => '2026-08-28 16:00:00']);

        $this->artisan('cfb:activity-rollup --day=2026-08-28')->assertSuccessful();

        expect(PageViewDaily::sole()->day->toDateString())->toBe('2026-08-28')
            ->and(FeedRun::latestFor('activity:rollup'))->not->toBeNull()
            ->and(FeedRun::latestFor('activity:rollup')->status)->toBe(FeedRun::COMPLETE);
    });
});

describe('the analytics windows', function () {
    it('offers seven, twenty-eight and ninety days, and nothing else', function () {
        // Twenty-eight is four whole pick'em weeks; thirty holds 4.3
        // Saturdays and makes two adjacent months incomparable.
        expect(AnalyticsWindow::DAYS)->toBe([7, 28, 90])
            ->and(AnalyticsWindow::of(28)->from->toDateString())->toBe('2026-08-06')
            ->and(AnalyticsWindow::of(28)->to->toDateString())->toBe(ROLLUP_DAY)
            ->and(AnalyticsWindow::of(7)->label)->toBe('7d');
    });

    it('falls back to the default rather than honoring a made-up width', function () {
        // Filters come off a URL, and `?window=4000` must not render four
        // thousand days labeled as one.
        expect(AnalyticsWindow::from(['window' => 4_000])->days)->toBe(AnalyticsWindow::DEFAULT_DAYS)
            ->and(AnalyticsWindow::from([])->days)->toBe(AnalyticsWindow::DEFAULT_DAYS)
            ->and(AnalyticsWindow::from(['window' => 7])->days)->toBe(7);
    });

    it('refuses to call a window covered when the sensor is younger than it', function () {
        /*
         * The `funnel_since` rule one layer up. A 90-day page-view count on a
         * sensor that shipped three days ago is a three-day count wearing a
         * three-month label, and it reads as a collapse in traffic that never
         * happened.
         */
        screenView(['occurred_at' => '2026-09-01 16:00:00']);
        rollDay('2026-09-01');

        $window = AnalyticsWindow::of(90);

        expect($window->covered)->toBeFalse()
            ->and($window->sinceDate())->toBe('2026-09-01')
            ->and($window->coveredDays())->toBe(2);
    });

    it('calls a window covered once the data starts before it', function () {
        screenView(['occurred_at' => '2026-06-01 16:00:00']);
        rollDay('2026-06-01');

        $window = AnalyticsWindow::of(7);

        expect($window->covered)->toBeTrue()
            // Clamped to the window's own start: a window never reports days
            // outside itself.
            ->and($window->sinceDate())->toBe($window->fromDate())
            ->and($window->coveredDays())->toBe(7);
    });

    it('has no since at all before anything is rolled', function () {
        $window = AnalyticsWindow::of(28);

        expect($window->since)->toBeNull()
            ->and($window->covered)->toBeFalse()
            ->and($window->coveredDays())->toBe(0);
    });
});
