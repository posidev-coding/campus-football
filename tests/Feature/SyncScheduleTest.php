<?php

use App\Actions\RecordActivity;
use App\Models\FeedRun;
use App\Models\Game;
use App\Models\Season;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Services\Espn\Sync\SyncRecruiting;
use App\Support\ActivityRollup;
use App\Support\Cadence;
use App\Support\SyncSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
 * The schedule file loads during EVERY artisan command — including
 * package:discover on a deploy build whose database has no tables yet — so
 * nothing in it may touch the database at load time. The recruiting entries
 * once resolved `currentYear()` while the file loaded, and the deploy died
 * before migrations ran. They pass relative tokens now, and the command
 * resolves them at run time.
 */

it('schedules the kickoff sweep on the five-minute cadence the stamp assumes', function () {
    // The command's per-game stamp is sized to a 15-minute window swept
    // every 5 — a slower cadence starts missing games, a faster one just
    // burns wakes. The entry rides the live window the score tier keeps
    // awake, so it must never grow its own year-round schedule.
    $entry = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', 'cfb:kickoff-alerts'));

    expect($entry)->not->toBeNull()
        ->and($entry->expression)->toBe('*/5 * * * *');
});

it('caps every live-window mutex at minutes, not the day', function () {
    /*
     * The default overlap mutex expires in 24 HOURS, and it lives in Redis
     * where a worker OOM'd mid-run cannot release it — one bad
     * `--tier=live` minute would freeze scoreboard, grading and kickoff
     * alerts for the rest of the Saturday. Expiry is a small multiple of
     * cadence on everything; the default survives only on the teams sync,
     * the longest inline run in the schedule.
     */
    $expiries = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => $event->command !== null)
        ->mapWithKeys(fn (Event $event) => [$event->command => $event->expiresAt]);

    $expect = [
        'cfb:games --tier=live' => 5,
        'cfb:summaries:live' => 10,
        'cfb:kickoff-alerts' => 5,
        'pickem:remind' => 30,
        'pickem:settle' => 30,
        'pickem:publish-slates' => 30,
        'pickem:open-lobbies' => 30,
    ];

    foreach ($expect as $needle => $minutes) {
        $match = $expiries->first(fn ($expiry, string $command) => str_contains($command, $needle));

        expect($match)->toBe($minutes, "{$needle} should carry a {$minutes}-minute mutex expiry.");
    }

    // The one deliberate default: teams, ~165 seconds inline.
    $teams = $expiries->filter(fn ($expiry, string $command) => str_contains($command, '--only=teams'));
    expect($teams->unique()->values()->all())->toBe([1440]);

    // And nothing else still carries the 24-hour default.
    $defaulted = $expiries
        ->filter(fn ($expiry, string $command) => $expiry === 1440 && ! str_contains($command, '--only=teams'));
    expect($defaulted->keys()->all())->toBe([]);
});

it('keeps the two bulk mail sends on different days', function () {
    /*
     * The weekly digest and the pick'em results announcement are both BULK
     * mail spending the same `mail_daily_budget`. Sharing Sunday meant the
     * second one released its tail into Monday, and results that arrive
     * after the group has finished arguing are results nobody reads. The
     * digest moved to Tuesday — which is also the pick'em week's own
     * turnover, so it now opens the new week rather than trailing the old.
     */
    $newsletter = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', 'cfb:newsletter'));

    expect($newsletter)->not->toBeNull()
        ->and($newsletter->expression)->toBe('0 8 * * '.Cadence::TURNOVER_DOW)
        ->and($newsletter->expression)->not->toBe('0 8 * * 0');
});

it('sweeps pick reminders often enough for a ninety-minute last call', function () {
    // The last call has a 90-minute window; a cadence slower than that
    // starts missing cards entirely. The window opens at 08:00 because a
    // Friday-lunchtime wave one falls outside the live tier's 11:00-03:00.
    $entry = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', 'pickem:remind'));

    expect($entry)->not->toBeNull()
        ->and($entry->expression)->toBe('*/15 * * * *');
});

it('names the season on every games sync, never leaning on the config default', function () {
    /*
     * The current and recent tiers ran bare, `--year` fell through to
     * `config('cfb.season')` — 2025 in production — and for all of
     * September 2026 they synced 2025's final week every hour while this
     * season went unwritten. The command's own default is fixed too; this
     * keeps the schedule from ever depending on it.
     */
    $bare = collect(app(Schedule::class)->events())
        ->map(fn (Event $event) => $event->command ?? '')
        ->filter(fn (string $command) => str_contains($command, 'cfb:games') && ! str_contains($command, '--tier=live'))
        ->reject(fn (string $command) => str_contains($command, '--year='));

    expect($bare->values()->all())->toBe([]);
});

