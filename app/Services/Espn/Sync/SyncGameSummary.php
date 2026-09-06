<?php

namespace App\Services\Espn\Sync;

use App\Events\GameWentFinal;
use App\Models\Article;
use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteTeamSeason;
use App\Models\Game;
use App\Models\GameDrive;
use App\Models\GameScoringPlay;
use App\Models\GameSummary;
use App\Models\GameTeamStat;
use App\Models\Team;
use App\Services\Espn\EspnClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One game's box score, scoring summary and drives.
 *
 * This is the only single-game fetch in the application, and it exists under
 * protest: the summary payload is 544 KB — LARGER than a whole day's 25-game
 * scoreboard at 440 KB — so refreshing one game costs more than refreshing all
 * of them. Nothing that can be answered from the scoreboard feed is answered
 * here.
 *
 * It is unavoidable for a game page, because the box score, the scoring plays
 * and the drives exist nowhere else in ESPN's API.
 *
 * The cost is therefore bounded in two ways:
 *
 *   - A FINAL game is fetched exactly once, ever. Its summary cannot change,
 *     so every later page view is a pure database read.
 *   - A LIVE game is fetched at most once per staleness window (60s), and
 *     never by a page view directly: viewers and the gameday sweep both
 *     dispatch FetchGameSummary, whose uniqueness and in-handle staleness
 *     re-check collapse a hundred watchers of one game into one request.
 *
 * The in-flight lock below is RELEASED on completion — it is a concurrency
 * guard for the one race job uniqueness cannot see (a backfill batch job
 * executing beside a live job), not a rate limiter. The old never-released
 * 60s variant silently swallowed legitimate fetches made within a minute of
 * the last one; the game-log sync learned the same lesson first.
 *
 * One useful side effect: this is the ONLY source of historical athletes. The
 * roster endpoint publishes the current season only, so a 2021 player has no
 * roster row to have come from. Box scores name every player who took a snap,
 * which is how past seasons get populated at all.
 */
class SyncGameSummary
{
    /** Crash ceiling only — the lock is released in a finally. */
    private const IN_FLIGHT_SECONDS = 60;

    public function __construct(private EspnClient $espn, private SyncNews $news) {}

    /**
     * Whether this game's summary needs a fetch.
     *
     * Game-aware where the model's own isStale() cannot be: "a final summary
     * never changes" is only true while the GAME agrees that it is final, and
     * the two disagree in both directions.
     *
     *   completed game, non-final summary   the just-final fetch was
     *                                       swallowed (crashed worker,
     *                                       cancelled batch), and staleness
     *                                       alone would leave a finished game
     *                                       wearing a mid-game box score
     *   live game, final summary            ESPN briefly reported the game
     *                                       complete and then flipped it
     *                                       back. Trusting the flag here
     *                                       freezes the box score for the
     *                                       rest of the game — is_final's
     *                                       short-circuit is permanent
     *
     * So disagreement is always stale, and the cheap archive short-circuit
     * survives for the case it was written for: both sides final.
     *
     * The second case is deliberately unbounded HERE and bounded elsewhere:
     * left to itself it re-fetches 544 KB every sweep for as long as the two
     * disagree, which for a game the scoreboard has abandoned is the rest of
     * the season. {@see reconcileFinal()} is what ends it.
     */
    public function isStale(Game $game): bool
    {
        $summary = $game->summary;

        if ($summary === null) {
            return true;
        }

        if ($game->completed !== (bool) $summary->is_final) {
            return true;
        }

        return $summary->isStale();
    }

    /**
     * Fetch and store. Returns whether a fetch actually happened.
     *
     * Freshness policy lives in the CALLER (FetchGameSummary re-checks
     * isStale() unless forced); this method only refuses to run two fetches
     * for one game at the same instant.
     */
    public function handle(Game $game): bool
    {
        $lock = Cache::lock("espn:summary:{$game->id}", self::IN_FLIGHT_SECONDS);

        // A concurrent fetch for this game is in flight — the caller renders
        // what is already stored, and the in-flight copy lands momentarily.
        if (! $lock->get()) {
            return false;
        }

        try {
            return $this->fetch($game);
        } finally {
            $lock->release();
        }
    }

