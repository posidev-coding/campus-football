<?php

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