it('refreshes the upcoming card hourly through the build window', function () {
    /*
     * Tuesday and Wednesday are when commissioners build, and the build
     * door counts only lined games. The 04:00 `recent` pass alone left a
     * line posted mid-morning unseen until the next day — 2026-09-22 read
     * four lined games on a full Saturday. The hours are in the CRON, not
     * a between(), because the overdue check reads the cron alone.
     */
    $entry = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', 'cfb:games --tier=current')
            && $event->expression === '0 8-23 * * 2,3');

    expect($entry)->not->toBeNull()
        ->and($entry->timezone)->toBe(config('cfb.timezone'));

    $task = collect(app(SyncSchedule::class)->tasks())
        ->first(fn (array $task) => $task['name'] === 'cfb:games --tier=current --year=current' && str_contains($task['cadence'], 'Tue/Wed'));

    expect($task['cadence'] ?? null)->toBe('hourly Tue/Wed 08:00-23:00');
});

it('schedules recruiting by relative token, never a resolved year', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn (Event $event) => $event->command ?? '')
        ->filter(fn (string $command) => str_contains($command, '--only=recruiting'))
        ->values();

    expect($commands)->toHaveCount(2)
        ->and($commands->filter(fn (string $c) => str_contains($c, '--year=current')))->toHaveCount(1)
        ->and($commands->filter(fn (string $c) => str_contains($c, '--year=next')))->toHaveCount(1);
});

describe('the nightly aggregate is scoped to the season being played', function () {
    /*
     * A finished season's totals cannot change, so recomputing all six every
     * night is ~18 season/type rounds over 305,000 box-score lines — half an
     * hour of compute, nightly, to learn what one season did yesterday. It
     * also risks outrunning the sleep timeout on a scale-to-zero app cluster
     * and being cut off mid-pass.
     */
    it('passes the relative token rather than every season', function () {
        $aggregate = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command ?? '', 'cfb:aggregate'));

        // `results`, not `current`: this reads box scores, and in August the
        // season we are heading into has none.
        expect($aggregate)->not->toBeNull()
            ->and($aggregate->command)->toContain('--year=results');
    });

    it('resolves `current` to a season that HAS box scores', function () {
        /*
         * resultsYear(), not currentYear(). In August the season we are
         * heading into has no completed games, so aggregating it would spend
         * the whole pass writing nothing while the season that actually holds
         * numbers went stale — the same distinction that empties a dropdown
         * everywhere else in this app.
         */
        $played = Season::factory()->create([
            'year' => 2025, 'type' => Season::REGULAR,
            'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
        ]);

        $week = Week::create([
            'season_id' => $played->id, 'number' => 5, 'name' => 'Week 5',
            'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
        ]);

        Game::factory()->finished()->create([
            'season_id' => $played->id,
            'week_id' => $week->id,
        ]);

        // Scheduled but unplayed — the season a naive "current" would pick.
        Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => '2026-08-22', 'end_date' => '2026-12-13',
        ]);

        $this->artisan('cfb:aggregate', ['--year' => 'results'])
            ->expectsOutputToContain('zero ESPN requests')
            ->assertSuccessful();

        expect(app(CfbCalendar::class)->resultsYear())->toBe(2025);
    });

    it('still recomputes every season when no year is named', function () {
        // The backfill path, which is what a fresh seed needs.
        $this->artisan('cfb:aggregate')->assertSuccessful();
    });
});

