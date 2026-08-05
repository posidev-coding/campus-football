<?php

namespace App\Support;

use App\Enums\Poll;
use App\Models\Game;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Week;
use Illuminate\Support\Facades\Cache;

/**
 * A team's ranking as it stood when a game was played.
 *
 * `games.home_rank`/`away_rank` hold ESPN's `curatedRank`, and that is the
 * number a card shows whenever it is there — it is ESPN's own statement about
 * the matchup, and `SyncGames` re-patches it on every pass, so it keeps up on
 * its own as polls move.
 *
 * It is just not always there. Measured: all 946 of 2026's games carry 99
 * ("unranked") on BOTH sides even though the Coaches preseason poll is out and
 * we hold all 25 rows. ESPN does not backfill a schedule when a poll lands —
 * re-fetching the scoreboard for week 1 still returns 99 — so without a
 * fallback the entire upcoming season shows no ranks at all.
 *
 * This fills that gap from rankings we already hold, using the same ladder ESPN
 * does. Checked against 2025 week 12, the stored value IS the CFP poll once the
 * CFP exists (ESPN 20/8/3/2, CFP 20/8/3/2, AP 19/7/3/2), so the two agree where
 * both are available and history is untouched.
 *
 * The rule, and it is deliberately about the GAME's moment rather than today:
 *
 *   1. Find the latest poll release published at or before kickoff.
 *   2. Within that release, take the best poll available — CFP, then AP, then
 *      Coaches. If it carries none of them, walk back a release.
 *   3. Look the team up in it. Unranked is null, never 99.
 *
 * Week 1 falls out of step 1 rather than needing a special case: no regular
 * week-1 release exists, so the latest one at or before kickoff IS the
 * preseason poll.
 *
 * POSTSEASON releases are excluded on purpose. ESPN files the AP and Coaches
 * "Final Rankings" under postseason week 1, whose range opens on Dec 13 — so a
 * bowl on Dec 20 would show a poll not published until January. Excluding them
 * leaves the last regular-season release, which is the CFP final and is exactly
 * what a bowl card should show.
 *
 * Costs one query per RELEASE, not per game or per card: a scoreboard week is
 * one lookup shared by fifty cards. Memoized on top of the cache, which is why
 * `flush()` exists and why `tests/Pest.php` calls it.
 */
class GameRanks
{
    private const CACHE_SECONDS = 900;

    /** @var array<string, mixed> */
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * Both sides of a game, ready for a card.
     *
     * ESPN's curated rank WINS wherever it exists. It is the number ESPN itself
     * put on the matchup, `SyncGames` re-patches it on every pass through
     * `fill()`/`isDirty()`, and on the seasons that have it the derivation below
     * only reproduces it. Deriving is what fills the gap it leaves.
     *
     * The choice is made per GAME, not per side. Mixing sources inside one card
     * — ESPN's number on one team and ours on the other — is how a one-rank
     * disagreement between two polls surfaces as what looks like a bug, and a
     * card is read as a single statement about a single matchup.
     *
     * @return array{home: ?int, away: ?int}
     */
    public static function forGame(Game $game): array
    {
        $curated = [
            'home' => self::sane($game->home_rank),
            'away' => self::sane($game->away_rank),
        ];

        // Either side curated means ESPN has ranked this matchup; a null on the
        // other side is a real "unranked", not a gap to fill in from elsewhere.
        if ($curated['home'] !== null || $curated['away'] !== null) {
            return $curated;
        }

        $release = self::releaseFor($game);

        if ($release === null) {
            return $curated;
        }

        $ranks = self::ranks($release['week_id'], $release['poll']);

        return [
            'home' => $ranks[$game->home_team_id] ?? null,
            'away' => $ranks[$game->away_team_id] ?? null,
        ];
    }

    /**
     * ESPN uses 99 for "unranked" rather than null, and a card must never print
     * it. Anything outside a poll's 25 is not a ranking.
     */
    private static function sane(?int $rank): ?int
    {
        return $rank !== null && $rank >= 1 && $rank <= 25 ? $rank : null;
    }

