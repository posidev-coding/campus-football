<?php

use App\Enums\ActivityArea;
use App\Enums\ActivityFeature;
use App\Enums\ActivityKind;
use App\Enums\ViewportBucket;
use App\Models\ActivityEvent;
use App\Models\PageViewDaily;
use App\Models\User;
use App\Models\UserDay;
use App\Support\Navigation;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 1 of docs/plans/analytics.md: the destination, before anything writes
 * to it. Nothing in the app records an activity event yet — the sensor, the
 * drain and the rollup are phases 2 and 3 — so what is guaranteed here is the
 * shape, the retention ceiling and the two enums whose wrong answer would be
 * a fabricated one.
 */

describe('privacy', function () {
    /*
     * The column list of every table is pinned, the way UxFunnelTest pins
     * `ux_events`. That test's reason was that the funnel goes to an
     * external advisor and must carry no identity; this one's is the
     * opposite and needs saying just as plainly: `activity_events` DOES
     * carry identity, and the pin is what makes adding a column to it a
     * decision somebody made rather than one that happened.
     *
     * A path, a query string, a user agent, an IP or a free-text label
     * arriving here would each be a new kind of fact about a person, and
     * each would slip in silently — the sensor would keep working, every
     * dashboard would keep reading, and nothing else in the suite would
     * care. This is the test that cares.
     */
    it('pins what a raw activity row is allowed to know about a person', function () {
        expect(Schema::getColumnListing('activity_events'))->toBe([
            'id',
            'stream_id',
            'kind',
            'user_id',
            'visitor',
            'audience',
            'route',
            'facet',
            'subject_type',
            'subject_id',
            'occurred_at',
            'day',
            'hour',
            'viewport',
            'standalone',
            'via_navigate',
            'release',
        ]);
    });

    it('pins the daily attention rollup, which keeps counts and no identity', function () {
        // This table lives forever, so it is the one with no user id at all —
        // `visitors` is a distinct count computed inside a cell and thrown
        // away.
        expect(Schema::getColumnListing('page_views_daily'))->toBe([
            'id',
            'day',
            'route',
            'facet',
            'audience',
            'viewport_bucket',
            'installed',
            'views',
            'visitors',
            'navigate_views',
            'created_at',
            'updated_at',
        ]);
    });

    it('pins the presence rollup, which keeps a person and no screens', function () {
        // Also forever, and it does carry a user id — but only counts and two
        // bitmasks. Which ROUTES somebody read is a thirty-day fact; that
        // they were here is a permanent one.
        expect(Schema::getColumnListing('user_days'))->toBe([
            'id',
            'user_id',
            'day',
            'views',
            'actions',
            'areas',
            'features',
            'first_seen_at',
            'last_seen_at',
            'viewport_bucket',
            'created_at',
            'updated_at',
        ]);
    });

    it('stores an unreported client hint as null and never as a zero', function () {
        /*
         * The app's own non-negotiable, at the schema level. The very first
         * HTML response of a session goes out before the client cookie
         * exists, so `viewport` and `standalone` are genuinely unknown for a
         * real share of rows. `standalone` DEFAULT false would turn "we were
         * not told" into "they were in a browser" — a claim, written by the
         * column definition, that no dashboard could ever tell apart from a
         * measured one.
         */
        $event = ActivityEvent::factory()->create([
            'viewport' => null,
            'standalone' => null,
            'release' => null,
        ]);

        $event->refresh();

        expect($event->viewport)->toBeNull()
            ->and($event->standalone)->toBeNull()
            ->and($event->release)->toBeNull();
    });

    it('gives users a last_seen_at with no value until something writes one', function () {
        // Nullable and never backfilled: before the sensor shipped there is
        // no answer, and now() would be a fabricated one.
        expect(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue()
            ->and(User::factory()->create()->last_seen_at)->toBeNull();
    });
});

describe('retention', function () {
    it('keeps thirty days of raw activity and not twenty-nine', function () {
        // The ceiling matters more than the floor: this is the only table in
        // the app pairing a person with the screens they read, and 30 is the
        // number ClientError uses, so an error can be read against the
        // traffic that produced it for as long as the error row lives.
        $old = ActivityEvent::factory()->create([
            'occurred_at' => now()->subDays(30)->subHour(),
        ]);
        $young = ActivityEvent::factory()->create([
            'occurred_at' => now()->subDays(29)->subHours(23),
        ]);

        $this->artisan('model:prune', ['--model' => [ActivityEvent::class]])->assertSuccessful();

        expect(ActivityEvent::find($old->id))->toBeNull()
            ->and(ActivityEvent::find($young->id))->not->toBeNull()
            ->and(ActivityEvent::KEEP_DAYS)->toBe(30);
    });

    it('names the clickstream on BOTH scheduled prune lines', function () {
        /*
         * Two entries — the in-season daily and the off-season weekly. A
         * prunable named on only one of them stops being pruned for half the
         * calendar, silently, and this is the table where that means an
         * identity-bearing row outliving its ceiling by months.
         */
        $prunes = collect(app(Schedule::class)->events())
            ->map(fn (Event $event) => $event->command ?? '')
            ->filter(fn (string $c) => str_contains($c, 'model:prune'))
            ->values();

        expect($prunes)->toHaveCount(2)
            ->and($prunes->every(fn (string $c) => str_contains($c, 'ActivityEvent')))
            ->toBeTrue('Both prune entries must name ActivityEvent.');
    });

    it('takes a deleted account\'s clickstream and presence with it', function () {
        // Cascade rather than nullOnDelete: a deleted account's rows are the
        // account, and orphaning them leaves rows that are neither a
        // person's nor a guest's.
        $user = User::factory()->create();
        ActivityEvent::factory()->create(['user_id' => $user->id]);
        UserDay::factory()->create(['user_id' => $user->id]);

        $user->delete();

        expect(ActivityEvent::where('user_id', $user->id)->count())->toBe(0)
            ->and(UserDay::where('user_id', $user->id)->count())->toBe(0);
    });

    it('leaves the rollups behind when the raw rows go', function () {
        // The whole point of the split: raw rows have a ceiling, counts do
        // not. PageViewDaily is not prunable at all.
        PageViewDaily::factory()->create(['day' => '2026-01-01']);

        $this->artisan('model:prune', ['--model' => [ActivityEvent::class]])->assertSuccessful();

        expect(PageViewDaily::count())->toBe(1);
    });
});

describe('the viewport buckets', function () {
    it('buckets a width at every boundary it claims', function () {
        expect(ViewportBucket::for(399))->toBe(ViewportBucket::Compact)
            ->and(ViewportBucket::for(400))->toBe(ViewportBucket::Phone)
            ->and(ViewportBucket::for(767))->toBe(ViewportBucket::Phone)
            ->and(ViewportBucket::for(768))->toBe(ViewportBucket::Tablet)
            ->and(ViewportBucket::for(1023))->toBe(ViewportBucket::Tablet)
            ->and(ViewportBucket::for(1024))->toBe(ViewportBucket::Desktop);
    });

    it('calls an unreported width unknown, and never the likeliest bucket', function () {
        /*
         * The first HTML response of a session is sent before the client
         * cookie exists, so a real share of views carry no width. Bucketing
         * those as Phone — the honest guess, since the product is read on a
         * phone — would fabricate the exact number the breakdown exists to
         * measure, and it would fabricate it in the direction that makes the
         * mobile-first case look already won.
         */
        expect(ViewportBucket::for(null))->toBe(ViewportBucket::Unknown)
            ->and(ViewportBucket::for(0))->toBe(ViewportBucket::Unknown)
            ->and(ViewportBucket::for(-1))->toBe(ViewportBucket::Unknown);
    });
});

describe('the area mapping', function () {
    it('reads the nav rather than keeping a second map', function () {
        expect(ActivityArea::forRoute('home'))->toBe(ActivityArea::Home)
            ->and(ActivityArea::forRoute('scoreboard'))->toBe(ActivityArea::Scores)
            ->and(ActivityArea::forRoute('pickem.home'))->toBe(ActivityArea::Picks)
            ->and(ActivityArea::forRoute('standings'))->toBe(ActivityArea::League)
            ->and(ActivityArea::forRoute('account'))->toBe(ActivityArea::Account);
    });

    it('follows a route the nav moves, because the nav is the source', function () {
        /*
         * `article` and `game` are detail screens whose area is a decision
         * Navigation makes — a story keeps Home lit, a game keeps Scores lit.
         * If a copy of that mapping lived in this enum it would keep
         * answering the old way after somebody moved the route, and the tab
         * bar and the breadth number would disagree with nothing failing.
         */
        expect(ActivityArea::forRoute('article'))->toBe(ActivityArea::Home)
            ->and(ActivityArea::forRoute('game'))->toBe(ActivityArea::Scores)
            ->and(ActivityArea::forRoute('team'))->toBe(ActivityArea::League);
    });

    it('gives the same answer with the pick\'em strip open and closed', function () {
        /*
         * The reason `forRoute()` reads only the area's unconditional
         * `routes` list. The Picks SECTIONS appear only inside the pick'em
         * config mirror or for an admin, and Account's only for a signed-in
         * reader — so a mapping that read them would classify a route one way
         * during Saturday's hourly rollup and another way during the 04:56
         * pass, and nothing would ever fail.
         *
         * (`pickem.talk` is listed only in the strip and therefore maps to
         * nothing here. That is the nav's own gap — the Talk screen lights no
         * tab today — and it is fixed in Navigation, not worked around here.)
         */
        config()->set('cfb.pickem_open', false);
        Navigation::flush();
        $closed = ActivityArea::forRoute('pickem.home');

        config()->set('cfb.pickem_open', true);
        Navigation::flush();

        expect($closed)->toBe(ActivityArea::Picks)
            ->and(ActivityArea::forRoute('pickem.home'))->toBe(ActivityArea::Picks);
    });

    it('maps an unknown route to NOTHING, never to Home', function () {
        /*
         * The wrong default this whole enum is guarded against. Home is case
         * one, so a `default =>` arm or a `?: self::Home` would send every
         * unclassified route — a legacy redirect, an auth screen, anything
         * added without a tab — into the single area every acquisition
         * question is read against. It would inflate exactly the number that
         * looks healthiest when inflated, and it would look like traffic.
         */
        expect(ActivityArea::forRoute('login'))->toBeNull()
            ->and(ActivityArea::forRoute('picks.groups'))->toBeNull()
            ->and(ActivityArea::forRoute('no-such-route'))->toBeNull()
            ->and(ActivityArea::forRoute(null))->toBeNull()
            ->and(ActivityArea::forRoute(''))->toBeNull();
    });

    it('covers every area the nav renders, so a sixth one fails loudly', function () {
        // A new nav area with no bit would map to null for all its routes —
        // honest, but invisible. This is the line that makes it visible.
        $keys = collect(Navigation::areas())->pluck('key');

        expect($keys->count())->toBe(count(ActivityArea::cases()))
            ->and($keys->every(fn (string $key) => ActivityArea::forRoute(
                collect(Navigation::areas())->firstWhere('key', $key)['route']
            ) !== null))->toBeTrue('Every nav area needs an ActivityArea bit.');
    });
});

describe('the bitmasks', function () {
    it('gives every case its own power of two', function () {
        // A duplicated or non-power-of-two value makes one bit answer for
        // two features, and the wrong answer is indistinguishable from a
        // right one.
        foreach ([ActivityArea::cases(), ActivityFeature::cases()] as $cases) {
            $values = array_map(fn ($case) => $case->value, $cases);

            expect($values)->toBe(array_unique($values));

            foreach ($values as $value) {
                expect($value & ($value - 1))->toBe(0);
            }
        }
    });

    it('reads a bit out of a stored mask', function () {
        $mask = ActivityFeature::Picked->value | ActivityFeature::ReadTalk->value;

        expect(ActivityFeature::Picked->in($mask))->toBeTrue()
            ->and(ActivityFeature::ReadTalk->in($mask))->toBeTrue()
            ->and(ActivityFeature::Talked->in($mask))->toBeFalse()
            ->and(ActivityArea::Picks->in(ActivityArea::Picks->value))->toBeTrue()
            ->and(ActivityArea::Home->in(ActivityArea::Picks->value))->toBeFalse();
    });

    it('fits the features it declares inside the column that holds them', function () {
        // unsignedSmallInteger holds sixteen bits. The seventeenth is a
        // column change, not a second mask.
        $all = array_reduce(
            ActivityFeature::cases(),
            fn (int $carry, ActivityFeature $case) => $carry | $case->value,
            0,
        );

        expect($all)->toBeLessThanOrEqual(65535);
    });
});

describe('the vocabulary', function () {
    it('is bounded, and everything with a truth table stays out of it', function () {
        /*
         * A pick, a post, a join, a follow and an invite each already have a
         * row somewhere durable. A second row here would be a second counter
         * that can disagree with the first — and a stream entry can be
         * trimmed under load where a truth row cannot.
         */
        expect(array_map(fn (ActivityKind $k) => $k->value, ActivityKind::cases()))->toBe([
            'page_view',
            'searched',
            'stat_asked',
            'help_asked',
            'notification_toggled',
            'shared',
        ]);
    });

    it('counts everything but a page view as an action', function () {
        expect(ActivityKind::PageView->isAction())->toBeFalse()
            ->and(ActivityKind::Searched->isAction())->toBeTrue()
            ->and(ActivityKind::Shared->isAction())->toBeTrue();
    });
});

describe('the factories', function () {
    it('derives the league day and hour from the kickoff the caller pinned', function () {
        /*
         * The FactoryFixturesTest rule, on a third factory. `day` and `hour`
         * computed in `definition()` would keep the DEFAULT instant's values
         * while `occurred_at` moved, and every rollup fixture would quietly
         * roll into a day it does not belong to — the shape that has already
         * cost this suite two runs.
         */
        $event = ActivityEvent::factory()->create(['occurred_at' => '2026-11-08 20:30:00']);

        expect($event->day->toDateString())->toBe('2026-11-08')
            ->and($event->hour)->toBe(15);
    });

    it('reads that day in the league timezone, not UTC', function () {
        // 01:00 UTC Sunday is Saturday night to everyone watching, and a
        // Saturday's traffic is the whole product's busiest hour.
        $event = ActivityEvent::factory()->create(['occurred_at' => '2026-11-08 01:00:00']);

        expect($event->day->toDateString())->toBe('2026-11-07')
            ->and($event->hour)->toBe(20)
            ->and($event->occurred_at->format('D'))->toBe('Sun');
    });

    it('leaves a day the caller pinned deliberately alone', function () {
        $event = ActivityEvent::factory()->create([
            'occurred_at' => '2026-11-08 20:30:00',
            'day' => '2026-01-01',
            'hour' => 3,
        ]);

        expect($event->day->toDateString())->toBe('2026-01-01')
            ->and($event->hour)->toBe(3);
    });

    it('makes a guest with a visitor hash and no user id', function () {
        // Exactly one of the two is non-null; a row with both is a row that
        // belongs to two populations.
        $event = ActivityEvent::factory()->guest()->create();

        expect($event->user_id)->toBeNull()
            ->and($event->visitor)->toMatch('/^[0-9a-f]{32}$/')
            ->and($event->audience)->toBe(ActivityEvent::GUEST);
    });

    it('builds rollup rows nothing has to derive', function () {
        $cell = PageViewDaily::factory()->create();
        $day = UserDay::factory()->create();

        expect($cell->facet)->toBe('')
            ->and($cell->viewport_bucket)->toBe(ViewportBucket::Phone)
            ->and($day->day->toDateString())->toBe('2026-09-02')
            ->and($day->viewport_bucket)->toBe(ViewportBucket::Phone);
    });
});

describe('the upsert keys', function () {
    it('refuses a second row for a cell the rollup already wrote', function () {
        /*
         * The re-run guarantee. `ActivityRollup::day()` upserts on these six
         * columns so recomputing a day corrects it rather than doubling it —
         * and `facet` is an empty string rather than null precisely because
         * MySQL treats nulls as distinct inside a unique key, which would let
         * every faceted cell be written twice.
         */
        PageViewDaily::factory()->create();

        expect(fn () => PageViewDaily::factory()->create())
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses a second presence row for one person on one day', function () {
        $user = User::factory()->create();
        UserDay::factory()->create(['user_id' => $user->id]);

        expect(fn () => UserDay::factory()->create(['user_id' => $user->id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses a second row for one stream entry, which is what makes the drain idempotent', function () {
        // The drain reads a batch, inserts, then XDELs. A crash between
        // those leaves the entries on the stream and the next drain re-reads
        // them; this constraint is the difference between "at least once"
        // and "exactly once".
        ActivityEvent::factory()->create(['stream_id' => '1757000000000-0']);

        expect(fn () => ActivityEvent::factory()->create(['stream_id' => '1757000000000-0']))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});