describe('every season-bearing job names its year relatively', function () {
    /*
     * `config('cfb.season')` is a static env value somebody has to remember to
     * bump every August. It happens to be right today, which is the dangerous
     * kind of right: the year the calendar rolls over and nobody edits .env,
     * every sync silently starts refreshing a HISTORICAL season forever, with
     * no error to notice. CfbCalendar is the single source of truth for where
     * we are in the football year; the screens already obeyed that, the
     * scheduled commands did not.
     */
    it('never bakes a literal year into the schedule', function () {
        $literal = collect(app(Schedule::class)->events())
            ->map(fn (Event $event) => $event->command ?? '')
            ->filter(fn (string $c) => preg_match('/--year=\d{4}/', $c));

        expect($literal)->toBeEmpty('A schedule must not pin a literal season year.');
    });

    it('passes a token on every command that takes a year', function () {
        // The steps below all resolve a season. Anything year-bearing that
        // reaches the queue without a token is falling back to config.
        $needsYear = ['--only=teams', '--only=conferences', '--only=standings',
            '--only=leaders', '--only=injuries', '--tier=season', '--only=rosters'];

        $commands = collect(app(Schedule::class)->events())
            ->map(fn (Event $event) => $event->command ?? '');

        foreach ($needsYear as $step) {
            $matching = $commands->filter(fn (string $c) => str_contains($c, $step));

            expect($matching)->not->toBeEmpty("No schedule entry for {$step}.")
                ->and($matching->every(fn (string $c) => str_contains($c, '--year=')))
                ->toBeTrue("{$step} is scheduled without a --year token.");
        }
    });

    it('resolves each token through the calendar, not through config', function () {
        Season::factory()->create([
            'year' => 2025, 'type' => Season::REGULAR,
            'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
        ]);

        $week = Week::create([
            'season_id' => Season::where('year', 2025)->value('id'), 'number' => 5,
            'name' => 'Week 5', 'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
        ]);

        Game::factory()->finished()->create([
            'season_id' => Season::where('year', 2025)->value('id'), 'week_id' => $week->id,
        ]);

        Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => '2026-08-22', 'end_date' => '2026-12-13',
        ]);

        $calendar = app(CfbCalendar::class);

        // The distinction that matters in August: the season being played has
        // no games yet, so anything reading results must not ask for it.
        expect($calendar->resolveYear('current'))->toBe(2026)
            ->and($calendar->resolveYear('results'))->toBe(2025)
            ->and($calendar->resolveYear('next'))->toBe(2027)
            ->and($calendar->resolveYear('2021'))->toBe(2021)
            ->and($calendar->resolveYear(null))->toBe((int) config('cfb.season'));
    });
});

describe('the offseason runs sparingly', function () {
    /*
     * A scheduled task holds a scale-to-zero app cluster awake for the whole
     * sleep timeout, so an offseason job's cost is the WAKE, not the request.
     *
     * Asserted by TRAVELLING to June and asking each event whether its filters
     * pass, rather than by inspecting the schedule's internals — the property
     * that matters is what actually runs in the offseason, and the filters are
     * closures over `now()`.
     */
    $dueInJune = function () {
        return collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => $event->filtersPass(app()))
            ->values();
    };

    beforeEach(function () {
        // Mid-June, 2pm — INSIDE the live window's 11:00-23:59 filter, so the
        // season guard is the only thing that can hold these jobs back.
        $this->travelTo(CarbonImmutable::parse('2026-06-15 14:00', config('cfb.timezone')));
    });

    it('runs nothing every minute or every hour in June', function () use ($dueInJune) {
        // The news sync was the last hourly holdout — the single thing
        // standing between this app and months of genuine sleep.
        $frequent = $dueInJune()
            ->filter(fn (Event $event) => in_array($event->expression, ['* * * * *', '0 * * * *'], true))
            ->map(fn (Event $event) => $event->command ?? $event->description);

        expect($frequent)->toBeEmpty();
    });

    it('stops the game-day and results tiers entirely', function () use ($dueInJune) {
        $running = $dueInJune()->map(fn (Event $event) => $event->command ?? '');

        foreach (['--tier=live', 'cfb:summaries:live', '--only=standings', '--only=leaders', 'cfb:aggregate'] as $step) {
            expect($running->contains(fn (string $c) => str_contains($c, $step)))
                ->toBeFalse("{$step} should not run in the offseason.");
        }
    });

    it('keeps a monthly beat for reference data that still drifts', function () use ($dueInJune) {
        // Realignment is announced in the spring, the portal moves players,
        // and next season's schedule publishes — so these slow down rather
        // than stop.
        $running = $dueInJune()->map(fn (Event $event) => $event->command ?? '');

        foreach (['--only=teams', '--only=conferences', '--only=rosters', '--only=recruiting'] as $step) {
            expect($running->contains(fn (string $c) => str_contains($c, $step)))
                ->toBeTrue("{$step} should keep an offseason cadence.");
        }
    });

    it('runs the full in-season schedule again in October', function () use ($dueInJune) {
        /*
         * Asserted on entries guarded ONLY by season. The live tier also
         * carries `between('11:00', '23:59')`, and Laravel freezes that
         * interval's `now` when the schedule is DEFINED rather than when the
         * filters run — so it cannot respond to travel here. Harmless in
         * production: schedule:run redefines the schedule every minute.
         */
        $this->travelTo(CarbonImmutable::parse('2026-10-15 14:00', config('cfb.timezone')));

        $running = $dueInJune()->map(fn (Event $event) => $event->command ?? '');

        foreach (['--only=standings', 'cfb:aggregate', '--tier=recent', '--only=leaders'] as $step) {
            expect($running->contains(fn (string $c) => str_contains($c, $step)))
                ->toBeTrue("{$step} must run in season.");
        }
    });
});

