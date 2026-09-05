<?php

use App\Jobs\FetchGameSummary;
use App\Models\Article;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGameSummary;
use App\Services\Espn\Sync\SyncNews;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * The article pivots under parallel writers.
 *
 * `SyncNews::store()` has four concurrent callers by design — the national
 * feed, `SyncTeamNews`, and the summary path fanned out one `FetchGameSummary`
 * per game — and ESPN repeats the same national stories across many games'
 * related lists, so on a live Saturday several of them store ONE article at
 * the same moment. Both pivots carry a unique index, so a read-then-write
 * (`sync()`, `syncWithoutDetaching()`) let the writer who read first lose to
 * the writer who inserted first, with a
 * `UniqueConstraintViolationException` for its trouble.
 *
 * The fix is idempotency, not serialization, so these tests have to produce a
 * real interleave rather than call the service twice in a row: a competing
 * writer's row has to land INSIDE the read-modify-write window.
 */

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    Team::factory()->create(['id' => 61, 'display_name' => 'Georgia']);
    Team::factory()->create(['id' => 333, 'display_name' => 'Alabama']);
});

/**
 * A second writer that lands its row just before ours, once.
 *
 * `DB::beforeExecuting` fires BEFORE a statement runs, and the hook is armed
 * on the first INSERT against the pivot — so the competing row lands in the
 * narrowest possible window, after the reader has read and before the writer
 * writes. That anchor is deliberately shape-independent: a read-then-write
 * (`sync()`, `syncWithoutDetaching()`) has already SELECTed by then and its
 * INSERT collides, while an idempotent write no-ops. `DB::listen` cannot do
 * this job — it fires after the fact, so against an insert-first
 * implementation the "competing" row would arrive once the race was over and
 * the test would pass for the wrong reason.
 */
function interloper(string $table, callable $write): void
{
    $fired = false;

    DB::beforeExecuting(function (string $sql) use ($table, $write, &$fired) {
        if ($fired || ! str_contains($sql, $table)) {
            return;
        }

        if (! str_starts_with(strtolower(ltrim($sql)), 'insert')) {
            return;
        }

        // Set before the write, or the write re-enters this callback.
        $fired = true;

        $write();
    });
}

/** @return array<string, mixed> */
function articlePayload(array $teamIds = [61]): array
{
    return [
        'id' => 47667165,
        'type' => 'Recap',
        'headline' => 'Georgia survives Alabama in a classic',
        'description' => 'A finish for the ages.',
        'published' => '2025-09-28T04:17:59Z',
        'links' => ['web' => ['href' => 'https://www.espn.com/ncf/recap?gameId=401']],
        'categories' => array_map(fn (int $id) => ['type' => 'team', 'teamId' => $id], $teamIds),
    ];
}