    private function fetch(Game $game): bool
    {
        $body = $this->espn->site('summary', ['event' => $game->id], ttl: config('espn.cache.live'));

        if ($body === null || ! isset($body['boxscore'])) {
            return false;
        }

        $isFinal = (bool) data_get($body, 'header.competitions.0.status.type.completed', $game->completed);

        // Hashed OUTSIDE the row write so an unchanged scoring summary skips
        // the delete-and-recreate below — under a two-minute sweep that write
        // would otherwise churn every scoring row all Saturday against a
        // scale-to-zero database.
        $playsHash = md5(json_encode($body['scoringPlays'] ?? []));
        $previousHash = GameSummary::whereKey($game->id)->value('scoring_plays_hash');

        DB::transaction(function () use ($game, $body, $isFinal, $playsHash, $previousHash) {
            $this->storeTeamStats($game, $body);

            if ($playsHash !== $previousHash) {
                $this->storeScoringPlays($game, $body);
            }

            $this->storePlayerStats($game, $body);

            GameSummary::updateOrCreate(
                ['game_id' => $game->id],
                [
                    'win_probability' => $body['winprobability'] ?? null,
                    'leaders' => $body['leaders'] ?? null,
                    'attendance' => data_get($body, 'gameInfo.attendance'),
                    'is_final' => $isFinal,
                    // Written in the same transaction as the rows it
                    // describes, so a rollback cannot strand a hash that
                    // claims plays which were never stored.
                    'scoring_plays_hash' => $playsHash,
                    'synced_at' => now(),
                ]
            );

            // Its own table and its own row: 306 KB on average, and keeping
            // it beside the summary made every game-page view read it.
            GameDrive::updateOrCreate(
                ['game_id' => $game->id],
                ['drives' => data_get($body, 'drives.previous')]
            );
        });

        // Outside the transaction, and BEFORE the articles: a stuck game is
        // the reason this payload keeps being fetched at all, and unsticking
        // it must not ride on a recap article linking cleanly.
        $this->reconcileFinal($game, $body);

        // Outside the transaction: articles are their own aggregate, and a
        // failure linking one must not roll back a stored box score.
        $this->storeArticles($game, $body);

        return true;
    }

    /**
     * The one place a game stuck LIVE can be finished.
     *
     * `SyncGames` is the only writer of `status` and `completed`, and it can
     * only correct an event its scoreboard payload actually carries. When that
     * stops happening the row freezes mid-quarter: every screen reads live
     * before final, so a finished game wears "5:00 - 4th" indefinitely, and
     * `isStale()` — which treats a game and its summary disagreeing as
     * permanently stale — then re-fetches this 544 KB payload every sweep for
     * the rest of the season without anything ever changing.
     *
     * This closes both. The summary is fetched by EVENT ID rather than by
     * date, so it is the one source that cannot lose the game to a bucket, and
     * its header carries the same status block the scoreboard does — we
     * already read `type.completed` out of it and then threw it away.
     *
     * DELIBERATELY LATE, never eager. ESPN briefly reports a game complete and
     * flips it back (the reason `is_final` is not trusted as a short-circuit),
     * and premature finality grades picks and flips a slate to prelim. So this
     * waits out {@see Game::isStuckLive()} — the same grace window after which
     * the app already stops presuming a game is live — by which point the
     * scoreboard has had hundreds of passes to say so itself. In the ordinary
     * case it never fires at all.
     *
     * No `FetchGameSummary` dispatch on the transition, unlike the scoreboard's
     * own: the final summary is the payload in hand.
     */
    private function reconcileFinal(Game $game, array $body): void
    {
        $status = data_get($body, 'header.competitions.0.status', []);
        $type = $status['type'] ?? [];

        if (! ($type['completed'] ?? false) || ! $game->isStuckLive()) {
            return;
        }

        $game->fill([
            'status' => $type['state'] ?? 'post',
            'status_detail' => $type['shortDetail'] ?? null,
            'period' => (int) ($status['period'] ?? $game->period),
            'clock' => $status['displayClock'] ?? null,
            'completed' => true,
            // A final must not wear a frozen "3rd & 7" — the same reading
            // SyncGames::situation() takes when a game stops being live.
            'possession_team_id' => null,
            'down' => null,
            'distance' => null,
            'yard_line' => null,
            'down_distance_text' => null,
            'is_red_zone' => false,
            'last_play_text' => null,
            'home_timeouts' => null,
            'away_timeouts' => null,
            ...$this->finalScores($body),
        ]);

        $game->save();

        // The same signal the scoreboard fires on its own transition, so a
        // rescued final grades picks and settles slates by the one path.
        GameWentFinal::dispatch($game->id);
    }