describe('the live window covers West Coast night games', function () {
    /*
     * A 10:30pm Eastern kickoff on the West Coast is still being played at
     * 2am, so a window ending at 23:59 freezes the score of exactly the games
     * people are still awake for — and holds the final, the box score and
     * every pick'em result until the next morning's tier.
     *
     * Tested by building the window directly rather than through the app's
     * schedule: `between()` captures `now` when the event is DEFINED, and the
     * app's schedule was defined at boot, before any travel.
     */
    $window = fn () => app(Schedule::class)
        ->exec('cfb:games --tier=live')
        ->timezone(config('cfb.timezone'))
        ->between('11:00', '03:00');

    it('is still open at 1am, when a late game is mid-fourth-quarter', function () use ($window) {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 01:00', config('cfb.timezone')));

        expect($window()->filtersPass(app()))->toBeTrue();
    });

    it('is open through the afternoon slate', function () use ($window) {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 15:00', config('cfb.timezone')));

        expect($window()->filtersPass(app()))->toBeTrue();
    });

    it('closes overnight, once even the West Coast has finished', function () use ($window) {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 06:00', config('cfb.timezone')));

        expect($window()->filtersPass(app()))->toBeFalse();
    });
});

describe('the verification self-destruct is scheduled as a pair', function () {
    it('prunes users alongside the feed ledger in both halves of the year', function () {
        // Two entries — the in-season daily and the off-season weekly — and
        // both must name User, or unverified accounts quietly live forever in
        // one half of the calendar.
        $prunes = collect(app(Schedule::class)->events())
            ->map(fn (Event $event) => $event->command ?? '')
            ->filter(fn (string $c) => str_contains($c, 'model:prune'))
            ->values();

        expect($prunes)->toHaveCount(2)
            ->and($prunes->every(fn (string $c) => str_contains($c, 'FeedRun') && str_contains($c, 'User')))
            ->toBeTrue('Both prune entries must cover FeedRun and User.');
    });

    it('runs the reminder daily, year-round', function () {
        // Ungated like the newsletter: signups are year-round, so the
        // three-day warning that ARMS the prune has to be too.
        $reminder = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command ?? '', 'cfb:verification-reminders'));

        expect($reminder)->not->toBeNull()
            ->and($reminder->expression)->toBe('0 7 * * *')
            ->and($reminder->filtersPass(app()))->toBeTrue();
    });
});

it('schedules the live summary sweep inside the live window', function () {
    // The sweep rides the live tier's window; its own first query is the
    // guard that makes a quiet tick free.
    $sweep = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', 'cfb:summaries:live'));

    expect($sweep)->not->toBeNull()
        ->and($sweep->expression)->toBe('*/2 * * * *');
});

it('resolves current and next against the calendar at run time', function () {
    // The season we are heading into. `compute` is pure database arithmetic
    // — zero ESPN requests — so it can carry the year assertion safely.
    Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    $this->artisan('cfb:sync', ['--only' => 'compute', '--year' => 'current'])
        ->expectsOutputToContain('Syncing 2025')
        ->assertSuccessful();

    $this->artisan('cfb:sync', ['--only' => 'compute', '--year' => 'next'])
        ->expectsOutputToContain('Syncing 2026')
        ->assertSuccessful();

    // A literal year and the bare default still behave as they always did.
    $this->artisan('cfb:sync', ['--only' => 'compute', '--year' => '2024'])
        ->expectsOutputToContain('Syncing 2024')
        ->assertSuccessful();

    $this->artisan('cfb:sync', ['--only' => 'compute'])
        ->expectsOutputToContain('Syncing '.config('cfb.season'))
        ->assertSuccessful();
});

