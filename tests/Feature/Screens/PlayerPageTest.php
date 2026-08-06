<?php

use App\Jobs\FetchAthleteGameLog;
use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteTeamSeason;
use App\Models\Game;
use App\Models\Position;
use App\Models\Season;
use App\Models\Team;
use App\Services\Espn\Sync\SyncAthleteStats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    config()->set('espn.http.rate_limit', 0);

    $this->team = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs']);
    Position::create(['id' => 8, 'name' => 'Quarterback', 'abbreviation' => 'QB']);

    $this->athlete = Athlete::create([
        'id' => 4685578,
        'display_name' => 'Gunner Stockton',
        'display_height' => "6' 1\"",
        'display_weight' => '215 lbs',
        'birth_city' => 'Tiger',
        'birth_state' => 'GA',
        'headshot_url' => 'https://a.espncdn.com/i/headshots/college-football/players/full/4685578.png',
    ]);

    AthleteTeamSeason::create([
        'athlete_id' => $this->athlete->id,
        'team_id' => 61,
        'season_year' => 2025,
        'jersey' => '14',
        'position_id' => 8,
        'experience_class' => 'Junior',
    ]);
});

it('renders a player page for guests', function () {
    $this->get(route('player', $this->athlete))->assertOk();
});

it('shows measurables and hometown, since ESPN publishes no bio prose', function () {
    Livewire::test('player', ['athlete' => $this->athlete])
        ->assertSee('Gunner Stockton')
        ->assertSee("6' 1")
        ->assertSee('215 lbs')
        ->assertSee('Tiger, GA')
        ->assertSee('Junior');
});

it('dispatches a refresh rather than fetching on the render path', function () {
    // The log is the one per-athlete feed. Fetching it inline put an upstream
    // round trip on the critical path of every player page view.
    Queue::fake();
    Http::fake();

    Livewire::test('player', ['athlete' => $this->athlete])->assertOk();

    Http::assertNothingSent();
    Queue::assertPushed(FetchAthleteGameLog::class, fn ($job) => $job->athleteId === $this->athlete->id);
});

it('renders the game log the job fetched', function () {
    $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    Game::factory()->finished()->create([
        'id' => 401769073,
        'season_id' => $season->id,
        'short_name' => 'OLE @ UGA',
    ]);

    Http::fake(['*gamelog*' => Http::response([
        'names' => ['completions', 'passingAttempts', 'passingYards', 'passingTouchdowns'],
        'labels' => ['CMP', 'ATT', 'YDS', 'TD'],
        'seasonTypes' => [[
            'displayName' => '2025 Regular Season',
            'categories' => [[
                'type' => 'passing',
                'events' => [[
                    'eventId' => '401769073',
                    'stats' => ['18', '31', '203', '1'],
                ]],
            ]],
        ]],
    ])]);

    // The queue is `sync` under test, so mounting runs the job inline — which
    // is exactly the end-to-end path: dispatch, fetch, render.
    Livewire::test('player', ['athlete' => $this->athlete])
        ->assertSee('OLE @ UGA')
        ->assertSee('203');

    $row = AthleteGameStat::where('athlete_id', $this->athlete->id)->sole();

    // Addressable by name, not by array position.
    expect($row->stats)->toEqualCanonicalizing([
        'completions' => '18',
        'passingAttempts' => '31',
        'passingYards' => '203',
        'passingTouchdowns' => '1',
    ]);

    // Order is asserted separately, because MySQL's JSON type reorders object
    // keys on write — so ordering has to live in a JSON array, not the map.
    expect($row->display_stats)
        ->toBe(['completions', 'passingAttempts', 'passingYards', 'passingTouchdowns']);
});

it('collapses a burst of viewers into a single upstream fetch', function () {
    /*
     * Asserted through the JOB, which is where the guarantee lives: it is
     * unique on the athlete, and it re-checks staleness before spending a
     * request. The service's lock only covers genuine concurrency.
     *
     * It used to be asserted by calling the service three times in a row,
     * which passed for the wrong reason — a 60-second cache marker that was
     * never released. That marker also swallowed a hand-asked refresh made
     * within a minute of the last one, and the page spun waiting for a job
     * that had quietly done nothing.
     */
    Http::fake(['*gamelog*' => Http::response(['names' => [], 'seasonTypes' => []])]);

    $stats = app(SyncAthleteStats::class);

    (new FetchAthleteGameLog($this->athlete->id))->handle($stats);
    (new FetchAthleteGameLog($this->athlete->id))->handle($stats);
    (new FetchAthleteGameLog($this->athlete->id))->handle($stats);

    Http::assertSentCount(1);
});

