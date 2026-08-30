<?php

use App\Console\Concerns\TracksFeedRun;
use App\Filament\Pages\SyncHealth;
use App\Filament\Widgets\DataCoverage;
use App\Filament\Widgets\RecentSyncFailures;
use App\Filament\Widgets\ScheduledSyncTasks;
use App\Filament\Widgets\SyncSpend;
use App\Jobs\FetchGameSummary;
use App\Jobs\SyncTeamSeason;
use App\Models\Article;
use App\Models\FeedRun;
use App\Models\Game;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Models\User;
use App\Models\Week;
use App\Support\CoverageReport;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

describe('the feed-run ledger', function () {
    it('records a scheduled command run with records and requests', function () {
        // No games are live, so this spends zero requests — the row is the
        // point: the ledger separates "ran and found nothing" from "never ran",
        // which console output cannot.
        $this->artisan('cfb:summaries:live')->assertSuccessful();

        $run = FeedRun::latestFor('summaries:live');

        expect($run)->not->toBeNull()
            ->and($run->status)->toBe(FeedRun::COMPLETE)
            ->and($run->records)->toBe(0)
            ->and($run->requests)->toBe(0)
            ->and($run->finished_at)->not->toBeNull();
    });

    it('records a failure and rethrows, so exit codes still mean something', function () {
        $command = new class
        {
            use TracksFeedRun;

            public function run(): void
            {
                $this->trackRun('games:test', 2025, function (): int {
                    throw new RuntimeException('the feed fell over');
                });
            }
        };

        expect(fn () => $command->run())->toThrow(RuntimeException::class);

        $run = FeedRun::latestFor('games:test');

        expect($run->status)->toBe(FeedRun::FAILED)
            ->and($run->error)->toBe('the feed fell over')
            ->and($run->season_year)->toBe(2025);
    });

    it('prunes rows older than a fortnight', function () {
        FeedRun::begin('games:live', 2025);
        FeedRun::begin('games:live', 2025)->forceFill(['created_at' => now()->subDays(20)])->save();

        $this->artisan('model:prune', ['--model' => [FeedRun::class]])->assertSuccessful();

        expect(FeedRun::count())->toBe(1);
    });
});