it('resolves a ledger key for every pick\'em sweep, not just the reminder', function () {
    /*
     * `tracked` is what the schedule panel reads a run back through: null
     * renders a permanently grey "untracked" row, and for three of these four
     * that WAS the truth — they called no trackRun. They each carry one now,
     * so a missing ledgerKey() arm would be the other half of the same bug,
     * a command writing rows nothing can find.
     *
     * Asserted through tasks() rather than the private ledgerKey(), because
     * the display name has to survive parsing before the key is ever looked
     * up — and it is the name that comes off the scheduler.
     */
    $tracked = collect(app(SyncSchedule::class)->tasks())
        ->filter(fn (array $task) => str_starts_with($task['name'], 'pickem:'))
        ->mapWithKeys(fn (array $task) => [$task['name'] => $task['tracked']]);

    expect($tracked->all())->toBe([
        'pickem:publish-slates' => 'publish-slates',
        'pickem:remind' => 'pick-reminders',
        'pickem:settle' => 'settle-slates',
        'pickem:open-lobbies' => 'open-lobbies',
    ]);
});

describe('the clickstream drain and rollup', function () {
    /*
     * `between()` captures `now` when the event is DEFINED, not when the
     * filters run, and the app's schedule was defined at boot — so
     * `filtersPass()` on a windowed entry cannot answer for a travelled
     * clock. The live-window block above hits the same wall and solves it the
     * same way: the window is rebuilt here and asserted directly, while the
     * season gate is read off the entry that carries no window.
     */
    $entries = fn (string $needle) => collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command ?? '', $needle))
        ->values();

    it('registers the drain as an in-season pair and an off-season one', function () use ($entries) {
        /*
         * Five minutes in season, and it adds NO wake of its own: 08:00-03:00
         * is the window the live tier, the kickoff alerts and the pick
         * reminders already hold the cluster up for. Six-hourly off season
         * rides the news sync's wake exactly, so June stays asleep — a drain
         * is not worth a wake of its own, and MAXLEN covers six hours many
         * times over.
         */
        $drains = $entries('cfb:activity-drain');

        expect($drains)->toHaveCount(2)
            ->and($drains->pluck('expression')->sort()->values()->all())
            ->toBe(['*/5 * * * *', '0 */6 * * *'])
            // A small multiple of the cadence, like everything else in the
            // live window: a worker OOM'd mid-run must not leave a mutex that
            // freezes the drain for the rest of the Saturday.
            ->and($drains->pluck('expiresAt')->sort()->values()->all())->toBe([5, 30]);
    });

    it('drains through the window the cluster is already awake for', function () {
        // Rebuilt rather than read off the schedule, for the reason above.
        $window = fn () => app(Schedule::class)
            ->exec('cfb:activity-drain')
            ->timezone(config('cfb.timezone'))
            ->between('08:00', '03:00');

        $this->travelTo(CarbonImmutable::parse('2026-10-17 15:00', config('cfb.timezone')));
        expect($window()->filtersPass(app()))->toBeTrue();

        // Still open at 1am, when a West Coast night game is being read.
        $this->travelTo(CarbonImmutable::parse('2026-10-18 01:00', config('cfb.timezone')));
        expect($window()->filtersPass(app()))->toBeTrue();

        // And shut at 5am, when nobody is.
        $this->travelTo(CarbonImmutable::parse('2026-10-18 05:00', config('cfb.timezone')));
        expect($window()->filtersPass(app()))->toBeFalse();
    });

    it('runs the off-season drain in June and not in October', function () use ($entries) {
        // The one drain entry with no window, so its season gate is the only
        // thing filtersPass is answering about.
        $off = $entries('cfb:activity-drain')->first(fn (Event $event) => $event->expression === '0 */6 * * *');

        $this->travelTo(CarbonImmutable::parse('2026-06-15 14:00', config('cfb.timezone')));
        expect($off->filtersPass(app()))->toBeTrue();

        $this->travelTo(CarbonImmutable::parse('2026-10-17 14:00', config('cfb.timezone')));
        expect($off->filtersPass(app()))->toBeFalse();
    });

    it('rolls yesterday at 04:56, ungated, one minute behind the funnel', function () use ($entries) {
        /*
         * Ungated by season for the reason `cfb:ux-rollup` is: people read
         * screens year-round, and a rollup that only ran in season would
         * leave exactly the quiet months unmeasured — the months where a drop
         * in attention is cheapest to notice. 04:56 rides the wake the prunes
         * at 04:50 and the funnel at 04:55 already pay for.
         */
        $daily = $entries('cfb:activity-rollup')->first(fn (Event $event) => $event->expression === '56 4 * * *');

        expect($daily)->not->toBeNull();

        foreach (['2026-06-15 14:00', '2026-10-17 14:00'] as $when) {
            $this->travelTo(CarbonImmutable::parse($when, config('cfb.timezone')));

            expect($daily->filtersPass(app()))->toBeTrue("The daily roll must be ungated in {$when}.");
        }
    });

    it('keeps today so far on the hour, as a separate entry', function () use ($entries) {
        // Two entries for one command: the finished day and the partial one,
        // the same shape `cfb:news:followed` carries for its two cadences.
        $rollups = $entries('cfb:activity-rollup');

        expect($rollups)->toHaveCount(2)
            ->and($rollups->pluck('expression')->sort()->values()->all())
            ->toBe(['0 * * * *', '56 4 * * *']);
    });

    it('resolves a ledger key for both commands, the --today entry included', function () {
        /*
         * A null key renders a permanently grey "untracked" row, which is the
         * one state this panel exists to distinguish from "ran and found
         * nothing" — and for the drain that distinction is the difference
         * between a dead pipeline and a quiet week. The `--today` entry
         * resolves to its OWN key, which the command writes (CFB-124): under
         * the daily pass's key the hourly row wore the 04:56 stamp all day,
         * and an hourly pass that had stopped was invisible.
         */
        $tracked = collect(app(SyncSchedule::class)->tasks())
            ->filter(fn (array $task) => str_starts_with($task['name'], 'cfb:activity-'))
            ->map(fn (array $task) => $task['name'].' => '.($task['tracked'] ?? 'untracked'))
            ->sort()
            ->values()
            ->all();

        expect($tracked)->toBe([
            'cfb:activity-drain => activity:drain',
            'cfb:activity-drain => activity:drain',
            'cfb:activity-rollup --today => activity:rollup:today',
            'cfb:activity-rollup => activity:rollup',
        ]);
    });
});