    /**
     * The final score off the summary header.
     *
     * A game frozen mid-quarter is usually frozen on a stale score too, and a
     * "Final" over the wrong number is worse than a stuck clock. A side ESPN
     * does not name is LEFT ALONE rather than zeroed — the missing-data rule,
     * which is exactly how v3 overwrote real scores with defaults.
     *
     * @return array<string, int>
     */
    private function finalScores(array $body): array
    {
        $scores = [];

        foreach (data_get($body, 'header.competitions.0.competitors', []) as $competitor) {
            $side = $competitor['homeAway'] ?? null;
            $score = $competitor['score'] ?? null;

            if (in_array($side, ['home', 'away'], true) && is_numeric($score)) {
                $scores["{$side}_score"] = (int) $score;
            }
        }

        return $scores;
    }

    /**
     * The recap article and ESPN's related list, both riding the summary
     * payload we already paid for and previously discarded.
     *
     * The upsert is SyncNews::store — the same writer the news feed uses, so
     * an article arriving from both cannot double or drift. Only the LINK
     * belongs to this sync.
     */
    private function storeArticles(Game $game, array $body): void
    {
        $links = [];
        $seen = [];

        $recap = $body['article'] ?? null;

        if (is_array($recap)) {
            $article = $this->news->store($recap);

            if ($article !== null) {
                $this->storeInlineStory($article, $recap);
                $links[$article->id] = ['role' => 'recap'];
                $seen[(int) $recap['id']] = true;
            }
        }

        foreach (data_get($body, 'news.articles', []) as $payload) {
            // The recap can appear in its own related list, usually as a
            // sparser copy — re-storing it would overwrite full fields with
            // absent ones and demote its role.
            if (! is_array($payload) || isset($seen[(int) ($payload['id'] ?? 0)])) {
                continue;
            }

            $article = $this->news->store($payload);

            if ($article !== null) {
                $links[$article->id] = ['role' => 'related'];
                $seen[(int) $payload['id']] = true;
            }
        }

        if ($links !== []) {
            $this->storeArticleLinks($game, $links);
        }
    }

    /**
     * `syncWithoutDetaching()`'s effect on `article_game`, minus its race.
     *
     * Same shape and same exposure as the `article_team` write in
     * `SyncNews::syncTeamLinks()`: read-then-write against a pivot carrying
     * `unique(article_id, game_id)`, from jobs that are parallel on purpose.
     * A national story sits in several games' related lists at once, so two
     * `FetchGameSummary` workers insert the same pair and the loser throws.
     *
     * Never detaching stays: the live sweep re-runs this every two minutes
     * and a mid-game payload carries no recap yet, so a re-fetch must not
     * drop what a fuller pass linked. The role is still corrected when it
     * genuinely changes, and only then — writes are not free on scale-to-zero
     * MySQL, and the usual pass changes nothing.
     *
     * @param  array<int, array{role: string}>  $links
     */
    private function storeArticleLinks(Game $game, array $links): void
    {
        $now = now();

        DB::table('article_game')->insertOrIgnore(array_map(fn (int $articleId): array => [
            'article_id' => $articleId,
            'game_id' => $game->id,
            'role' => $links[$articleId]['role'],
            'created_at' => $now,
            'updated_at' => $now,
        ], array_keys($links)));

        // Grouped by role rather than one statement per link: there are only
        // ever two roles, and `where role !=` means the common no-change pass
        // writes nothing.
        // preserveKeys, or the group loses the article ids it is grouping.
        foreach (collect($links)->groupBy('role', preserveKeys: true) as $role => $group) {
            DB::table('article_game')
                ->where('game_id', $game->id)
                ->whereIn('article_id', $group->keys()->all())
                ->where('role', '!=', $role)
                ->update(['role' => $role, 'updated_at' => $now]);
        }
    }

