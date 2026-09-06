<?php

use App\Events\GameWentFinal;
use App\Jobs\FetchGameSummary;
use App\Models\Article;
use App\Models\Game;
use App\Models\GameScoringPlay;
use App\Models\GameSummary;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGameSummary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * The no-stacking guarantee, layer by layer. ShouldBeUnique collapses
 * simultaneous dispatches; these tests cover the other two layers — the
 * in-handle staleness re-check (a stale queued copy becomes a no-op) and the
 * released in-flight lock (two workers genuinely executing at once cannot
 * stack fetches for one game).
 */

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    Team::factory()->create(['id' => 61, 'display_name' => 'Georgia']);
    Team::factory()->create(['id' => 333, 'display_name' => 'Alabama']);

    // kickoff PINNED: GameFactory defaults it to a random date in a
    // four-month window, and an unpinned fixture both drifts into other
    // tests' date-window queries and shifts the faker sequence under every
    // test that runs after this file.
    $this->live = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'in',
        'kickoff_at' => '2025-09-27 19:30:00',
    ]);

    // Registered per test, never here: sequential Http::fake calls STACK and
    // the first '*' registered keeps winning, so a beforeEach catch-all would
    // silently override any test that fakes its own payload.
    $this->fakeEmptySummary = fn () => Http::fake([
        '*' => Http::response(['boxscore' => ['teams' => [], 'players' => []]]),
    ]);
});

it('skips the fetch when the summary is already fresh', function () {
    // Many viewers and the sweep can queue this game before the first copy
    // runs; uniqueness cannot dedupe a dispatch made after an earlier copy
    // finished. The in-handle re-check makes the late copy free.
    ($this->fakeEmptySummary)();

    GameSummary::create(['game_id' => $this->live->id, 'is_final' => false, 'synced_at' => now()]);

    (new FetchGameSummary($this->live->id))->handle(app(SyncGameSummary::class));

    Http::assertNothingSent();
});

it('fetches past a fresh summary when forced', function () {
    // The just-final fetch and the backfill carry force: what they fetch is
    // the FINAL truth, and a live fetch seconds earlier must not veto it.
    ($this->fakeEmptySummary)();

    GameSummary::create(['game_id' => $this->live->id, 'is_final' => false, 'synced_at' => now()]);

    (new FetchGameSummary($this->live->id, force: true))->handle(app(SyncGameSummary::class));

    Http::assertSentCount(1);
});

it('treats a completed game with a non-final summary as stale', function () {
    // The swallowed-final race: the just-final job died and the stored
    // summary still says mid-game. A fresh synced_at must not hide that.
    ($this->fakeEmptySummary)();
    $this->live->update(['completed' => true, 'status' => 'post']);
    GameSummary::create(['game_id' => $this->live->id, 'is_final' => false, 'synced_at' => now()]);

    (new FetchGameSummary($this->live->id))->handle(app(SyncGameSummary::class));

    Http::assertSentCount(1);
});

it('treats a live game with a final summary as stale', function () {
    // The mirror case, and the one that bites hardest: ESPN briefly reports a
    // game complete and then flips it back. is_final's short-circuit is
    // permanent, so trusting it here freezes the box score for the rest of
    // the game — the game and its summary disagreeing is always stale.
    ($this->fakeEmptySummary)();
    GameSummary::create(['game_id' => $this->live->id, 'is_final' => true, 'synced_at' => now()]);

    (new FetchGameSummary($this->live->id))->handle(app(SyncGameSummary::class));

    Http::assertSentCount(1);
});