it('lets a forced refresh through straight after an unforced one', function () {
    /*
     * The regression that made the button appear to hang: the in-flight guard
     * was a 60-second marker with no release, so a click moments after the
     * page-load fetch made no request and wrote no stamp — leaving the page
     * with no "it came back" signal to wait for.
     */
    Http::fake(['*gamelog*' => Http::response(['names' => [], 'seasonTypes' => []])]);

    $stats = app(SyncAthleteStats::class);

    (new FetchAthleteGameLog($this->athlete->id))->handle($stats);
    (new FetchAthleteGameLog($this->athlete->id, force: true))->handle($stats);
    (new FetchAthleteGameLog($this->athlete->id, force: true))->handle($stats);

    // One page-load fetch plus both hand-asked ones.
    Http::assertSentCount(3);
});

it('skips log entries for games we have not ingested', function () {
    Http::fake(['*gamelog*' => Http::response([
        'names' => ['passingYards'],
        'seasonTypes' => [[
            'categories' => [[
                'type' => 'passing',
                'events' => [['eventId' => '999999999', 'stats' => ['203']]],
            ]],
        ]],
    ])]);

    app(SyncAthleteStats::class)->refreshGameLog($this->athlete->id);

    // A foreign key would otherwise abort the whole log.
    expect(AthleteGameStat::count())->toBe(0);
});

it('says it is fetching until the first answer lands, then says there is none', function () {
    /*
     * Two different empty states, and the difference is the timestamp rather
     * than the log. Keyed on emptiness, a player who genuinely has no stats
     * would sit under a spinner forever.
     */
    Queue::fake();

    Livewire::test('player', ['athlete' => $this->athlete])
        ->assertSee('Fetching')
        ->assertDontSee('No game log');

    $this->athlete->forceFill(['game_log_fetched_at' => now()])->save();

    Livewire::test('player', ['athlete' => $this->athlete->fresh()])
        ->assertSee('No game log')
        ->assertDontSee('Fetching');
});

describe('the on-demand refresh', function () {
    it('offers the button when nothing is outstanding', function () {
        Queue::fake();

        // Fetched an hour ago: not stale, so nothing was dispatched and the
        // reader gets the control immediately.
        $this->athlete->forceFill(['game_log_fetched_at' => now()->subHour()])->save();

        Livewire::test('player', ['athlete' => $this->athlete->fresh()])
            ->assertSee('Refresh')
            ->assertDontSee('Refreshing');
    });

    it('withholds it while the page-load job is still in flight', function () {
        /*
         * Offering "Refresh" while the job dispatched on page load has not
         * come back invites a second request for the answer already on its
         * way, and reads as though the first one failed.
         */
        Queue::fake();

        Livewire::test('player', ['athlete' => $this->athlete])
            ->assertSee('Refreshing')
            ->assertDontSee('>Refresh<', escape: false);
    });

    it('restores it once the job comes back', function () {
        Queue::fake();

        $screen = Livewire::test('player', ['athlete' => $this->athlete])
            ->assertSee('Refreshing');

        // What the worker does: stamps the athlete. The page re-resolves the
        // model from the database on every request, so the poll sees it.
        $this->athlete->forceFill(['game_log_fetched_at' => now()])->save();

        $screen->call('$refresh')
            ->assertSee('Refresh')
            ->assertDontSee('Refreshing');
    });

    it('gives the controls back if the job never lands', function () {
        /*
         * A failed fetch deliberately leaves the stamp alone, so "landed" can
         * never arrive for it. Without the ceiling the page would poll forever
         * under a spinner and the button would never return.
         */
        Queue::fake();

        $screen = Livewire::test('player', ['athlete' => $this->athlete])
            ->assertSee('Refreshing');

        $this->travel(31)->seconds();

        $screen->call('$refresh')->assertSee('Refresh');
    });

    it('forces past the staleness check, which a normal dispatch would not', function () {
        // The whole point of the button is a log that is NOT due a refresh.
        Queue::fake();

        $this->athlete->forceFill(['game_log_fetched_at' => now()])->save();

        Livewire::test('player', ['athlete' => $this->athlete->fresh()])
            ->call('refreshGameLog');

        Queue::assertPushed(
            FetchAthleteGameLog::class,
            fn ($job) => $job->athleteId === $this->athlete->id && $job->force === true
        );
    });

    it('actually refetches on a forced run, where an unforced one bails', function () {
        Http::fake(['*gamelog*' => Http::response(['names' => [], 'seasonTypes' => []])]);

        $this->athlete->forceFill(['game_log_fetched_at' => now()])->save();
        $stats = app(SyncAthleteStats::class);

        (new FetchAthleteGameLog($this->athlete->id))->handle($stats);
        Http::assertNothingSent();

        // A forced job still has to get past the in-flight lock, which is keyed
        // per athlete and untouched here.
        (new FetchAthleteGameLog($this->athlete->id, force: true))->handle($stats);
        Http::assertSentCount(1);
    });
});