    /**
     * The recap carries its full body INLINE — 7.7 KB of the same raw markup
     * the now endpoint serves — so storing it here saves the one request
     * `SyncArticleStory` would otherwise spend on the game page's first
     * reader. Never overwrites: a body already fetched is already right.
     */
    private function storeInlineStory(Article $article, array $payload): void
    {
        if ($article->story !== null) {
            return;
        }

        $story = trim((string) ($payload['story'] ?? ''));

        if ($story === '') {
            return;
        }

        $article->forceFill([
            'story' => $story,
            'story_images' => SyncArticleStory::images($payload),
            'story_fetched_at' => now(),
        ])->save();
    }

    /**
     * The team box score — first downs, third-down efficiency, total yards.
     *
     * `stats` is keyed by ESPN's stat name and `display_stats` carries the
     * order and labels as a JSON ARRAY, because MySQL does not preserve JSON
     * object key order. Storing order in the map itself means the box score
     * silently rearranges on the next read.
     */
    private function storeTeamStats(Game $game, array $body): void
    {
        foreach ($body['boxscore']['teams'] ?? [] as $side) {
            $teamId = (int) data_get($side, 'team.id');

            if ($teamId === 0 || ! Team::whereKey($teamId)->exists()) {
                continue;
            }

            $stats = [];
            $order = [];

            foreach ($side['statistics'] ?? [] as $stat) {
                if (! isset($stat['name'])) {
                    continue;
                }

                $stats[$stat['name']] = $stat['displayValue'] ?? $stat['value'] ?? null;
                $order[] = ['name' => $stat['name'], 'label' => $stat['label'] ?? $stat['name']];
            }

            if ($stats === []) {
                continue;
            }

            GameTeamStat::updateOrCreate(
                ['game_id' => $game->id, 'team_id' => $teamId],
                ['stats' => $stats, 'display_stats' => $order]
            );
        }
    }

    /**
     * The scoring summary.
     *
     * `sequence` comes from the payload's own ordering rather than from period
     * and clock: a football clock counts DOWN, so sorting by clock ascending
     * within a period reverses each quarter.
     */
    private function storeScoringPlays(Game $game, array $body): void
    {
        $plays = $body['scoringPlays'] ?? [];

        if ($plays === []) {
            return;
        }

        // Replaced wholesale rather than upserted: a live game's scoring plays
        // only ever grow, but a correction can rewrite one, and a stale row
        // with a sequence nobody reuses would linger forever. The caller's
        // payload hash gates this — a count or last-sequence check could not,
        // because a correction changes neither — so an unchanged summary
        // never pays for the rewrite.
        GameScoringPlay::where('game_id', $game->id)->delete();

        foreach ($plays as $index => $play) {
            $teamId = (int) data_get($play, 'team.id');

            GameScoringPlay::create([
                'game_id' => $game->id,
                'team_id' => Team::whereKey($teamId)->exists() ? $teamId : null,
                'sequence' => $index + 1,
                'period' => data_get($play, 'period.number'),
                'clock' => data_get($play, 'clock.displayValue'),
                'type' => data_get($play, 'type.text'),
                'abbreviation' => data_get($play, 'scoringType.abbreviation')
                    ?? data_get($play, 'type.abbreviation'),
                'text' => $play['text'] ?? null,
                'home_score' => $this->score($play['homeScore'] ?? null),
                'away_score' => $this->score($play['awayScore'] ?? null),
            ]);
        }
    }