describe('coverage checks', function () {
    it('flags the queued-but-never-drained team stats gap', function () {
        // The production failure: cfb:players --only=stats exits 0 having
        // queued jobs, and with no worker the table stays empty.
        Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

        $teams = Team::factory()->count(3)->create();

        foreach ($teams as $team) {
            TeamSeason::create([
                'team_id' => $team->id, 'season_year' => 2025,
                'classification' => 'FBS',
            ]);
        }

        $check = collect(app(CoverageReport::class)->checks())->firstWhere('key', 'team-stats');

        expect($check['status'])->toBe(CoverageReport::FAIL)
            ->and($check['remedy'])->toContain('cfb:players');

        // Stats landing flips it green.
        foreach ($teams as $team) {
            TeamSeasonStat::create([
                'team_id' => $team->id, 'season_year' => 2025, 'season_type' => 2,
                'category' => 'passing', 'stats' => [],
            ]);
        }

        $check = collect(app(CoverageReport::class)->checks())->firstWhere('key', 'team-stats');

        expect($check['status'])->toBe(CoverageReport::OK);
    });

    /*
     * Rankings freshness needs an in-season phase to assert anything: out of
     * season the check relaxes to OK by design, which would make every case
     * below pass without measuring a thing.
     */
    $inSeason = fn () => Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);

    $pollWritten = function (Season $season, string $at): Ranking {
        /*
         * The week is built on the PINNED season rather than left to
         * RankingFactory, which reaches Season::factory() through WeekFactory
         * and draws a year from a twelve-year range -- one roll in twelve
         * lands on the 2026 regular season this fixture already created and
         * dies on the (year, type) unique. It passed under --filter and failed
         * in the suite, which is the whole shape the factory rule describes.
         */
        $week = Week::factory()->create(['season_id' => $season->id]);

        $ranking = Ranking::factory()->create(['week_id' => $week->id]);

        // A poll row's own timestamps are what the check USED to read, so they
        // have to be placed deliberately rather than left at insert time.
        $ranking->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $ranking;
    };

    $rankingsSyncedAt = fn (string $at, int $records = 25) => FeedRun::create([
        'command' => 'sync:rankings-current', 'season_year' => 2026,
        'status' => FeedRun::COMPLETE, 'records' => $records,
        'started_at' => $at, 'finished_at' => $at,
    ]);

    $rankings = fn () => collect(app(CoverageReport::class)->checks())->firstWhere('key', 'rankings');

    it('counts a poll the sync reconciled today, not the day its rows were last written', function () use ($inSeason, $pollWritten, $rankingsSyncedAt, $rankings) {
        /*
         * The reported symptom. SyncRankings reconciles with updateOrCreate,
         * so an unchanged poll is not written at all and NEITHER row timestamp
         * moves -- the check read six days stale for data a completed sync had
         * confirmed the day before.
         */
        $season = $inSeason();

        $pollWritten($season, now()->subDays(6)->toDateTimeString());
        $rankingsSyncedAt(now()->subDay()->toDateTimeString());

        expect($rankings())->toMatchArray([
            'status' => CoverageReport::OK,
            'actual' => '1 days',
        ]);

        expect($rankings()['detail'])->toContain('unchanged since');
    });

    it('still warns and then fails when the sync itself has stopped', function () use ($inSeason, $pollWritten, $rankingsSyncedAt, $rankings) {
        // Widening the threshold to the Sun/Tue cadence must not cost the
        // check its teeth: one missed run is amber, two is red.
        $season = $inSeason();

        $pollWritten($season, now()->subDays(7)->toDateTimeString());
        $run = $rankingsSyncedAt(now()->subDays(7)->toDateTimeString());

        expect($rankings()['status'])->toBe(CoverageReport::WARN);

        $run->forceFill(['finished_at' => now()->subDays(11)])->save();
        Ranking::query()->update(['updated_at' => now()->subDays(11)]);

        expect($rankings()['status'])->toBe(CoverageReport::FAIL);
    });

    it('does not go amber across a weekend the schedule was never going to fill', function () use ($inSeason, $pollWritten, $rankingsSyncedAt, $rankings) {
        // Tuesday's run, read the following Sunday: five days, the widest gap
        // the Sun/Tue cadence can produce while working exactly as designed.
        $season = $inSeason();

        $pollWritten($season, now()->subDays(5)->toDateTimeString());
        $rankingsSyncedAt(now()->subDays(5)->toDateTimeString());

        expect($rankings()['status'])->toBe(CoverageReport::OK);
    });

    it('will not let a run row stand in for rankings that do not exist', function () use ($inSeason, $rankingsSyncedAt, $rankings) {
        // A run refines the age of data we hold; it cannot invent data we do
        // not. An empty table stays FAIL however recently the command ran.
        $inSeason();

        $rankingsSyncedAt(now()->toDateTimeString());

        expect($rankings())->toMatchArray([
            'status' => CoverageReport::FAIL,
            'actual' => 'never',
            'detail' => 'no ranking rows at all',
        ]);
    });

    it('does not treat a run that wrote nothing as evidence of a poll', function () use ($inSeason, $pollWritten, $rankingsSyncedAt, $rankings) {
        /*
         * The 403 story. The site host answers a disallowed User-Agent with a
         * 403, EspnClient returns null, and the sync completes having written
         * nothing -- so a run row alone would dress that silence up as
         * freshness. Only a run that reconciled a poll counts.
         */
        $season = $inSeason();

        $pollWritten($season, now()->subDays(11)->toDateTimeString());
        $rankingsSyncedAt(now()->toDateTimeString(), records: 0);

        expect($rankings()['status'])->toBe(CoverageReport::FAIL);

        // The same run, having actually reconciled a poll, is evidence.
        $rankingsSyncedAt(now()->toDateTimeString(), records: 25);

        expect($rankings()['status'])->toBe(CoverageReport::OK);
    });

    it('doctor exits non-zero while a check fails, and zero once healthy', function () {
        // An empty database has one genuine gap: no articles at all.
        $this->artisan('cfb:doctor')->assertFailed();

        Article::create([
            'espn_id' => 1, 'headline' => 'Kickoff nears', 'published_at' => now()->subHour(),
        ]);

        $this->artisan('cfb:doctor')->assertSuccessful();
    });
});

