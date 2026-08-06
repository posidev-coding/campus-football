<?php

namespace App\Services\Espn\Sync;

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteTeamSeason;
use App\Models\Game;
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

    public function __construct(private EspnClient $espn) {}

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
                    'drives' => data_get($body, 'drives.previous'),
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
        });

        return true;
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