    /**
     * A running score, or null if ESPN sent something impossible.
     *
     * Verified live: game 401767129 carries a scoring play with
     * `homeScore: -14`. A running score cannot be negative, and the column is
     * unsigned, so writing it raw threw and took down a 954-game backfill at
     * game 260 over one corrupt row in one game.
     *
     * Null rather than clamping to zero: we do not know what the score was, and
     * inventing 0 would render a confidently wrong scoreline. The play text
     * still displays.
     */
    private function score(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $score = (int) $value;

        return $score >= 0 && $score <= 255 ? $score : null;
    }

    /**
     * The player box score.
     *
     * ESPN delivers each athlete's line as a POSITIONAL array, which is exactly
     * the shape that broke v3 — it indexed `stats[0]`/`stats[1]` and scrambled
     * whenever ESPN reordered. The payload also ships a parallel `keys` array
     * naming each position, so the two are zipped into a keyed map here and
     * every read downstream is by name. `labels` carries the short column
     * headings and preserves display order.
     */
    private function storePlayerStats(Game $game, array $body): void
    {
        $seasonYear = $game->season?->year;

        foreach ($body['boxscore']['players'] ?? [] as $side) {
            $teamId = (int) data_get($side, 'team.id');

            if ($teamId === 0 || ! Team::whereKey($teamId)->exists()) {
                continue;
            }

            foreach ($side['statistics'] ?? [] as $category) {
                $keys = $category['keys'] ?? [];
                $labels = $category['labels'] ?? [];
                $name = $category['name'] ?? null;

                if ($name === null || $keys === []) {
                    continue;
                }

                foreach ($category['athletes'] ?? [] as $entry) {
                    $athlete = $entry['athlete'] ?? null;
                    $values = $entry['stats'] ?? [];

                    if ($athlete === null || $values === []) {
                        continue;
                    }

                    $athleteId = $this->ensureAthlete($athlete, $teamId, $seasonYear);

                    if ($athleteId === null) {
                        continue;
                    }

                    AthleteGameStat::updateOrCreate(
                        ['athlete_id' => $athleteId, 'game_id' => $game->id, 'category' => $name],
                        [
                            'team_id' => $teamId,
                            // Zipped by name, never read by position.
                            'stats' => array_combine(
                                array_slice($keys, 0, count($values)),
                                $values
                            ),
                            'display_stats' => array_map(
                                fn ($key, $label) => ['name' => $key, 'label' => $label],
                                array_slice($keys, 0, count($labels)),
                                $labels
                            ),
                        ]
                    );
                }
            }
        }
    }

    /**
     * Make sure the athlete exists, without clobbering richer roster data.
     *
     * `firstOrCreate`, not `updateOrCreate`: a player already synced from a
     * roster has measurables, hometown and class that the box score does not
     * carry, and overwriting them with the sparse version would be a silent
     * downgrade.
     *
     * Returns null for ESPN's pseudo-athletes. A box score carries team-level
     * lines — sack yardage charged to "Team", for instance — as athletes with a
     * NEGATIVE id and the display name "Team". They are not people, their
     * production is already in the team box score, and `athletes.id` is
     * unsigned, so inserting one fails outright.
     */
    private function ensureAthlete(array $payload, int $teamId, ?int $seasonYear): ?int
    {
        $id = (int) ($payload['id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        Athlete::firstOrCreate(
            ['id' => $id],
            [
                'first_name' => $payload['firstName'] ?? null,
                'last_name' => $payload['lastName'] ?? null,
                'display_name' => $payload['displayName'] ?? 'Unknown',
                'headshot_url' => data_get($payload, 'headshot.href'),
            ]
        );

        // Historical roster membership, which the roster endpoint cannot give
        // us — it only ever returns the current season.
        if ($seasonYear !== null) {
            AthleteTeamSeason::firstOrCreate(
                ['athlete_id' => $id, 'season_year' => $seasonYear],
                ['team_id' => $teamId, 'jersey' => $payload['jersey'] ?? null]
            );
        }

        return $id;
    }
}