describe('the Sync Health page', function () {
    it('keeps non-admins out', function () {
        $this->actingAs(User::factory()->create())
            ->get('/admin/sync-health')
            ->assertForbidden();
    });

    it('renders for an admin', function () {
        Livewire::actingAs($this->admin)
            ->test(SyncHealth::class)
            ->assertOk();
    });

    /*
     * Each section is its own Filament widget — a separate Livewire component,
     * so its content is NOT in the page's own HTML and has to be tested where
     * it lives.
     *
     * It was originally built this way because the panel loads no Tailwind of
     * its own, so a hand-rolled Blade view rendered with no grid, flex or
     * spacing whatsoever. A custom theme is registered now (see
     * PanelThemeTest), so that constraint is lifted — but the widgets stay,
     * because native components carrying their own CSS is still the cheaper
     * way to build an admin table, and the testing rule is unchanged.
     */
    it('renders coverage through a Filament table widget', function () {
        Livewire::actingAs($this->admin)
            ->test(DataCoverage::class)
            ->assertOk()
            ->assertSee('Data coverage')
            ->assertSee('Box scores');
    });

    it('lists the schedule from the schedule itself, not a second registry', function () {
        Livewire::actingAs($this->admin)
            ->test(ScheduledSyncTasks::class)
            ->assertOk()
            ->assertSee('cfb:games --tier=live')
            ->assertSee('cfb:summaries:live');
    });

    it('shows the request spend and a coverage verdict', function () {
        Livewire::actingAs($this->admin)
            ->test(SyncSpend::class)
            ->assertOk()
            ->assertSee('ESPN requests · 24h')
            ->assertSee('budget 240/min');
    });

    it('lists a failed queue job beside the failed commands', function () {
        // The ledger used to cover scheduled COMMANDS only, and said so —
        // Cloud's managed queues keep `failed_jobs` to themselves, so a job
        // that died was invisible to every screen we own. AppServiceProvider's
        // Queue::failing hook writes the row now; the widget must show it, or
        // the sensor exists and nobody can see it.
        FeedRun::jobFailed(FetchGameSummary::class, 'ESPN returned 403 for game 401628515');

        Livewire::actingAs($this->admin)
            ->test(RecentSyncFailures::class)
            ->assertOk()
            ->assertSee('job:FetchGameSummary')
            ->assertSee('ESPN returned 403')
            // ...and still points at the dashboard that holds the payload.
            ->assertSee('Laravel Cloud');
    });

    it('queues an allowlisted task from the run action', function () {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SyncHealth::class)
            ->callAction('runTask', ['task' => 'cfb:sync --only=news'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        Queue::assertPushed(QueuedCommand::class);
    });

    it('refuses a task outside the allowlist', function () {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SyncHealth::class)
            ->callAction('refetchSummary', ['game' => 999999])
            ->assertHasActionErrors();

        Queue::assertNothingPushed();
    });

    it('queues a forced summary refetch on the live queue', function () {
        Queue::fake();

        $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
        $game = Game::factory()->finished()->create([
            'season_id' => $season->id, 'kickoff_at' => '2025-10-04 19:30:00',
        ]);

        Livewire::actingAs($this->admin)
            ->test(SyncHealth::class)
            ->callAction('refetchSummary', ['game' => (string) $game->id])
            ->assertHasNoActionErrors()
            ->assertNotified();

        Queue::assertPushedOn('live', FetchGameSummary::class);
    });

    it('queues a team resync', function () {
        Queue::fake();

        $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
        $team = Team::factory()->create();
        TeamSeason::create(['team_id' => $team->id, 'season_year' => 2025, 'classification' => 'FBS']);

        Livewire::actingAs($this->admin)
            ->test(SyncHealth::class)
            ->callAction('resyncTeam', ['team' => (string) $team->id])
            ->assertHasNoActionErrors()
            ->assertNotified();

        Queue::assertPushed(SyncTeamSeason::class);
    });
});