describe('the followed-team news sweep reports like a command, not a closure', function () {
    /*
     * Both cadences were `Schedule::call()` closures. A closure cannot carry
     * TracksFeedRun, cannot match SyncSchedule's `cfb:`/`pickem:` allowlist on
     * an artisan invocation, and had no ledgerKey() arm to match — so the two
     * rows read `last_status: null` forever, whether the sweep ran, failed, or
     * silently synced nothing. The general feed's own freshness hid it: the
     * coverage row is satisfied by the national feed and says nothing about
     * the per-team feeds every follow is a promise about.
     */
    $followed = fn () => collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command ?? '', 'cfb:news:followed'))
        ->values();

    it('registers both cadences as one real command', function () use ($followed) {
        // Two entries, not one — two cadences of a single command, the same
        // shape as `cfb:sync --only=news`.
        expect($followed())->toHaveCount(2)
            ->and($followed()->pluck('expression')->sort()->values()->all())
            ->toBe(['0 7 * * *', '0 7,19 * * *'])
            // The mutex expiry the daily-and-slower block carries, kept.
            ->and($followed()->pluck('expiresAt')->unique()->values()->all())->toBe([60]);
    });

    it('resolves a ledger key under both the in-season and off-season gates', function () {
        /*
         * Read through tasks() rather than the private ledgerKey(), because
         * the display name has to survive parsing off the scheduler before the
         * key is ever looked up — and a null key is what rendered the two
         * permanently grey "untracked" rows.
         *
         * Asserted in October and again in June: the in-season entry and the
         * off-season one are separate events, and only one of them is ungated
         * at a time.
         */
        foreach (['2026-10-15 14:00', '2026-06-15 14:00'] as $when) {
            $this->travelTo(CarbonImmutable::parse($when, config('cfb.timezone')));

            $tasks = collect(app(SyncSchedule::class)->tasks())
                ->filter(fn (array $task) => str_starts_with($task['name'], 'cfb:news:followed'));

            expect($tasks)->toHaveCount(2, "Both entries must be reported in {$when}.")
                ->and($tasks->pluck('tracked')->unique()->values()->all())
                ->toBe(['news:followed'], "The ledger key must resolve in {$when}.");

            // Exactly one of the pair is live at a time — the gates are what
            // stop the offseason entry reading as overdue in October.
            expect($tasks->reject(fn (array $task) => $task['gated']))->toHaveCount(1);
        }
    });

    it('does not let the newsletter arm answer for it', function () {
        // `cfb:newsletter` and `cfb:news:followed` share a prefix to the eye
        // but not to str_starts_with; the arm is ordered above it anyway, the
        // way `cfb:summaries:live` sits above `cfb:summaries`.
        $keys = collect(app(SyncSchedule::class)->tasks())
            ->filter(fn (array $task) => str_starts_with($task['name'], 'cfb:news'))
            ->mapWithKeys(fn (array $task) => [$task['name'] => $task['tracked']]);

        expect($keys->get('cfb:newsletter'))->toBe('newsletter')
            ->and($keys->get('cfb:news:followed'))->toBe('news:followed');
    });
});

