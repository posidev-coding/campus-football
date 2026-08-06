<?php

use App\Console\Concerns\TracksFeedRun;
use App\Filament\Pages\SyncHealth;
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
            ->assertOk()
            ->assertSee('Data coverage')
            ->assertSee('Scheduled tasks');
    });

    it('lists the schedule from the schedule itself, not a second registry', function () {
        Livewire::actingAs($this->admin)
            ->test(SyncHealth::class)
            ->assertSee('cfb:games --tier=live')
            ->assertSee('cfb:summaries:live');
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
