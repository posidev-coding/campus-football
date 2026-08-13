<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A team's home stadium, inferred rather than stored.
 *
 * ESPN's team feed does not give us a venue, but the games table already
 * knows: the MODE of `venue_id` across a team's home games — with
 * `neutral_site` rows excluded, so bowls and kickoff classics never vote —
 * is their stadium. Verified against the live data: with the neutral filter
 * on, every sampled program maps to exactly ONE venue (Tennessee → Neyland
 * Stadium across 43 home games, no runner-up), so "most frequent" is not a
 * heuristic so much as a lookup with history as the index.
 *
 * Null when the games table has nothing to say (a team with no synced home
 * games). Callers fall back to the team's own name — never to a guessed
 * venue, per the no-defaults rule.
 *
 * Cached as a plain STRING for a day. `Remember::filled` rather than
 * `Cache::remember`, so an empty answer during a fresh-season backfill is a
 * moment, not a pinned fact: the null case recomputes per request, which is
 * one indexed query.
 */
class TeamVenue
{
    private const CACHE_SECONDS = 86400;

    public static function nameFor(int $teamId): ?string
    {
        return Remember::filled(
            "team:venue:{$teamId}",
            self::CACHE_SECONDS,
            fn (): ?string => DB::table('games')
                ->join('venues', 'venues.id', '=', 'games.venue_id')
                ->where('games.home_team_id', $teamId)
                ->where('games.neutral_site', false)
                ->whereNotNull('games.venue_id')
                ->groupBy('games.venue_id', 'venues.name')
                ->orderByRaw('COUNT(*) DESC')
                ->value('venues.name'),
        );
    }
}
