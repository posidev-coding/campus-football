<?php

use App\Jobs\FetchGameSummary;
use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\Conference;
use App\Models\Game;
use App\Models\GameDrive;
use App\Models\GamePredictor;
use App\Models\GameScoringPlay;
use App\Models\GameSummary;
use App\Models\GameTeamStat;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Models\Venue;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGameSummary;
use App\Support\TeamPalette;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    $this->georgia = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs', 'abbreviation' => 'UGA']);
    $this->alabama = Team::factory()->create(['id' => 333, 'display_name' => 'Alabama Crimson Tide', 'abbreviation' => 'ALA']);

    // Without these the FBS scope resolves to an empty team list and correctly
    // filters every game out — membership is season-scoped, so it has to exist.
    foreach ([61, 333] as $teamId) {
        TeamSeason::create([
            'team_id' => $teamId,
            'season_year' => 2025,
            'classification' => 'FBS',
        ]);
    }

    // kickoff PINNED: the factory otherwise scatters it across four months
    // from `now`, which is a random date in a fixture every test in this file
    // renders — and which drifts into every date-window query in the app.
    $this->game = Game::factory()->finished(31, 17)->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
        'kickoff_at' => '2025-09-27 19:30:00',
    ]);

    GameSummary::create([
        'game_id' => $this->game->id,
        'is_final' => true,
        'synced_at' => now(),
        'attendance' => 92746,
    ]);
});

it('renders a completed game for guests', function () {
    $this->get(route('game', $this->game))->assertOk();
});

it('costs no ESPN request for a final game', function () {
    // A final game's summary can never change, so it is fetched once ever and
    // every later visit is a pure database read. This is what makes an archive
    // of 5,000 game pages free to browse — nothing fetched, nothing queued.
    Http::fake();
    Queue::fake();

    Livewire::test('game', ['game' => $this->game])->assertOk();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('renders the team box score in ESPN order, not MySQL JSON order', function () {
    // MySQL does not preserve JSON object key order, so ordering lives in a
    // JSON *array* alongside the map. Storing them scrambled here proves the
    // display order comes from display_stats and not from the map.
    GameTeamStat::create([
        'game_id' => $this->game->id,
        'team_id' => 61,
        'stats' => ['totalYards' => '461', 'firstDowns' => '21'],
        'display_stats' => [
            ['name' => 'firstDowns', 'label' => '1st Downs'],
            ['name' => 'totalYards', 'label' => 'Total Yards'],
        ],
    ]);

    $html = Livewire::test('game', ['game' => $this->game])->set('tab', 'box')->html();

    expect(strpos($html, '1st Downs'))->toBeLessThan(strpos($html, 'Total Yards'));
});

it('renders player lines keyed by name rather than array position', function () {
    $athlete = Athlete::create(['id' => 4690158, 'display_name' => 'Noah Kim', 'slug' => 'noah-kim']);

    AthleteGameStat::create([
        'athlete_id' => $athlete->id,
        'game_id' => $this->game->id,
        'team_id' => 61,
        'category' => 'passing',
        // Deliberately out of display order — v3 indexed stats[0]/stats[1] and
        // broke whenever ESPN reordered.
        'stats' => ['passingYards' => '330', 'completions/passingAttempts' => '25/42'],
        'display_stats' => [
            ['name' => 'completions/passingAttempts', 'label' => 'C/ATT'],
            ['name' => 'passingYards', 'label' => 'YDS'],
        ],
    ]);

    Livewire::test('game', ['game' => $this->game])
        ->set('tab', 'box')
        ->assertSee('Noah Kim')
        ->assertSee('25/42')
        ->assertSee('330');
});

it('orders scoring plays by sequence, not by clock', function () {
    // A football clock counts DOWN, so ascending clock within a quarter
    // reverses it. These two are stored with the later play having the LARGER
    // clock value to make that failure mode visible.
    GameScoringPlay::create([
        'game_id' => $this->game->id, 'team_id' => 61, 'sequence' => 1,
        'period' => 1, 'clock' => '2:11', 'abbreviation' => 'TD',
        'text' => 'First score of the game', 'home_score' => 7, 'away_score' => 0,
    ]);

    GameScoringPlay::create([
        'game_id' => $this->game->id, 'team_id' => 333, 'sequence' => 2,
        'period' => 2, 'clock' => '14:55', 'abbreviation' => 'TD',
        'text' => 'Second score of the game', 'home_score' => 7, 'away_score' => 7,
    ]);

    $html = Livewire::test('game', ['game' => $this->game])->set('tab', 'scoring')->html();

    expect(strpos($html, 'First score'))->toBeLessThan(strpos($html, 'Second score'));
});

it('shows attendance from the summary', function () {
    Livewire::test('game', ['game' => $this->game])->assertSee('92,746');
});

it('opens an upcoming game on the Preview tab, with no box tabs to fall into', function () {
    // "Not played yet" used to be the whole pregame page. The preview IS the
    // pregame page now; the apology only survives for a fixture with nothing
    // at all to say.
    $upcoming = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'pre',
    ]);

    Livewire::test('game', ['game' => $upcoming])
        ->assertOk()
        ->assertSet('tab', 'preview')
        ->assertDontSee('Box Score');
});