describe('the refresh policy', function () {
    it('polls once a day off gameday, and every fifteen minutes on Saturday', function () {
        /*
         * Saturday is when the numbers actually move. Fifteen minutes is a
         * per-ATHLETE ceiling — four requests an hour for someone being
         * watched, none at all for the rest of the roster.
         */
        $stats = app(SyncAthleteStats::class);

        $this->travelTo(CarbonImmutable::parse('2025-11-05 12:00', config('cfb.timezone'))); // Wednesday
        expect($stats->window())->toBe(SyncAthleteStats::FRESH_FOR)->toBe(86400);

        $this->travelTo(CarbonImmutable::parse('2025-11-08 12:00', config('cfb.timezone'))); // Saturday
        expect($stats->window())->toBe(SyncAthleteStats::FRESH_FOR_GAMEDAY)->toBe(900);
    });

    it('decides Saturday in the app timezone, not UTC', function () {
        /*
         * A UTC Saturday opens at 8pm Friday Eastern. Read in UTC, Friday
         * night's games would get the gameday cadence and Saturday night's the
         * 24-hour one — exactly inverted, and only ever visible in the evening.
         */
        $stats = app(SyncAthleteStats::class);

        // 01:00 Saturday UTC is still 21:00 Friday in New York.
        $this->travelTo(CarbonImmutable::parse('2025-11-08 01:00', 'UTC'));

        expect(now()->isSaturday())->toBeTrue()
            ->and(now(config('cfb.timezone'))->isSaturday())->toBeFalse()
            ->and($stats->window())->toBe(SyncAthleteStats::FRESH_FOR);
    });

    it('treats a never-fetched log as stale, and a just-fetched one as fresh', function () {
        $stats = app(SyncAthleteStats::class);

        expect($stats->isStale($this->athlete))->toBeTrue();

        $this->athlete->forceFill(['game_log_fetched_at' => now()])->save();
        expect($stats->isStale($this->athlete->fresh()))->toBeFalse();

        $this->athlete->forceFill(['game_log_fetched_at' => now()->subDay()->subMinute()])->save();
        expect($stats->isStale($this->athlete->fresh()))->toBeTrue();
    });

    it('does not re-dispatch for a log fetched moments ago', function () {
        Queue::fake();

        $this->athlete->forceFill(['game_log_fetched_at' => now()])->save();

        Livewire::test('player', ['athlete' => $this->athlete->fresh()])->assertOk();

        Queue::assertNothingPushed();
    });

    it('stamps an EMPTY answer, so a statless player stops being re-queued', function () {
        // Most athletes never record a stat. Without this they would dispatch a
        // job on every view for the rest of time.
        Http::fake(['*gamelog*' => Http::response(['names' => [], 'seasonTypes' => []])]);

        (new FetchAthleteGameLog($this->athlete->id))->handle(app(SyncAthleteStats::class));

        expect($this->athlete->fresh()->game_log_fetched_at)->not->toBeNull()
            ->and(AthleteGameStat::count())->toBe(0);
    });

    it('does NOT stamp a failed request', function () {
        /*
         * Same rule as the article story sync: a transient 500 must not
         * permanently demote a player to "no stats". Leaving the timestamp null
         * is what makes the next view try again.
         */
        Http::fake(['*gamelog*' => Http::response('', 500)]);

        (new FetchAthleteGameLog($this->athlete->id))->handle(app(SyncAthleteStats::class));

        expect($this->athlete->fresh()->game_log_fetched_at)->toBeNull();
    });

    it('is unique on the athlete, so a trending player costs one request', function () {
        // A player who blows up after a big game is viewed by everyone at once.
        expect((new FetchAthleteGameLog(4685578))->uniqueId())->toBe('4685578')
            ->and(new FetchAthleteGameLog(1))->toBeInstanceOf(ShouldBeUnique::class);
    });
});
