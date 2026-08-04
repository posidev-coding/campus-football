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
 *   - A LIVE game is fetched at most once per minute, enforced by a cache lock
 *     keyed on the game rather than on the viewer. A hundred people watching
 *     one game is one request a minute, which is the same invariant the
 *     scoreboard holds.
 *
 * The lock is deliberately never released — it is allowed to expire — because
 * its purpose is to rate-limit, not to guard a critical section.
 *
 * One useful side effect: this is the ONLY source of historical athletes. The
 * roster endpoint publishes the current season only, so a 2021 player has no
 * roster row to have come from. Box scores name every player who took a snap,
 * which is how past seasons get populated at all.
 */
class SyncGameSummary
{
    private const THROTTLE_SECONDS = 60;

    public function __construct(private EspnClient $espn) {}

    /**
     * Refresh a game's summary if it is stale, respecting the throttle.
     *
     * Returns whether a fetch actually happened, so callers can tell "already
     * fresh" from "nothing to sync".
     */
    public function refresh(Game $game): bool
    {
        $summary = $game->summary;

        // A final game never changes. This short-circuit is what makes an
        // archived game page free.
        if ($summary !== null && ! $summary->isStale()) {
            return false;
        }

        $lock = Cache::lock("espn:summary:{$game->id}", self::THROTTLE_SECONDS);

        // Somebody fetched this game inside the window. Not an error — the
        // caller renders what is already stored.
        if (! $lock->get()) {
            return false;
        }

        return $this->handle($game);
    }

    /**
     * Fetch and store, bypassing the throttle. Used by the backfill.
     */
    public function handle(Game $game): bool
    {
        $body = $this->espn->site('summary', ['event' => $game->id], ttl: config('espn.cache.live'));

        if ($body === null || ! isset($body['boxscore'])) {
            return false;
        }

        $isFinal = (bool) data_get($body, 'header.competitions.0.status.type.completed', $game->completed);

        DB::transaction(function () use ($game, $body, $isFinal) {
            $this->storeTeamStats($game, $body);
            $this->storeScoringPlays($game, $body);
            $this->storePlayerStats($game, $body);

            GameSummary::updateOrCreate(
                ['game_id' => $game->id],
                [
                    'drives' => data_get($body, 'drives.previous'),
                    'win_probability' => $body['winprobability'] ?? null,
                    'leaders' => $body['leaders'] ?? null,
                    'attendance' => data_get($body, 'gameInfo.attendance'),
                    'is_final' => $isFinal,
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
        // with a sequence nobody reuses would linger forever.
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