describe('the pregame screen is one scroll', function () {
    beforeEach(function () {
        $this->upcoming = Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'completed' => false, 'status' => 'pre',
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);

        GamePredictor::create([
            'game_id' => $this->upcoming->id,
            'home_projection' => 61.5,
            'away_projection' => 38.5,
            'matchup_quality' => 72.4,
        ]);

        $this->upcoming->odds()->create([
            'phase' => 'current', 'provider' => 'DraftKings', 'provider_id' => 100,
            'details' => 'UGA -7.5', 'spread' => -7.5, 'over_under' => 48.5,
            'captured_at' => now(),
        ]);
    });

    it('offers a single tab, so no strip is drawn at all', function () {
        // A two-item strip whose second item is one table is a control charging
        // for something the page can simply show. One tab renders none.
        $component = Livewire::test('game', ['game' => $this->upcoming]);

        expect($component->instance()->tabs())->toBe(['preview' => 'Preview']);

        $component->assertDontSee('aria-label="Game sections"', escape: false);
    });

    it('folds the odds in rather than hiding them behind a tab', function () {
        $html = Livewire::test('game', ['game' => $this->upcoming])->html();

        // The line LEADS the scroll — it is the one number a reader checks
        // before kickoff whether or not they bet — and it sits inside the
        // preview, above the game-information card at the foot.
        $gameInfo = strpos($html, $this->upcoming->kickoff_at
            ->setTimezone(config('cfb.timezone'))
            ->format('g:i A, F j, Y'));

        expect(strpos($html, 'DraftKings'))->toBeLessThan(strpos($html, 'Matchup predictor'))
            ->and(strpos($html, 'Matchup predictor'))->toBeLessThan($gameInfo);
    });

    it('prints matchup quality once, not beside itself', function () {
        // The odds partial carries a quality table when it IS a tab. Folded
        // into the preview the donut two cards below already says it, so the
        // table is suppressed — otherwise one scroll states it twice.
        $html = Livewire::test('game', ['game' => $this->upcoming])->html();

        expect(substr_count($html, 'Matchup quality'))->toBe(1);
    });

    it('renders exactly one game-information card', function () {
        // It is the parent's, at the foot of every state. The preview must not
        // grow its own or a pregame reader gets the venue twice.
        $html = Livewire::test('game', ['game' => $this->upcoming])->html();

        expect(substr_count($html, 'Aer Lingus'))->toBeLessThanOrEqual(1)
            ->and(substr_count($html, $this->upcoming->kickoff_at->setTimezone(config('cfb.timezone'))->format('g:i A, F j, Y')))->toBe(1);
    });

    it('lands a bookmarked ?tab=odds back on the preview', function () {
        // The tab no longer exists here, and a URL carried from a game that
        // has since kicked off must not open an empty pane.
        Livewire::withUrlParams(['tab' => 'odds'])
            ->test('game', ['game' => $this->upcoming])
            ->assertSet('tab', 'preview');
    });

    it('keeps the odds tab once a game is under way', function () {
        // The consolidation is pregame only: after kickoff the scroll belongs
        // to the box score, and odds go back behind their own tab.
        expect(Livewire::test('game', ['game' => $this->game])->instance()->tabs())
            ->toHaveKey('odds');
    });
});

