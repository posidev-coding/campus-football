<?php

namespace App\Support;

use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What one reader's week looked like: their teams, what each did, what is next.
 *
 * Deliberately small. A newsletter that tries to be the app is a newsletter
 * nobody finishes, and the job of this one is to say "here is your team" and
 * send them back. Pick'em results arrive with pick'em.
 *
 * Built per USER rather than per team, because the fan-out is per user — one
 * bad row must not cost the other 299 their email. Within a user it keeps the
 * same discipline the home page does: one query per CONCERN across all their
 * teams, never one per team.
 */
class WeeklyDigest
{
    private const TEAM_COLUMNS = 'id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark';

    /**
     * The same columns, qualified, for the follow relation.
     *
     * `id` alone is AMBIGUOUS across the pivot join and MySQL rejects the query
     * outright — the constrained-eager-load trap in its other form. The columns
     * on a game's own homeTeam/awayTeam relations need no prefix, which is why
     * only this one is spelled twice.
     *
     * @var list<string>
     */
    private const FOLLOW_COLUMNS = [
        'teams.id', 'slug', 'location', 'display_name', 'short_display_name',
        'abbreviation', 'logo', 'logo_dark',
    ];

    /**
     * @return array{teams: list<array<string, mixed>>, since: Carbon, has_results: bool}
     */
    public static function for(User $user, ?Carbon $since = null): array
    {
        /*
         * A week back from the send, in the app's own timezone. Never UTC: a
         * UTC week boundary opens at 8pm Eastern the evening before, which puts
         * Saturday night's late kicks in the wrong digest — and only ever in
         * the evening, which is the hardest kind of bug to see.
         */
        $since ??= Carbon::now(config('cfb.timezone'))->subWeek();

        $teams = $user->followedTeams()->get(self::FOLLOW_COLUMNS);

        if ($teams->isEmpty()) {
            return ['teams' => [], 'since' => $since, 'has_results' => false];
        }

        $ids = $teams->pluck('id')->all();
        $with = ['homeTeam:'.self::TEAM_COLUMNS, 'awayTeam:'.self::TEAM_COLUMNS];

        $involvesAny = fn ($query) => $query
            ->whereIn('home_team_id', $ids)
            ->orWhereIn('away_team_id', $ids);

        /* What happened. Bounded by the window rather than by season, so a
           bowl in early January is still last week's news in early January. */
        $completed = Game::query()
            ->with($with)
            ->where('completed', true)
            ->where('kickoff_at', '>=', $since)
            ->where(fn ($q) => $involvesAny($q))
            ->orderBy('kickoff_at')
            ->get();

        /* What is next. NOT season-scoped, for the same reason the home page's
           pending query is not: in the offseason the next game belongs to a
           season that has not started counting yet. */
        $upcoming = Game::query()
            ->with($with)
            ->where('completed', false)
            ->where('kickoff_at', '>=', now())
            ->where(fn ($q) => $involvesAny($q))
            ->orderBy('kickoff_at')
            ->get();

        $records = TeamGlance::records();
        $ranks = TeamGlance::ranks();

        $rows = $teams->map(function (Team $team) use ($completed, $upcoming, $records, $ranks): array {
            $involves = fn (Game $game) => $game->home_team_id === $team->id
                || $game->away_team_id === $team->id;

            return [
                'team' => $team,
                'rank' => $ranks[$team->id] ?? null,
                /* records() returns a SHAPE — overall, conference, streak — not
                   a string. Handing the whole array to a template fatals on
                   htmlspecialchars, which is the same trap athlete display_stats
                   already paid for. Only the overall record belongs in an email. */
                'record' => $records[$team->id]['overall'] ?? null,
                'result' => $completed->last($involves),
                'next' => $upcoming->first($involves),
            ];
        })->all();

        return [
            'teams' => $rows,
            'since' => $since,
            /* Whether anybody played at all. The empty state is a different
               email, not an empty section — see Voice's mail.newsletter.empty. */
            'has_results' => $completed->isNotEmpty(),
        ];
    }

    /**
     * "Won 24-17 at Georgia" — one line a reader can take in without a table.
     */
    public static function describe(Game $game, Team $team): string
    {
        $isHome = $game->home_team_id === $team->id;

        $us = $isHome ? $game->home_score : $game->away_score;
        $them = $isHome ? $game->away_score : $game->home_score;
        $opponent = $isHome ? $game->awayTeam : $game->homeTeam;

        /* A tie is rare enough to be forgotten and real enough to be wrong —
           overtime rules make it nearly impossible in FBS, and not impossible
           everywhere else. */
        $verb = match (true) {
            $us > $them => 'Won',
            $us < $them => 'Lost',
            default => 'Tied',
        };

        return sprintf(
            '%s %d-%d %s %s',
            $verb,
            $us ?? 0,
            $them ?? 0,
            $isHome ? 'vs' : 'at',
            $opponent?->placeName() ?? 'TBD',
        );
    }
}