it('survives a competing writer inserting the same article_team row mid-write', function () {
    // The article has to exist before the interloper can reference it — this
    // is the SECOND pass over a story ESPN is handing to several summary
    // jobs at once, which is precisely when the collision happens.
    app(SyncNews::class)->store(articlePayload([333]));

    $article = Article::where('espn_id', 47667165)->sole();

    interloper('article_team', function () use ($article) {
        DB::table('article_team')->insert([
            'article_id' => $article->id,
            'team_id' => 61,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    // Against `sync()` this throws UniqueConstraintViolationException: it read
    // the pivot without team 61, the other writer inserted 61, and its own
    // INSERT of 61 hit the unique index.
    app(SyncNews::class)->store(articlePayload([61, 333]));

    $linked = DB::table('article_team')->where('article_id', $article->id)->pluck('team_id');

    expect($linked->sort()->values()->all())->toBe([61, 333])
        ->and($linked)->toHaveCount(2);
});

it('still detaches a link the payload dropped', function () {
    // The test that catches a lazy syncWithoutDetaching() fix: a fuller
    // payload's link has to go when a later payload genuinely drops it.
    app(SyncNews::class)->store(articlePayload([61, 333]));

    app(SyncNews::class)->store(articlePayload([61]));

    $article = Article::where('espn_id', 47667165)->sole();

    expect($article->teams()->pluck('teams.id')->all())->toBe([61]);
});

it('detaches every link when the payload names a categories block with no team we carry', function () {
    app(SyncNews::class)->store(articlePayload([61, 333]));

    // A categories block that speaks, and names nobody — not the same thing
    // as no block at all, and `sync([])` emptied the pivot here.
    app(SyncNews::class)->store(['id' => 47667165, 'headline' => 'Georgia survives Alabama', 'categories' => []]);

    $article = Article::where('espn_id', 47667165)->sole();

    expect($article->teams()->count())->toBe(0);
});

it('leaves the links alone when the payload carries no categories block at all', function () {
    // An absent block is NO DATA, never an empty list. The summary's related
    // list names articles we already hold in exactly this sparser shape, and
    // syncing [] off that absence would strip links a fuller payload made.
    app(SyncNews::class)->store(articlePayload([61, 333]));

    app(SyncNews::class)->store(['id' => 47667165, 'headline' => 'Georgia survives Alabama']);

    $article = Article::where('espn_id', 47667165)->sole();

    expect($article->teams()->pluck('teams.id')->sort()->values()->all())->toBe([61, 333]);
});

describe('the article_game pivot, through the job that races on it', function () {
    beforeEach(function () {
        $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

        $week = Week::create([
            'season_id' => $season->id, 'number' => 5, 'name' => 'Week 5',
            'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
        ]);

        // kickoff PINNED: GameFactory defaults it to a random window, and an
        // unpinned fixture drifts into other tests' date-window queries.
        $this->game = Game::factory()->create([
            'season_id' => $season->id, 'week_id' => $week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'completed' => false, 'status' => 'in',
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);
    });

    /** @return array<string, mixed> */
    function summaryPayload(): array
    {
        return [
            'boxscore' => ['teams' => [], 'players' => []],
            'article' => [
                'id' => 47667165,
                'type' => 'Recap',
                'headline' => 'Georgia survives Alabama in a classic',
                'published' => '2025-09-28T04:17:59Z',
                'categories' => [['type' => 'team', 'teamId' => 61]],
            ],
            'news' => ['articles' => [[
                'id' => 49546711,
                'type' => 'HeadlineNews',
                'headline' => 'What the win means for the East',
                'published' => '2025-09-28T10:00:00Z',
            ]]],
        ];
    }

    it('survives a sibling summary job linking the same article to this game', function () {
        /*
         * Sequential calls are not concurrent viewers, so this is asserted
         * through the JOB — `FetchGameSummary` is dispatched one per game
         * across parallel workers on purpose, and a national story sits in
         * several games' related lists at once. The interloper stands in for
         * the sibling worker that got its INSERT in first.
         */
        Http::fake(['*' => Http::response(summaryPayload())]);

        $article = Article::create([
            'espn_id' => 49546711, 'headline' => 'What the win means for the East',
        ]);

        interloper('article_game', function () use ($article) {
            DB::table('article_game')->insert([
                'article_id' => $article->id,
                'game_id' => $this->game->id,
                'role' => 'related',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        (new FetchGameSummary($this->game->id))->handle(app(SyncGameSummary::class));

        $roles = $this->game->articles()->pluck('role', 'espn_id');

        expect($roles->get(47667165))->toBe('recap')
            ->and($roles->get(49546711))->toBe('related')
            ->and($this->game->articles()->count())->toBe(2);
    });

    it('corrects a role the pivot already holds without detaching anything', function () {
        // insertOrIgnore alone would leave a wrong role in place forever;
        // syncWithoutDetaching() used to fix it, and still has to.
        Http::fake(['*' => Http::response(summaryPayload())]);

        $article = Article::create([
            'espn_id' => 47667165, 'headline' => 'Georgia survives Alabama in a classic',
        ]);

        DB::table('article_game')->insert([
            'article_id' => $article->id,
            'game_id' => $this->game->id,
            'role' => 'related',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new FetchGameSummary($this->game->id))->handle(app(SyncGameSummary::class));

        expect($this->game->articles()->pluck('role', 'espn_id')->get(47667165))->toBe('recap')
            ->and($this->game->articles()->count())->toBe(2);
    });
});