it('never fetches ESPN synchronously, even for a live game', function () {
    /*
     * The page used to fetch the 544 KB summary INLINE in the Livewire
     * request — the slow path on the one screen people refresh most. It
     * queues a refresh now (the athlete game-log pattern) and renders
     * whatever is stored.
     */
    Http::fake();
    Queue::fake();

    $live = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'in',
        // Pinned: an unpinned kickoff drifts into other tests' date-window
        // queries and shifts the faker sequence beneath them.
        'kickoff_at' => '2025-09-27 19:30:00',
    ]);

    Livewire::test('game', ['game' => $live])->assertOk();

    Http::assertNothingSent();
    // On the live queue, so it is picked up in seconds even while a backfill
    // batch drains.
    Queue::assertPushedOn('live', FetchGameSummary::class);
    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->force === false);
});

it('queues one refresh for a live game, not one per viewer', function () {
    /*
     * The invariant that keeps this screen cheap: the job is unique on the
     * game, so a second viewer mounting while the first's job is still
     * queued adds NOTHING. (Queue::fake honors ShouldBeUnique locks.)
     */
    Queue::fake();

    $live = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'in',
        // Pinned: an unpinned kickoff drifts into other tests' date-window
        // queries and shifts the faker sequence beneath them.
        'kickoff_at' => '2025-09-27 19:30:00',
    ]);

    foreach (range(1, 3) as $viewer) {
        Livewire::test('game', ['game' => $live->fresh()])->assertOk();
    }

    Queue::assertPushed(FetchGameSummary::class, 1);
});

it('queues nothing for a fresh live summary', function () {
    // The staleness window is the per-game throttle now: a summary synced
    // seconds ago means the next viewer dispatches nothing at all.
    Queue::fake();

    $live = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'in',
        // Pinned: an unpinned kickoff drifts into other tests' date-window
        // queries and shifts the faker sequence beneath them.
        'kickoff_at' => '2025-09-27 19:30:00',
    ]);

    GameSummary::create([
        'game_id' => $live->id,
        'is_final' => false,
        'synced_at' => now(),
    ]);

    Livewire::test('game', ['game' => $live])->assertOk();

    Queue::assertNothingPushed();
});

it('queues nothing pregame', function () {
    // The summary payload has no box score before kickoff; the old inline
    // refresh burned one 544 KB request a minute on every upcoming game
    // somebody left open.
    Queue::fake();

    $upcoming = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'pre',
    ]);

    Livewire::test('game', ['game' => $upcoming])->assertOk();

    Queue::assertNothingPushed();
});

it('queues a refresh for a completed game whose final fetch was swallowed', function () {
    // A completed game wearing a mid-game summary means the just-final fetch
    // died (crashed worker, cancelled batch). Waiting for staleness would be
    // fine; waiting forever would not — isStale(Game) treats it as due.
    Queue::fake();

    GameSummary::where('game_id', $this->game->id)->update(['is_final' => false, 'synced_at' => now()]);

    Livewire::test('game', ['game' => $this->game->fresh()])->assertOk();

    Queue::assertPushedOn('live', FetchGameSummary::class);
});

it('links a game card to its game page', function () {
    Livewire::test('scoreboard')
        ->set('scope', 'fbs')
        ->set('week', $this->week->id)
        ->assertSee(route('game', $this->game), escape: false);
});