describe('entries that share a command each report their own run', function () {
    /*
     * A ledger key belongs to a COMMAND, and the schedule runs some commands
     * from several entries. Reading `latestFor()` per entry handed every one
     * of them the same row: the Thursday predictors pass reported complete at
     * Saturday 08:00, and a Thursday pass that had stopped could never look
     * overdue (CFB-124). A row with no run of its own reads as none — never as
     * a sibling's stamp, and never as a default.
     */
    $tz = fn (): string => config('cfb.timezone');

    $ran = function (string $command, string $at, ?int $year = null) use ($tz): FeedRun {
        test()->travelTo(CarbonImmutable::parse($at, $tz()));

        $run = FeedRun::begin($command, $year);
        $run->complete(1, 1, 1000);

        return $run;
    };

    $rows = fn (string $name) => collect(app(SyncSchedule::class)->tasks())
        ->filter(fn (array $task) => $task['name'] === $name)
        ->values();

    it('gives each predictors pass its own run, not the Saturday one', function () use ($ran, $rows, $tz) {
        $wednesday = $ran('sync:predictors', '2026-11-11 06:00:04');
        $thursday = $ran('sync:predictors', '2026-11-12 06:00:06');
        $saturday = $ran('sync:predictors', '2026-11-14 08:00:09');

        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00', $tz()));

        $owned = $rows('cfb:sync --only=predictors')
            ->mapWithKeys(fn (array $task) => [$task['cadence'] => $task['run']?->id]);

        expect($owned->all())->toBe([
            'Wed 06:00' => $wednesday->id,
            'Thu 06:00' => $thursday->id,
            'Sat 08:00' => $saturday->id,
        ]);

        expect($rows('cfb:sync --only=predictors')->pluck('shared')->unique()->all())->toBe([true])
            ->and($rows('cfb:sync --only=predictors')->pluck('overdue')->unique()->all())->toBe([false]);
    });

    it('reads a pass with no run of its own as none, and overdue, never as a sibling', function () use ($ran, $rows, $tz) {
        // Only Saturday ran. Before CFB-124 all three rows claimed it, so the
        // Thursday pass read complete at a Saturday timestamp.
        $saturday = $ran('sync:predictors', '2026-11-14 08:00:09');

        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00', $tz()));

        $tasks = $rows('cfb:sync --only=predictors')->keyBy('cadence');

        expect($tasks['Sat 08:00']['run']?->id)->toBe($saturday->id)
            ->and($tasks['Sat 08:00']['overdue'])->toBeFalse()
            ->and($tasks['Thu 06:00']['run'])->toBeNull()
            ->and($tasks['Wed 06:00']['run'])->toBeNull()
            ->and($tasks['Thu 06:00']['overdue'])->toBeTrue()
            ->and($tasks['Wed 06:00']['overdue'])->toBeTrue();

        // No two rows claim one run as their own.
        $claimed = $rows('cfb:sync --only=predictors')->pluck('run')->filter()->map->id;

        expect($claimed->count())->toBe($claimed->unique()->count());
    });

    it('gives a run due for both cadences to the ungated one', function () use ($ran, $rows, $tz) {
        // 12:00 is due for the hourly in-season news sync AND the six-hourly
        // off-season one. In June only the six-hourly is on...
        $june = $ran('sync:news', '2026-06-16 12:00:03');

        $this->travelTo(CarbonImmutable::parse('2026-06-16 12:20', $tz()));

        $tasks = $rows('cfb:sync --only=news')->keyBy('cadence');

        expect($tasks['every 6 hours']['gated'])->toBeFalse()
            ->and($tasks['every 6 hours']['run']?->id)->toBe($june->id)
            ->and($tasks['hourly']['gated'])->toBeTrue()
            ->and($tasks['hourly']['run'])->toBeNull();

        // ...and in November the same tie goes the other way.
        $noon = $ran('sync:news', '2026-11-16 12:00:03');

        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:20', $tz()));

        $tasks = $rows('cfb:sync --only=news')->keyBy('cadence');

        expect($tasks['hourly']['run']?->id)->toBe($noon->id)
            ->and($tasks['every 6 hours']['run'])->toBeNull();
    });

    it('never reports a gated row overdue, run or no run', function () use ($rows, $tz) {
        // No news run at all. The ungated row is overdue — it has no evidence
        // of running — and the gated one is not judged, in either half.
        foreach (['2026-11-16 12:20' => 'every 6 hours', '2026-06-16 12:20' => 'hourly'] as $when => $gatedCadence) {
            $this->travelTo(CarbonImmutable::parse($when, $tz()));

            $tasks = $rows('cfb:sync --only=news')->keyBy('cadence');
            $ungatedCadence = $gatedCadence === 'hourly' ? 'every 6 hours' : 'hourly';

            expect($tasks[$gatedCadence]['gated'])->toBeTrue("{$gatedCadence} should be gated at {$when}.")
                ->and($tasks[$gatedCadence]['overdue'])->toBeFalse("A gated row was reported overdue at {$when}.")
                ->and($tasks[$ungatedCadence]['overdue'])->toBeTrue("The ungated row has no run at {$when}.");
        }

        // And across the whole schedule, no row is ever both.
        $both = collect(app(SyncSchedule::class)->tasks())
            ->filter(fn (array $task) => $task['gated'] && $task['overdue']);

        expect($both->pluck('name')->all())->toBe([]);
    });

    it('keeps a single-entry command on its latest run, unmarked', function () use ($ran, $rows, $tz) {
        // `cfb:coaches --current` has one entry; nothing to deal out.
        $run = $ran('coaches', '2026-11-10 07:00:02');

        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00', $tz()));

        $task = $rows('cfb:coaches --current')->sole();

        expect($task['run']?->id)->toBe($run->id)
            ->and($task['shared'])->toBeFalse();
    });
});

