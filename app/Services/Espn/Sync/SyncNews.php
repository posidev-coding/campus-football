<?php

namespace App\Services\Espn\Sync;

use App\Models\Article;
use App\Models\Team;
use App\Services\Espn\EspnClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * College football news.
 *
 * Three things about this feed were verified live and none are guessable:
 *
 * 1. `limit` is CLAMPED TO 50. Asking for 1000 returns 50, silently — the same
 *    class of trap as the per-week game request that truncates. There is no
 *    pagination parameter that lifts it.
 *
 * 2. The `team=` filter is REAL. Georgia's feed shares only 5 of its 50 articles
 *    with the general feed and reaches back about eight weeks rather than six
 *    days. This is worth stating because the sibling `athlete=` filter on the
 *    same endpoint is silently IGNORED and returns general news — so one
 *    parameter on this endpoint can be trusted and the other cannot.
 *
 * 3. Every article on the college-football path carries an `NCAA Football`
 *    league tag, so no filtering is needed. Basketball tags do appear, but as
 *    ADDITIONAL tags on genuinely multi-sport stories (an athletic director
 *    hire), not as off-topic articles.
 *
 * Because the window is only days wide, history here is ACCUMULATED rather than
 * backfilled, and nothing in this class ever deletes: an article falling out of
 * ESPN's window must not fall out of ours.
 */
class SyncNews
{
    /** ESPN clamps to this regardless of what we ask for. Stated, not hoped. */
    private const MAX_LIMIT = 50;

    /**
     * Extra attempts after a deadlock, and only after a deadlock.
     *
     * Two, because the cycle needs another writer holding the same article's
     * locks at the same instant and that window is milliseconds wide — a
     * third attempt would be waiting on something other than contention, and
     * the honest answer to that is to fail and leave a ledger row.
     */
    private const DEADLOCK_RETRIES = 2;

    public function __construct(private EspnClient $espn) {}

    /**
     * The national feed.
     */
    public function general(): int
    {
        return $this->ingest($this->fetch());
    }

    /**
     * One team's feed.
     */
    public function team(int $teamId): int
    {
        return $this->ingest($this->fetch($teamId));
    }