it('survives a negative running score from ESPN', function () {
    /*
     * Verified live: game 401767129 carries a scoring play with
     * `homeScore: -14`. A running score cannot be negative, the column is
     * unsigned, and writing it raw threw — which aborted a 954-game backfill at
     * game 260 over one corrupt row.
     *
     * Null rather than clamped to zero: we do not know what the score was, and
     * inventing 0 renders a confidently wrong scoreline.
     */
    Http::fake(['*' => Http::response([
        'boxscore' => ['teams' => [], 'players' => []],
        'scoringPlays' => [[
            'text' => 'Ernest Campbell 22 Yd pass from Cardell Williams',
            'homeScore' => -14,
            'awayScore' => 0,
            'period' => ['number' => 1],
            'clock' => ['displayValue' => '0:05'],
            'type' => ['text' => 'Passing Touchdown'],
            'scoringType' => ['abbreviation' => 'TD'],
            'team' => ['id' => 61],
        ]],
    ])]);

    $sync = app(SyncGameSummary::class);

    expect(fn () => $sync->handle($this->game))->not->toThrow(Throwable::class);

    $play = GameScoringPlay::where('game_id', $this->game->id)->first();

    expect($play)->not->toBeNull()
        ->and($play->home_score)->toBeNull()
        ->and($play->away_score)->toBe(0)
        // The play itself is still worth having.
        ->and($play->text)->toContain('Ernest Campbell');
});

describe('the three states', function () {
    it('opens a final game on Recap and a live one on Live', function () {
        Livewire::test('game', ['game' => $this->game])
            ->assertSet('tab', 'recap');

        Queue::fake();

        $live = Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'completed' => false, 'status' => 'in',
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);

        Livewire::test('game', ['game' => $live])->assertSet('tab', 'live');
    });

    it('resolves a tab carried across a state change to the new lead', function () {
        // ?tab=live bookmarked mid-game, opened after the whistle.
        Livewire::withQueryParams(['tab' => 'live'])
            ->test('game', ['game' => $this->game])
            ->assertSet('tab', 'recap');
    });
});

describe('drives', function () {
    beforeEach(function () {
        GameDrive::create([
            'game_id' => $this->game->id,
            'drives' => [[
                'id' => 'd1',
                'team' => ['id' => 61],
                'description' => '8 plays, 75 yards, 3:29',
                'result' => 'TD',
                'displayResult' => 'Touchdown',
                'isScore' => true,
                'start' => ['text' => 'UGA 25'],
                'end' => ['text' => 'ALA end zone'],
                'plays' => [[
                    'id' => 'p1',
                    'text' => 'Deep shot to the post for the score.',
                    'scoringPlay' => true,
                    'clock' => ['displayValue' => '3:05'],
                    'period' => ['number' => 1],
                ]],
            ]],
        ]);
    });

    it('never reads the drive payload while another tab is showing', function () {
        /*
         * game_drives is ~306 KB a row and lives in its own table precisely so
         * a page view does not read it. The recap and box tabs must not undo
         * the split with a curious query.
         */
        $reads = 0;

        DB::listen(function ($query) use (&$reads) {
            if (str_contains($query->sql, 'game_drives') && str_contains($query->sql, 'select `drives`')) {
                $reads++;
            }
        });

        Livewire::test('game', ['game' => $this->game]); // recap tab

        expect($reads)->toBe(0);
    });

    it('renders the drive chart, expandable plays and all, on its own tab', function () {
        Livewire::test('game', ['game' => $this->game])
            ->set('tab', 'drives')
            ->assertSee('Touchdown')
            ->assertSee('8 plays, 75 yards, 3:29')
            ->assertSee('Deep shot to the post for the score.');
    });
});