    /**
     * The release a game should be read against.
     *
     * @return array{week_id: int, poll: string}|null
     */
    private static function releaseFor(Game $game): ?array
    {
        $year = self::seasonYears()[$game->season_id] ?? null;

        if ($year === null || $game->kickoff_at === null) {
            return null;
        }

        $kickoff = $game->kickoff_at->getTimestamp();

        foreach (self::releases($year) as $release) {
            if ($release['starts_at'] <= $kickoff) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Every usable release for a season, NEWEST FIRST, one entry per week
     * carrying the best major poll that week has.
     *
     * @return list<array{week_id: int, poll: string, starts_at: int}>
     */
    private static function releases(int $year): array
    {
        return self::$memo["releases:{$year}"] ??= Cache::remember(
            "gameranks:releases:{$year}",
            self::CACHE_SECONDS,
            function () use ($year) {
                $seasonIds = Season::where('year', $year)
                    // Preseason and regular only — see the class note on why
                    // the postseason "Final Rankings" must not resolve here.
                    ->whereIn('type', [Season::PRESEASON, Season::REGULAR])
                    ->pluck('id');

                if ($seasonIds->isEmpty()) {
                    return [];
                }

                /*
                 * Which major polls each week carries. `distinct` on the PAIR
                 * rather than `pluck('poll', 'week_id')`, which would collapse
                 * a week's several polls down to whichever came back last.
                 */
                $byWeek = Ranking::query()
                    ->whereIn('season_id', $seasonIds)
                    ->whereIn('poll', array_map(fn (Poll $p) => $p->value, Poll::major()))
                    ->distinct()
                    ->get(['week_id', 'poll'])
                    ->groupBy('week_id')
                    ->map(fn ($rows) => $rows->pluck('poll')->all());

                if ($byWeek->isEmpty()) {
                    return [];
                }

                $starts = Week::whereIn('id', $byWeek->keys())
                    ->pluck('start_date', 'id');

                $releases = [];

                foreach ($byWeek as $weekId => $present) {
                    $start = $starts[$weekId] ?? null;

                    if ($start === null) {
                        continue;
                    }

                    foreach (Poll::major() as $poll) {
                        if (in_array($poll->value, $present, true)) {
                            $releases[] = [
                                'week_id' => (int) $weekId,
                                'poll' => $poll->value,
                                // Stored as an int: a Carbon in the cache comes
                                // back __PHP_Incomplete_Class on the SECOND
                                // request, never the first.
                                'starts_at' => $start->getTimestamp(),
                            ];

                            break;
                        }
                    }
                }

                usort($releases, fn ($a, $b) => $b['starts_at'] <=> $a['starts_at']);

                return $releases;
            }
        );
    }

    /**
     * team_id => rank for one release. Top 25 only.
     *
     * @return array<int, int>
     */
    private static function ranks(int $weekId, string $poll): array
    {
        return self::$memo["ranks:{$weekId}:{$poll}"] ??= Cache::remember(
            "gameranks:{$weekId}:{$poll}",
            self::CACHE_SECONDS,
            fn () => Ranking::query()
                ->where('week_id', $weekId)
                ->where('poll', $poll)
                ->where('rank', '<=', 25)
                ->pluck('rank', 'team_id')
                ->map(fn ($rank) => (int) $rank)
                ->all()
        );
    }

    /**
     * season_id => year, for resolving a game without eager-loading its season.
     *
     * A game card is rendered from six different screens, and requiring each to
     * remember a constrained eager load is exactly how a missing column ships.
     * `season_id` is already on the row.
     *
     * @return array<int, int>
     */
    private static function seasonYears(): array
    {
        return self::$memo['seasons'] ??= Cache::remember(
            'gameranks:seasons',
            self::CACHE_SECONDS,
            fn () => Season::pluck('year', 'id')->map(fn ($y) => (int) $y)->all()
        );
    }
}