it('yields to a fetch already in flight for the same game', function () {
    // Layer three: the one race uniqueness cannot see is a backfill batch job
    // executing beside a live job (batched jobs skip unique locks). The
    // per-game lock makes the second a no-op instead of a stacked write.
    ($this->fakeEmptySummary)();
    $lock = Cache::lock("espn:summary:{$this->live->id}", 60);
    expect($lock->get())->toBeTrue();

    try {
        (new FetchGameSummary($this->live->id, force: true))->handle(app(SyncGameSummary::class));

        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

describe('the scoring-plays dirty guard', function () {
    // A SEQUENCE, faked once: sequential Http::fake calls stack and the first
    // '*' registered keeps winning, so faking a second payload mid-test would
    // silently replay the first — which is exactly how the original version
    // of this test passed while asserting nothing.
    $fakeSequence = function (string ...$texts) {
        Http::fake(['*' => Http::sequence(
            array_map(fn (string $text) => Http::response([
                'boxscore' => ['teams' => [], 'players' => []],
                'scoringPlays' => [[
                    'text' => $text,
                    'homeScore' => 7,
                    'awayScore' => 0,
                    'period' => ['number' => 1],
                    'clock' => ['displayValue' => '2:11'],
                    'type' => ['text' => 'Passing Touchdown'],
                    'scoringType' => ['abbreviation' => 'TD'],
                    'team' => ['id' => 61],
                ]],
            ]), $texts)
        )]);
    };

    it('does not rewrite scoring rows for an unchanged payload', function () use ($fakeSequence) {
        /*
         * storeScoringPlays replaces rows wholesale, and under the two-minute
         * sweep an unchanged summary would delete and recreate every scoring
         * row all Saturday against a scale-to-zero database. The stored
         * payload hash gates the rewrite — surviving row ids are the proof.
         */
        $fakeSequence('Ladd McConkey 22 Yd pass', 'Ladd McConkey 22 Yd pass');

        $sync = app(SyncGameSummary::class);

        $sync->handle($this->live);
        $before = GameScoringPlay::where('game_id', $this->live->id)->pluck('id');

        $sync->handle($this->live->fresh());
        $after = GameScoringPlay::where('game_id', $this->live->id)->pluck('id');

        expect($before)->not->toBeEmpty()
            ->and($after->all())->toBe($before->all());
    });

    it('rewrites when a play is corrected, even at the same count', function () use ($fakeSequence) {
        // The reason the guard is a payload HASH: ESPN issues corrections
        // that rewrite an existing play, which a count or last-sequence
        // check cannot see.
        $fakeSequence('Ladd McConkey 22 Yd pass', 'Ladd McConkey 23 Yd pass');

        $sync = app(SyncGameSummary::class);
        $sync->handle($this->live);
        $sync->handle($this->live->fresh());

        $plays = GameScoringPlay::where('game_id', $this->live->id)->get();

        expect($plays)->toHaveCount(1)
            ->and($plays->first()->text)->toContain('23 Yd');
    });
});

describe('articles riding the summary', function () {
    /** @return array<string, mixed> */
    function summaryWithArticles(): array
    {
        return [
            'boxscore' => ['teams' => [], 'players' => []],
            'article' => [
                'id' => 47667165,
                'type' => 'Recap',
                'headline' => 'Georgia survives Alabama in a classic',
                'description' => 'A finish for the ages.',
                'published' => '2025-09-28T04:17:59Z',
                'links' => ['web' => ['href' => 'https://www.espn.com/ncf/recap?gameId=401']],
                'story' => '<p>The whole story, inline.</p>',
                'images' => [['url' => 'https://a.espncdn.com/photo/lead.jpg', 'caption' => 'Lead', 'width' => 608, 'height' => 342]],
                'categories' => [['type' => 'team', 'teamId' => 61]],
            ],
            'news' => ['articles' => [
                [
                    'id' => 49546711,
                    'type' => 'HeadlineNews',
                    'headline' => 'What the win means for the East',
                    'published' => '2025-09-28T10:00:00Z',
                ],
                // The recap can appear in its own related list; the recap
                // role must win rather than being demoted to related.
                ['id' => 47667165, 'type' => 'Recap', 'headline' => 'Georgia survives Alabama in a classic'],
            ]],
        ];
    }

    it('stores the recap and related list, roles intact', function () {
        Http::fake(['*' => Http::response(summaryWithArticles())]);

        app(SyncGameSummary::class)->handle($this->live);

        $roles = $this->live->articles()->pluck('role', 'espn_id');

        expect($roles->get(47667165))->toBe('recap')
            ->and($roles->get(49546711))->toBe('related')
            ->and($this->live->articles()->count())->toBe(2);
    });

    it('stores the inline recap body so the first reader costs no request', function () {
        Http::fake(['*' => Http::response(summaryWithArticles())]);

        app(SyncGameSummary::class)->handle($this->live);

        $article = Article::where('espn_id', 47667165)->sole();

        expect($article->story)->toBe('<p>The whole story, inline.</p>')
            ->and($article->story_fetched_at)->not->toBeNull()
            ->and($article->storyIsWorthFetching())->toBeFalse()
            ->and($article->story_images)->toHaveCount(1)
            ->and($article->teams()->pluck('teams.id')->all())->toBe([61]);
    });

    it('never overwrites a body already fetched', function () {
        $article = Article::create([
            'espn_id' => 47667165, 'headline' => 'Old headline', 'type' => 'Recap',
        ]);
        $article->forceFill(['story' => '<p>Fetched first.</p>', 'story_fetched_at' => now()])->save();

        Http::fake(['*' => Http::response(summaryWithArticles())]);

        app(SyncGameSummary::class)->handle($this->live);

        expect(Article::where('espn_id', 47667165)->sole()->story)->toBe('<p>Fetched first.</p>');
    });

    it('keeps links a previous pass made when a later payload omits them', function () {
        // The live sweep re-fetches every two minutes and mid-game payloads
        // carry no recap yet — a re-fetch must not drop what an earlier,
        // fuller pass linked.
        Http::fake(['*' => Http::sequence()
            ->push(summaryWithArticles())
            ->push(['boxscore' => ['teams' => [], 'players' => []]]),
        ]);

        app(SyncGameSummary::class)->handle($this->live);

        $this->live->summary?->update(['synced_at' => now()->subHour()]);

        app(SyncGameSummary::class)->handle($this->live->fresh());

        expect($this->live->articles()->count())->toBe(2);
    });
});

describe('a game the scoreboard left live', function () {
    /*
     * The stuck-game recovery. SyncGames is the only writer of `status` and
     * `completed`, and it can only correct an event its scoreboard payload
     * carries — so a game ESPN stops moving, or one whose event leaves the ET
     * date bucket the live tier asks for, freezes mid-quarter. Every screen
     * reads live before final, so it wears "5:00 - 4th" indefinitely, and the
     * game/summary disagreement makes the two-minute sweep re-fetch 544 KB for
     * the rest of the season. The summary is fetched by EVENT ID, so it is the
     * one source that cannot lose the game — and it already carries the
     * status header.
     */
    $header = fn (array $status, array $competitors = []) => [
        'boxscore' => ['teams' => [], 'players' => []],
        'header' => ['competitions' => [[
            'status' => $status,
            'competitors' => $competitors,
        ]]],
    ];

    $finalHeader = fn () => [
        'period' => 4,
        'displayClock' => '0:00',
        'type' => ['state' => 'post', 'completed' => true, 'shortDetail' => 'Final'],
    ];

    beforeEach(function () {
        // Frozen where the bug is reported: fourth quarter, five minutes left,
        // a full situation block the scoreboard never got to clear.
        $this->live->update([
            'status' => 'in', 'status_detail' => '5:00 - 4th',
            'period' => 4, 'clock' => '5:00',
            'home_score' => 28, 'away_score' => 24,
            'possession_team_id' => 61, 'down' => 3, 'distance' => 7,
            'yard_line' => 41, 'down_distance_text' => '3rd & 7',
            'is_red_zone' => false, 'last_play_text' => 'Pass incomplete to Bowers.',
            'home_timeouts' => 2, 'away_timeouts' => 1,
        ]);
    });

    it('finishes it from the summary header, situation and all', function () use ($header, $finalHeader) {
        Event::fake([GameWentFinal::class]);

        Http::fake(['*' => Http::response($header($finalHeader(), [
            ['homeAway' => 'home', 'score' => '31'],
            ['homeAway' => 'away', 'score' => '24'],
        ]))]);

        app(SyncGameSummary::class)->handle($this->live);

        $game = $this->live->fresh();

        expect($game->completed)->toBeTrue()
            ->and($game->status)->toBe('post')
            ->and($game->status_detail)->toBe('Final')
            ->and($game->clock)->toBe('0:00')
            ->and($game->home_score)->toBe(31)
            ->and($game->away_score)->toBe(24)
            // A final must not wear a frozen "3rd & 7".
            ->and($game->possession_team_id)->toBeNull()
            ->and($game->down)->toBeNull()
            ->and($game->down_distance_text)->toBeNull()
            ->and($game->last_play_text)->toBeNull()
            ->and($game->home_timeouts)->toBeNull();

        // The grading path is the scoreboard's own, not a second one.
        Event::assertDispatched(GameWentFinal::class, fn (GameWentFinal $e) => $e->gameId === $this->live->id);
    });

    it('stops the endless re-fetch the disagreement was causing', function () use ($header, $finalHeader) {
        // The cost half of the bug: `isStale()` treats a game and its summary
        // disagreeing as stale FOREVER, so the sweep spent one 544 KB request
        // every two minutes on a game nothing could fix.
        Http::fake(['*' => Http::response($header($finalHeader()))]);

        $sync = app(SyncGameSummary::class);
        $sync->handle($this->live);

        expect($sync->isStale($this->live->fresh()))->toBeFalse();
    });

    it('leaves the score alone for a side the header does not name', function () use ($header, $finalHeader) {
        // Never write a default when the feed returns nothing — a header
        // without competitors must finish the game, not zero it.
        Http::fake(['*' => Http::response($header($finalHeader()))]);

        app(SyncGameSummary::class)->handle($this->live);

        $game = $this->live->fresh();

        expect($game->completed)->toBeTrue()
            ->and($game->home_score)->toBe(28)
            ->and($game->away_score)->toBe(24);
    });

    it('waits out the window rather than trusting a momentary final', function () use ($header, $finalHeader) {
        /*
         * ESPN briefly reports a game complete and then flips it back — the
         * reason `is_final` is never a short-circuit. Finishing on that would
         * grade picks and flip a slate to prelim mid-game, so the rescue only
         * runs once the game is past the grace window the app already uses to
         * stop presuming a game live. Two hours in, the scoreboard owns this.
         */
        Event::fake([GameWentFinal::class]);

        $this->live->update(['kickoff_at' => now()->subHours(2)]);

        Http::fake(['*' => Http::response($header($finalHeader()))]);

        app(SyncGameSummary::class)->handle($this->live->fresh());

        $game = $this->live->fresh();

        expect($game->completed)->toBeFalse()
            ->and($game->status)->toBe('in')
            ->and($game->clock)->toBe('5:00');

        Event::assertNotDispatched(GameWentFinal::class);
    });

    it('does not finish a game the header still calls live', function () use ($header) {
        // The window passing is not evidence of a final. A game genuinely
        // running long — weather delay, a marathon of overtimes — must keep
        // its clock.
        Event::fake([GameWentFinal::class]);

        Http::fake(['*' => Http::response($header([
            'period' => 4,
            'displayClock' => '4:12',
            'type' => ['state' => 'in', 'completed' => false, 'shortDetail' => '4:12 - 4th'],
        ]))]);

        app(SyncGameSummary::class)->handle($this->live);

        expect($this->live->fresh()->completed)->toBeFalse();

        Event::assertNotDispatched(GameWentFinal::class);
    });
});

it('releases the in-flight lock on completion', function () {
    /*
     * The lock is a concurrency guard, not a rate limiter. Its never-released
     * predecessor silently swallowed any fetch made within a minute of the
     * last one — the same bug the athlete game-log lock had. Two due fetches
     * in a row must both land.
     */
    ($this->fakeEmptySummary)();

    $sync = app(SyncGameSummary::class);

    (new FetchGameSummary($this->live->id, force: true))->handle($sync);
    (new FetchGameSummary($this->live->id, force: true))->handle($sync);

    Http::assertSentCount(2);
});