describe('the preview', function () {
    it('shows the matchup predictor donut from stored projections', function () {
        Queue::fake();

        $upcoming = Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'completed' => false, 'status' => 'pre',
            'kickoff_at' => now()->addDays(3),
        ]);

        GamePredictor::create([
            'game_id' => $upcoming->id,
            'matchup_quality' => 63.6,
            'home_projection' => 51.5,
            'away_projection' => 48.5,
            'home_pred_pt_diff' => 0.5,
            'away_pred_pt_diff' => -0.5,
        ]);

        Livewire::test('game', ['game' => $upcoming])
            ->assertSee('Matchup predictor')
            ->assertSee('51.5%')
            ->assertSee('48.5%')
            ->assertSee('UGA by 0.5');
    });

    it('lists last meetings from both home-away orders, capped at five', function () {
        Queue::fake();

        $upcoming = Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'completed' => false, 'status' => 'pre',
            'kickoff_at' => now()->addDays(3),
        ]);

        // Seven meetings, hosts alternating. With the beforeEach final also a
        // UGA-ALA meeting that makes eight candidates — only five may show,
        // so the three oldest fall off.
        $meetings = collect(range(1, 7))->map(fn (int $i) => Game::factory()->finished(20 + $i, 10)->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => $i % 2 === 0 ? 61 : 333,
            'away_team_id' => $i % 2 === 0 ? 333 : 61,
            'kickoff_at' => "2025-09-0{$i} 19:30:00",
        ]));

        $shown = Livewire::test('game', ['game' => $upcoming])->instance()->lastMeetings;

        expect($shown)->toHaveCount(5)
            ->and($shown->pluck('id'))->not->toContain($meetings[0]->id)
            ->and($shown->pluck('id'))->not->toContain($meetings[1]->id)
            ->and($shown->pluck('id'))->not->toContain($meetings[2]->id)
            // Both home/away orders admitted: Sep 6 (UGA hosting) and
            // Sep 7 (ALA hosting) are both in.
            ->and($shown->pluck('id'))->toContain($meetings[5]->id)
            ->and($shown->pluck('id'))->toContain($meetings[6]->id);
    });
});

describe('around the league', function () {
    it('orders the sheet followed, ranked, conference, rest — each game claimed once', function () {
        $user = User::factory()->create();
        $user->followedTeams()->attach(333, ['position' => 1]);

        // Same ET day as the game.
        $others = collect([
            'followed' => [333, null],   // the followed team also plays... claimed by YOUR TEAMS
            'ranked' => [null, null],    // carries a curated rank
            'conference' => [61, null],  // shares Georgia's conference
            'rest' => [null, null],
        ])->map(function ($teams, $kind) {
            $home = Team::factory()->create();
            $away = Team::factory()->create();

            TeamSeason::create(['team_id' => $home->id, 'season_year' => 2025, 'classification' => 'FBS', 'conference_id' => null]);

            return Game::factory()->create([
                'season_id' => $this->season->id, 'week_id' => $this->week->id,
                'home_team_id' => $teams[0] ?? $home->id,
                'away_team_id' => $teams[1] ?? $away->id,
                'completed' => false, 'status' => 'pre',
                'kickoff_at' => $this->game->kickoff_at,
                'home_rank' => $kind === 'ranked' ? 4 : null,
            ]);
        });

        // Georgia and the "conference" game's opponent share a conference.
        $sec = Conference::factory()->create(['id' => 8, 'name' => 'SEC']);
        TeamSeason::query()->whereIn('team_id', [61, $others['conference']->home_team_id])
            ->update(['conference_id' => 8]);

        $component = Livewire::actingAs($user)
            ->test('game', ['game' => $this->game])
            ->set('sheetOpen', true);

        $slate = $component->instance()->leagueSlate;

        $labels = collect($slate)->pluck('label')->all();
        $byLabel = collect($slate)->keyBy('label');

        expect($labels)->toBe(['Your teams', 'Top 25', 'Conference', 'Around the league'])
            ->and(collect($byLabel['Your teams']['games'])->pluck('id'))->toContain($others['followed']->id)
            ->and(collect($byLabel['Top 25']['games'])->pluck('id'))->toContain($others['ranked']->id)
            ->and(collect($byLabel['Conference']['games'])->pluck('id'))->toContain($others['conference']->id)
            ->and(collect($byLabel['Around the league']['games'])->pluck('id'))->toContain($others['rest']->id)
            // The page's own game never lists, and nothing appears twice.
            ->and(collect($slate)->pluck('games')->flatten(1)->pluck('id'))
            ->not->toContain($this->game->id)
            ->and(collect($slate)->pluck('games')->flatten(1)->pluck('id')->duplicates())->toBeEmpty();
    });

    it('costs nothing while closed', function () {
        $component = Livewire::test('game', ['game' => $this->game]);

        expect($component->instance()->leagueSlate)->toBe([]);
    });
});

