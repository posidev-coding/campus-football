<?php

namespace App\Support;

use App\Models\Game;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Turn a place and a date into a game we already hold — or into nothing.
 *
 * This is the whole safety story for College GameDay, and it is deliberately
 * the same story for both paths. The feed is hand-maintained and demonstrably
 * dirty: its own `map` block currently puts Norman, Oklahoma under an LSU
 * matchup, and its alt text names Ohio State beside an LSU logo. So being
 * first-party buys the feed nothing here. Whatever proposes a location — the
 * feed, the model, a backfill — our own `venues` and `games` decide whether it
 * is real.
 *
 * The match is `(city, state, the ET calendar day)`, and the date is
 * LOAD-BEARING rather than decorative: Austin on 2026-09-05 is Texas–Texas
 * State, while Austin on 2026-09-12 is Texas–Ohio State. Keyed on city alone
 * this lands on the wrong game in both directions.
 */
class GamedayResolver
{
    /**
     * The unique game at that place on that day, or null.
     *
     * NULL IS AN ANSWER. No match means the source contradicts our data and is
     * rejected; more than one means a city hosting a neutral-site game beside
     * a home team's, and a coin flip between them is worse than saying nothing.
     */
    public function resolve(string $city, string $state, CarbonInterface|string $saturday): ?Game
    {
        $city = trim($city);
        $state = mb_strtoupper(trim($state));

        if ($city === '' || $state === '') {
            return null;
        }

        [$start, $end] = $this->easternDay($saturday);

        /*
         * `kickoff_day` is a WEEKDAY NAME ("Sat"), not a date — so the day has
         * to come from `kickoff_at`, and it has to be read in Eastern. A
         * 00:30 UTC Sunday kickoff is a Saturday night game to everyone
         * watching it, and matching on the UTC date drops exactly the late
         * window GameDay's own broadcast leads into.
         *
         * Two rows fetched rather than one: the plan calls for ASSERTING the
         * match is unique, and `first()` cannot tell "the only game" from
         * "the first of several".
         */
        $games = Game::query()
            ->with('venue')
            ->whereHas('venue', fn ($venue) => $venue
                ->where('city', $city)
                ->where('state', $state))
            ->whereBetween('kickoff_at', [$start, $end])
            ->limit(2)
            ->get();

        return $games->count() === 1 ? $games->first() : null;
    }

    /**
     * The campus GameDay would be broadcasting from.
     *
     * Null at a neutral site, which is not a failure — GameDay goes to those
     * too, and the honest answer is a game with no host rather than the home
     * team of a game nobody is hosting.
     */
    public function hostTeam(Game $game): ?Team
    {
        return $game->neutral_site ? null : $game->homeTeam;
    }

    /**
     * `"Baton Rouge, LA"` and `"AUSTIN, TX"` are both real values from the
     * live feed on the same day, so casing is normalized rather than trusted.
     * Anything that is not `City, ST` returns null instead of a guess.
     *
     * @return array{city: string, state: string}|null
     */
    public function parseLocation(string $location): ?array
    {
        $parts = array_map(trim(...), explode(',', trim($location)));

        if (count($parts) !== 2) {
            return null;
        }

        [$city, $state] = $parts;
        $state = mb_strtoupper($state);

        if ($city === '' || ! preg_match('/^[A-Z]{2}$/', $state)) {
            return null;
        }

        // Title-cased for display; the MySQL comparison is case-insensitive
        // by collation, so this is about what lands on the home page.
        return ['city' => mb_convert_case(mb_strtolower($city), MB_CASE_TITLE), 'state' => $state];
    }

    /**
     * A CALENDAR DATE re-pinned to Eastern midnight, never an instant
     * converted into Eastern — the same distinction Cadence draws.
     *
     * `gameday_weeks.saturday` has a `date` cast, so it arrives as midnight
     * UTC. Converting that instant lands at 20:00 the PREVIOUS evening in
     * Eastern, and startOfDay() then hands back the wrong Saturday entirely.
     * Nothing throws; the admin override simply resolves to no game and looks
     * like our own schedule disagreeing with a city the human can see is
     * right.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function easternDay(CarbonInterface|string $saturday): array
    {
        $date = $saturday instanceof CarbonInterface ? $saturday->toDateString() : $saturday;
        $start = CarbonImmutable::parse($date, config('cfb.timezone'))->startOfDay();

        return [$start->utc(), $start->endOfDay()->utc()];
    }
}