    /**
     * Team feeds for teams somebody actually follows.
     *
     * 136 FBS teams is too many to refresh blindly every cycle for content
     * nobody has asked to see. This is the same principle the game summary
     * throttle follows: cost tracks interest.
     */
    public function followed(): int
    {
        // One source. This used to union in `users.favorite_team_id` as well,
        // because a favorite was not guaranteed to be followed; now a favorite
        // IS simply the first followed team, so the pivot covers everyone.
        $teamIds = DB::table('team_follows')->distinct()->pluck('team_id');

        $synced = 0;

        foreach ($teamIds as $teamId) {
            $synced += $this->team((int) $teamId);
        }

        return $synced;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(?int $teamId = null): array
    {
        $query = ['limit' => self::MAX_LIMIT];

        if ($teamId !== null) {
            $query['team'] = $teamId;
        }

        $body = $this->espn->site('news', $query, ttl: config('espn.cache.schedule'));

        // No data is not the same as an empty feed. Returning null here rather
        // than an empty array is what stops a caller writing over what we have.
        if ($body === null || ! isset($body['articles'])) {
            return [];
        }

        return $body['articles'];
    }

    /**
     * @param  list<array<string, mixed>>  $articles
     */
    private function ingest(array $articles): int
    {
        $known = null;
        $synced = 0;

        foreach ($articles as $payload) {
            // Resolved once per batch rather than per article — a Top 25
            // listicle tags 25 teams and there are 50 articles, so this is the
            // difference between one query and hundreds.
            $known ??= Team::pluck('id')->flip();

            if ($this->store($payload, $known) !== null) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Upsert one article payload and its team links.
     *
     * Public because the news FEED is not the only place ESPN hands us an
     * article: the game summary carries the recap and a related list in the
     * same shape, and two writers with their own upserts is how the pivot
     * doubles or the shapes drift.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, int>|null  $known
     */
    public function store(array $payload, ?Collection $known = null): ?Article
    {
        $espnId = $payload['id'] ?? null;

        if ($espnId === null || ! isset($payload['headline'])) {
            return null;
        }

        $article = Article::updateOrCreate(
            ['espn_id' => (int) $espnId],
            [
                'headline' => $payload['headline'],
                'description' => $payload['description'] ?? null,
                'byline' => $payload['byline'] ?? null,
                'type' => $payload['type'] ?? null,
                'image_url' => $this->image($payload),
                'url' => data_get($payload, 'links.web.href'),
                'premium' => (bool) ($payload['premium'] ?? false),
                'published_at' => isset($payload['published'])
                    ? CarbonImmutable::parse($payload['published'])
                    : null,
            ]
        );

        // Only when the payload SPEAKS about teams. The summary's related
        // list can name an article we already hold with no categories block
        // at all, and syncing [] from that absence would strip links a fuller
        // payload made — the cache-layer cousin of writing a default when a
        // feed returns nothing.
        if (array_key_exists('categories', $payload)) {
            $known ??= Team::pluck('id')->flip();

            $this->syncTeamLinks($article, $this->teamIds($payload, $known));
        }

        return $article;
    }

    /**
     * `sync()`'s effect on `article_team`, minus its race.
     *
     * `sync()` is read-then-write: it SELECTs the current pivot rows, diffs
     * them, then INSERTs the difference. `article_team` carries
     * `unique(article_id, team_id)`, and there are four concurrent writers
     * into `store()` by design — the news feed, `SyncTeamNews`, and the
     * summary path fanned out one `FetchGameSummary` per game. ESPN repeats
     * the same national stories across many games' related lists, so on a
     * live Saturday several jobs store ONE article at once, and whoever
     * SELECTed first lost: a `UniqueConstraintViolationException` that cost a
     * `FetchGameSummary` retry, and abandoned the rest of an `ingest()` batch
     * of up to 50 articles outright.
     *
     * The workers stay parallel — the ESPN cost is limiter-bound, not
     * worker-bound, so serializing them buys nothing and a per-article lock
     * would cost a round trip per article. The WRITE becomes idempotent
     * instead: `insertOrIgnore` makes the losing writer a no-op rather than a
     * throw, and the delete keeps the detach semantics `sync()` was chosen
     * for. Not `syncWithoutDetaching()`, which would quietly stop removing a
     * link a fuller payload later drops.
     *
     * An empty `$wanted` still detaches everything, exactly as `sync([])`
     * did. That is only reachable when the payload HAS a categories block
     * naming no team we carry; an absent block never gets this far.
     *
     * THE RACE CAME BACK AS A DEADLOCK. `insertOrIgnore` retired the unique
     * violation and left the contention underneath it: `insert ignore` still
     * takes next-key locks on `unique(article_id, team_id)`, the DELETE locks
     * the same `article_id` range on the same index, and `teamIds()` preserves
     * ESPN's payload order — so two writers of one national story took the
     * same row locks in opposite orders and MySQL rolled one of them back.
     * Seven `SQLSTATE[40001]` in a day, and the one that landed on the 06:00
     * pass abandoned the rest of that ingest batch.
     *
     * Two things answer it, and a third makes both rarer:
     *
     * 1. `$wanted` IS SORTED, so every writer takes the insert's row locks in
     *    the same order. That closes the insert-against-insert cycle outright,
     *    for the cost of one sort.
     * 2. The delete-against-insert cycle survives ordering, so the pair is
     *    retried on a deadlock and only on a deadlock. MySQL has already
     *    rolled the loser back and both statements are idempotent, so a retry
     *    is safe — see {@see writeTeamLinks()}.
     * 3. Most calls have nothing to do at all, because ESPN hands the same
     *    national story to several jobs, and both statements are then pure
     *    lock acquisition for no change.
     *
     * NOT a transaction around the pair: a longer transaction holds those same
     * locks for longer and widens the window it was reached for. NOT a
     * per-article lock either, for the reason above — a round trip per
     * article, and it is still the wrong trade.
     *
     * @param  list<int>  $wanted
     */
    private function syncTeamLinks(Article $article, array $wanted): void
    {
        // One order for every writer. Ascending is arbitrary; AGREEING is the
        // whole point, and it must happen before the retry loop so a second
        // attempt cannot take the locks in a third order.
        sort($wanted);

        for ($attempt = 0; ; $attempt++) {
            try {
                $this->writeTeamLinks($article, $wanted);

                return;
            } catch (QueryException $e) {
                if ($attempt >= self::DEADLOCK_RETRIES || ! self::isDeadlock($e)) {
                    throw $e;
                }

                // Jittered, so two writers that just deadlocked with each
                // other do not wake together and do it again.
                usleep(random_int(20_000, 80_000));
            }
        }
    }

    /**
     * The write itself: detach what the payload dropped, attach what it names.
     *
     * @param  list<int>  $wanted  ascending, so every writer agrees on lock order
     */
    private function writeTeamLinks(Article $article, array $wanted): void
    {
        /*
         * The common case, and the cheap one: this article's links already
         * say exactly what this payload says. ESPN repeats a national story
         * across many games' related lists, so most of the writers reaching
         * here have nothing to change, and running the pair anyway is two
         * lock-taking statements for no row.
         *
         * A READ, NEVER THE AUTHORITY. It decides only whether to write at
         * all; it never computes WHAT to write, which is what made the
         * original diff a race. The write below stays correct on its own, so
         * a stale read costs a redundant write and never a wrong row.
         */
        $stored = DB::table('article_team')
            ->where('article_id', $article->id)
            ->orderBy('team_id')
            ->pluck('team_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($stored === $wanted) {
            return;
        }

        DB::table('article_team')
            ->where('article_id', $article->id)
            ->whereNotIn('team_id', $wanted)
            ->delete();

        if ($wanted === []) {
            return;
        }

        $now = now();

        // Every wanted row, not just the ones a SELECT said were missing:
        // the rows that already exist are ignored, which is the same work the
        // diff was doing and one fewer query in which to lose the race.
        DB::table('article_team')->insertOrIgnore(array_map(fn (int $teamId): array => [
            'article_id' => $article->id,
            'team_id' => $teamId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $wanted));
    }

    /**
     * A deadlock and nothing else.
     *
     * SQLSTATE 40001 is "serialization failure", which for MySQL is error
     * 1213 — the loser of a lock cycle, already rolled back and safe to try
     * again. Every other QueryException is re-raised untouched: a retry loop
     * that swallowed a missing column or a foreign key would turn a bug into
     * three seconds of silence.
     */
    private static function isDeadlock(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode()) === '40001';
    }

    /**
     * Team ids mentioned by an article.
     *
     * ESPN lists each team TWICE — once as "Georgia Bulldogs" and once as
     * "University of Georgia", both carrying the same `teamId` — so this
     * deduplicates. It also drops teams we do not carry rather than letting a
     * foreign key abort the article.
     *
     * @param  Collection<int, int>  $known
     * @return list<int>
     */
    private function teamIds(array $payload, Collection $known): array
    {
        return collect($payload['categories'] ?? [])
            ->where('type', 'team')
            ->map(fn (array $c) => $c['teamId'] ?? data_get($c, 'team.id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id) => $known->has($id))
            ->values()
            ->all();
    }

    private function image(array $payload): ?string
    {
        return data_get($payload, 'images.0.url');
    }
}