describe('chart colors', function () {
    it('separates two same-colored teams and keeps both visible on the page', function () {
        $georgia = Team::factory()->make(['color' => 'ba0c2f', 'alt_color' => '000000']);
        $alabama = Team::factory()->make(['color' => '9e1b32', 'alt_color' => '828a8f']);

        [$away, $home] = TeamPalette::chartColors($georgia, $alabama);

        expect(TeamPalette::contrast($away, $home))->toBeGreaterThanOrEqual(1.25)
            ->and(TeamPalette::contrast($away, '#ffffff'))->toBeGreaterThanOrEqual(2.0)
            ->and(TeamPalette::contrast($home, '#ffffff'))->toBeGreaterThanOrEqual(2.0);
    });

    it('darkens a near-white brand until it reads on the page', function () {
        $pale = Team::factory()->make(['color' => 'f8f8f8', 'alt_color' => null]);
        $navy = Team::factory()->make(['color' => '001a57', 'alt_color' => null]);

        [$away, $home] = TeamPalette::chartColors($pale, $navy);

        expect(TeamPalette::contrast($away, '#ffffff'))->toBeGreaterThanOrEqual(2.0);
    });
});

describe('the scorebug nav row', function () {
    it('offers Back and Gameday instead of a week caption', function () {
        Livewire::test('game', ['game' => $this->game])
            ->assertSee('Back')
            ->assertSee('Gameday')
            // The week moved to the venue line; it did not vanish.
            ->assertSee('Week 5');
    });

    it('keeps the bowl name, which is the game\'s identity', function () {
        // games.note is the ONLY way to tell a playoff game from any other
        // bowl — a heuristic on `name` matches nothing — so it cannot lose its
        // place to the nav row.
        $this->game->update(['note' => 'College Football Playoff National Championship']);

        Livewire::test('game', ['game' => $this->game->fresh()])
            ->assertSee('College Football Playoff National Championship');
    });
});