describe('a run that does different work gets its own ledger key', function () {
    it('keys the current and next recruiting classes apart, and the command writes both', function () {
        Season::factory()->create([
            'year' => 2025, 'type' => Season::REGULAR,
            'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
        ]);

        // No ESPN: the step is mocked, the ledger is what is under test.
        $this->mock(SyncRecruiting::class)->shouldReceive('handle')->twice()->andReturn(0);

        $this->artisan('cfb:sync', ['--only' => 'recruiting', '--year' => 'current'])->assertSuccessful();
        $this->artisan('cfb:sync', ['--only' => 'recruiting', '--year' => 'next'])->assertSuccessful();

        expect(FeedRun::latestFor('sync:recruiting')?->season_year)->toBe(2025)
            ->and(FeedRun::latestFor('sync:recruiting:next')?->season_year)->toBe(2026);

        $tasks = collect(app(SyncSchedule::class)->tasks())
            ->filter(fn (array $task) => str_starts_with($task['name'], 'cfb:sync --only=recruiting'))
            ->keyBy('name');

        expect($tasks['cfb:sync --only=recruiting --year=current']['tracked'])->toBe('sync:recruiting')
            ->and($tasks['cfb:sync --only=recruiting --year=next']['tracked'])->toBe('sync:recruiting:next')
            ->and($tasks['cfb:sync --only=recruiting --year=current']['run']?->season_year)->toBe(2025)
            ->and($tasks['cfb:sync --only=recruiting --year=next']['run']?->season_year)->toBe(2026)
            ->and($tasks->pluck('shared')->unique()->all())->toBe([false]);
    });

    it('writes the hourly rollup under its own key, and a repair under the daily', function () {
        $this->mock(RecordActivity::class)->shouldReceive('drain')->andReturn(0);
        $this->mock(ActivityRollup::class)->shouldReceive('day')->andReturn(['page_views' => 0, 'user_days' => 0]);

        $this->artisan('cfb:activity-rollup', ['--today' => true])->assertSuccessful();

        expect(FeedRun::latestFor('activity:rollup:today'))->not->toBeNull()
            ->and(FeedRun::latestFor('activity:rollup'))->toBeNull();

        $this->artisan('cfb:activity-rollup')->assertSuccessful();
        $this->artisan('cfb:activity-rollup', ['--day' => '2026-09-01', '--today' => true])->assertSuccessful();

        expect(FeedRun::where('command', 'activity:rollup')->count())->toBe(2)
            ->and(FeedRun::where('command', 'activity:rollup:today')->count())->toBe(1);

        $tasks = collect(app(SyncSchedule::class)->tasks())
            ->filter(fn (array $task) => str_starts_with($task['name'], 'cfb:activity-rollup'))
            ->keyBy('name');

        expect($tasks['cfb:activity-rollup --today']['run']?->command)->toBe('activity:rollup:today')
            ->and($tasks['cfb:activity-rollup']['run']?->command)->toBe('activity:rollup');
    });
});
