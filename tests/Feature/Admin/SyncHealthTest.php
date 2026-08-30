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
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Models\User;
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
    /*
     * The team-stats row is measured against teams that have PLAYED, as far as
     * the weekly stats sync has been asked to reach — so its fixtures need a
     * season, FBS membership, completed games, and a run in the ledger. These
     * three helpers keep each case down to the one thing it is pinning.
     */
    $fbsTeam = function (int $year): Team {
        $team = Team::factory()->create();

        TeamSeason::create([
            'team_id' => $team->id, 'season_year' => $year,
            'classification' => 'FBS',
        ]);

        return $team;
    };

    $playedOn = function (Season $season, Team $home, Team $away, string $kickoff): Game {
        return Game::factory()->finished()->create([
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'kickoff_at' => $kickoff,
        ]);
    };

    $statsSyncedAt = function (string $at): FeedRun {
        return FeedRun::create([
            'command' => 'players:stats', 'season_year' => 2025,
            'status' => FeedRun::COMPLETE, 'records' => 138,
            'started_at' => $at, 'finished_at' => $at,
        ]);
    };

    $teamStats = fn () => collect(app(CoverageReport::class)->checks())->firstWhere('key', 'team-stats');

    it('flags the queued-but-never-drained team stats gap', function () use ($fbsTeam, $playedOn, $statsSyncedAt, $teamStats) {
        // The production failure: cfb:players --only=stats exits 0 having
        // queued jobs, and with no worker the table stays empty.
        $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

        $teams = collect(range(1, 4))->map(fn () => $fbsTeam(2025));

        $playedOn($season, $teams[0], $teams[1], '2025-09-06 19:00:00');
        $playedOn($season, $teams[2], $teams[3], '2025-09-06 19:00:00');

        // The sync ran AFTER those games, so it owns them.
        $statsSyncedAt('2025-09-09 10:40:00');

        $check = $teamStats();

        expect($check['status'])->toBe(CoverageReport::FAIL)
            ->and($check['expected'])->toBe(4)
            ->and($check['actual'])->toBe(0)
            ->and($check['remedy'])->toContain('cfb:players');

        // Stats landing flips it green.
        foreach ($teams as $team) {
            TeamSeasonStat::create([
                'team_id' => $team->id, 'season_year' => 2025, 'season_type' => 2,
                'category' => 'passing', 'stats' => [],
            ]);
        }

        expect($teamStats()['status'])->toBe(CoverageReport::OK);
    });

    it('stays green before anyone has played', function () use ($fbsTeam, $teamStats) {
        // Week 0. Against the whole FBS roll this row was red on day one of
        // every season, for a reason that had nothing to do with sync health.
        Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

        collect(range(1, 3))->each(fn () => $fbsTeam(2025));

        $check = $teamStats();

        expect($check['status'])->toBe(CoverageReport::OK)
            ->and($check['expected'])->toBe(0)
            ->and($check['actual'])->toBe(0);
    });

    it('does not ask for stats the weekly sync has not been run for yet', function () use ($fbsTeam, $playedOn, $statsSyncedAt, $teamStats) {
        // Saturday's games, read on Sunday. The stats pass is Tuesday-only, so
        // holding these teams to it is an assertion narrower than the cadence
        // of the thing it measures.
        $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

        $home = $fbsTeam(2025);
        $away = $fbsTeam(2025);

        $playedOn($season, $home, $away, '2025-09-13 19:00:00');
        $statsSyncedAt('2025-09-09 10:40:00');

        expect($teamStats())->toMatchArray([
            'status' => CoverageReport::OK,
            'expected' => 0,
        ]);

        // A game the sync HAS covered is held to it, on the same fixture.
        $playedOn($season, $home, $away, '2025-09-06 19:00:00');

        expect($teamStats())->toMatchArray([
            'status' => CoverageReport::FAIL,
            'expected' => 2,
            'actual' => 0,
        ]);
    });

    it('makes no allowance when the ledger holds no completed stats sync', function () use ($fbsTeam, $playedOn, $teamStats) {
        // No run row is not evidence of a window nothing ran in: with nothing
        // to widen against, every team that has played is expected to have
        // rows. A run still RUNNING is not a run that finished.
        $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

        $home = $fbsTeam(2025);
        $away = $fbsTeam(2025);

        $playedOn($season, $home, $away, '2025-09-13 19:00:00');
        FeedRun::begin('players:stats', 2025);

        expect($teamStats())->toMatchArray([
            'status' => CoverageReport::FAIL,
            'expected' => 2,
            'actual' => 0,
        ]);
    });

    it('will not let a team that never played cover for one that did', function () use ($fbsTeam, $playedOn, $statsSyncedAt, $teamStats) {
        /*
         * The way this check passes for the wrong reason. Counting stat rows
         * across the whole season instead of across the teams being asked
         * about makes one idle team's leftover row cancel out one played
         * team's missing one, and the totals still read 1 of 1.
         */
        $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

        $played = $fbsTeam(2025);
        $opponent = $fbsTeam(2025);
        $idle = $fbsTeam(2025);

        $playedOn($season, $played, $opponent, '2025-09-06 19:00:00');
        $statsSyncedAt('2025-09-09 10:40:00');

        foreach ([$played, $idle] as $team) {
            TeamSeasonStat::create([
                'team_id' => $team->id, 'season_year' => 2025, 'season_type' => 2,
                'category' => 'passing', 'stats' => [],
            ]);
        }

        // The opponent played and has nothing; the idle team's row is not its.
        expect($teamStats())->toMatchArray([
            'status' => CoverageReport::FAIL,
            'expected' => 2,
            'actual' => 1,
        ]);
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