describe('the hand-asked refresh', function () {
    beforeEach(function () {
        Queue::fake();

        $this->live = Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'completed' => false, 'status' => 'in',
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);
    });

    it('stays hidden for the first half of the sync cycle', function () {
        // Freshly synced: the stored copy is newer than the 60s window, so a
        // forced fetch would spend 544 KB to learn nothing.
        GameSummary::create([
            'game_id' => $this->live->id, 'is_final' => false, 'synced_at' => now()->subSeconds(10),
        ]);

        // Both states ship in the markup and Alpine picks between them, so
        // absence is asserted on the value that drives the choice rather than
        // on the text — the countdown is what the reader sees.
        Livewire::test('game', ['game' => $this->live])
            ->assertSet('canRefresh', false)
            ->assertSet('refreshAvailableIn', 20);
    });

    it('appears halfway in, when pressing it would genuinely expedite', function () {
        GameSummary::create([
            'game_id' => $this->live->id, 'is_final' => false, 'synced_at' => now()->subSeconds(35),
        ]);

        Livewire::test('game', ['game' => $this->live])
            ->assertSet('canRefresh', true)
            // Nothing left to count down; the ring gives way to the button.
            ->assertSet('refreshAvailableIn', 0)
            ->assertSee('Refresh');
    });

    it('forces past the staleness check, because it is offered before staleness', function () {
        GameSummary::create([
            'game_id' => $this->live->id, 'is_final' => false, 'synced_at' => now()->subSeconds(35),
        ]);

        Livewire::test('game', ['game' => $this->live])->call('forceRefresh');

        // Unforced, the job would re-check staleness and no-op at 35 seconds.
        Queue::assertPushedOn('live', FetchGameSummary::class);
        Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->force === true);
    });

    it('refuses to dispatch when called inside the window anyway', function () {
        // The method is publicly reachable, so the throttle cannot live only
        // in the Blade condition that hides the button.
        GameSummary::create([
            'game_id' => $this->live->id, 'is_final' => false, 'synced_at' => now(),
        ]);

        Livewire::test('game', ['game' => $this->live])->call('forceRefresh');

        Queue::assertNotPushed(FetchGameSummary::class);
    });

    it('is never offered on a final game, whose summary cannot change', function () {
        Livewire::test('game', ['game' => $this->game])
            ->assertSet('canRefresh', false);
    });

    it('is never offered before kickoff, when there is nothing to fetch', function () {
        $upcoming = Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'completed' => false, 'status' => 'pre',
            'kickoff_at' => now()->addDays(3),
        ]);

        Livewire::test('game', ['game' => $upcoming])
            ->assertSet('canRefresh', false);
    });
});

describe('the game information card', function () {
    it('carries when, where and how to watch, without a redundant heading', function () {
        $venue = Venue::create([
            'id' => 3504, 'name' => 'Aviva Stadium', 'city' => 'Dublin',
            'image_url' => 'https://a.espncdn.com/i/venues/college-football/day/3504.jpg',
            'image_checked_at' => now(),
        ]);

        $this->game->update(['venue_id' => $venue->id, 'broadcasts' => ['ESPN']]);

        Livewire::test('game', ['game' => $this->game->fresh()])
            ->assertSee('Aviva Stadium')
            ->assertSee('Dublin')
            ->assertSee('Where to Watch')
            ->assertSee('ESPN')
            ->assertSee('day/3504.jpg')
            // The card names itself by its contents; a "Game Information"
            // heading would be the widest thing in it.
            ->assertDontSee('Game Information');
    });

    it('renders without a venue photo, which two in five venues lack', function () {
        $venue = Venue::create([
            'id' => 9999, 'name' => 'Somewhere Field', 'city' => 'Nowhere', 'state' => 'KS',
            'image_checked_at' => now(),
        ]);

        $this->game->update(['venue_id' => $venue->id]);

        Livewire::test('game', ['game' => $this->game->fresh()])
            ->assertOk()
            ->assertSee('Somewhere Field')
            ->assertSee('Nowhere, KS');
    });
});

describe('last five', function () {
    it('lists a team\'s recent games newest first, with the right side of each score', function () {
        Queue::fake();

        $upcoming = Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'completed' => false, 'status' => 'pre', 'kickoff_at' => now()->addDays(3),
        ]);

        // Georgia wins away 28-10, then loses at home 14-21.
        Game::factory()->finished(10, 28)->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 333, 'away_team_id' => 61,
            'kickoff_at' => '2025-09-06 19:30:00',
        ]);
        Game::factory()->finished(14, 21)->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'kickoff_at' => '2025-09-13 19:30:00',
        ]);

        $html = Livewire::test('game', ['game' => $upcoming])->html();

        // Newest first: the Sep 13 loss precedes the Sep 6 win in the table.
        expect(strpos($html, '9/13/25'))->toBeLessThan(strpos($html, '9/6/25'))
            // Georgia's own score leads each result, whichever side it played.
            ->and($html)->toContain('14-21')
            ->and($html)->toContain('28-10');
    });
});
