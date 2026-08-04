<?php

namespace App\Services;

use App\Enums\SeasonPhase;
use App\Models\Game;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The single source of truth for "where are we in the college football year".
 *
 * Earlier versions of this app kept a `calendars` table for this. That table is
 * not needed — `seasons` and `weeks` already carry real date ranges — but the
 * behaviour absolutely is, and without it every screen invented its own answer.
 * The scoreboard had one rule, the team page just read a config constant, and
 * they disagreed.
 *
 * Everything here derives from dates rather than from the `is_current` flags on
 * seasons and weeks. Those columns exist but the sync never populates them, so
 * they are all zero; a stored flag also goes stale the moment a scheduled job
 * misses a run, whereas a date range cannot.
 *
 * The distinction that does most of the work here is between:
 *
 *   currentYear()      the season we are chronologically in or heading into
 *   resultsYear()      the most recent season that actually has games
 *
 * In August these differ. A visitor wants next season's schedule but this past
 * season's standings and polls, and conflating the two is what leaves a
 * dropdown pointing at an empty screen.
 */
class CfbCalendar
{
    /**
     * How close to kickoff ESPN's six-month "preseason" starts feeling like an
     * actual preseason to a human.
     */
    private const PRESEASON_WINDOW_DAYS = 45;

    private const CACHE_TTL = 900;

    /**
     * Translate ESPN's season type into a phase a person would recognise.
     *
     * This translation exists because ESPN's type names are misleading.
     * Verified live for 2025:
     *
     *   1 Preseason      2025-02-01 -> 2025-08-23   (six months)
     *   2 Regular Season 2025-08-23 -> 2025-12-13
     *   3 Postseason     2025-12-13 -> 2026-01-21
     *   4 Off Season     2026-01-21 -> 2026-02-01   (eleven days)
     *
     * So ESPN's "Preseason" covers what most people call the offseason, and its
     * "Off Season" is only the short bridge after the playoff. Reporting either
     * label verbatim would tell a user it is "preseason" in March.
     *
     * Type 1 is therefore split by proximity to kickoff: close in, it really is
     * preseason; months out, it is the offseason.
     */
    public function phase(?CarbonImmutable $at = null): SeasonPhase
    {
        $at ??= $this->now();

        $season = $this->seasonContaining($at);

        if ($season === null) {
            return SeasonPhase::Offseason;
        }

        return match ($season->type) {
            Season::REGULAR => SeasonPhase::Regular,
            Season::POSTSEASON => SeasonPhase::Postseason,
            Season::OFFSEASON => SeasonPhase::Offseason,
            Season::PRESEASON => $this->splitPreseason($at, $season),
            default => SeasonPhase::Offseason,
        };
    }

    /**
     * ESPN's type 1 spans February to kickoff. Only its tail is a preseason in
     * any meaningful sense.
     */
    private function splitPreseason(CarbonImmutable $at, Season $preseason): SeasonPhase
    {
        // Its end date IS the next regular season's kickoff.
        $kickoff = $preseason->end_date;

        if ($kickoff === null) {
            return SeasonPhase::Offseason;
        }

        return $at->diffInDays($kickoff, false) <= self::PRESEASON_WINDOW_DAYS
            ? SeasonPhase::Preseason
            : SeasonPhase::Offseason;
    }

    /**
     * The season we are inside, or the one we are heading into.
     *
     * Falls back to the most recent season on record so this never returns null
     * for a database that has any seasons at all.
     */
    public function season(?CarbonImmutable $at = null): ?Season
    {
        $at ??= $this->now();

        return $this->seasonContaining($at)
            ?? $this->nextSeason($at)
            ?? Season::orderByDesc('year')->orderByDesc('type')->first();
    }

    public function currentYear(?CarbonImmutable $at = null): int
    {
        return $this->season($at)?->year ?? (int) config('cfb.season');
    }

    /**
     * The week we are inside, if any.
     *
     * Null is a legitimate answer: bowl season and the gaps between weeks are
     * not inside any week's range. v3 resolved this with `->first()->id` and no
     * null guard, so an off-calendar date killed the sync outright.
     */
    public function week(?CarbonImmutable $at = null): ?Week
    {
        $at ??= $this->now();
        $season = $this->seasonContaining($at);

        if ($season === null) {
            return null;
        }

        return Week::where('season_id', $season->id)
            ->where('start_date', '<=', $at)
            ->where('end_date', '>=', $at)
            ->first();
    }

    /**
     * The most recent season year that actually has games.
     *
     * This is what season dropdowns should default to on any screen showing
     * results — standings, rankings, completed scores — because the
     * chronologically current season may not have been played yet.
     */
    public function resultsYear(): int
    {
        return Cache::remember('calendar:results-year', self::CACHE_TTL, function () {
            $seasonId = Game::query()->max('season_id');

            return $seasonId
                ? (int) (Season::whereKey($seasonId)->value('year') ?? config('cfb.season'))
                : (int) config('cfb.season');
        });
    }

    /**
     * The week a scoreboard should open on for a given season.
     *
     * Prefers the week we are currently inside; otherwise the most recent week
     * that has games in it. Falling back to the highest week number lands a
     * visitor on an empty "nothing on the slate" screen out of season.
     */
    public function defaultWeekNumber(int $year): ?int
    {
        $season = Season::where('year', $year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return null;
        }

        $current = $this->week();

        if ($current !== null && $current->season_id === $season->id) {
            return $current->number;
        }

        return Cache::remember(
            "calendar:default-week:{$season->id}",
            self::CACHE_TTL,
            fn () => Week::query()
                ->where('weeks.season_id', $season->id)
                ->whereExists(fn ($q) => $q->selectRaw(1)->from('games')->whereColumn('games.week_id', 'weeks.id'))
                ->orderByDesc('number')
                ->value('number')
        );
    }

    /**
     * The most recent season year that has the given poll published.
     *
     * A season exists in the database months before any poll appears for it, so
     * selecting on year alone empties a rankings panel entirely.
     */
    public function rankingsYear(string $poll = 'ap'): int
    {
        return Cache::remember("calendar:rankings-year:{$poll}", self::CACHE_TTL, function () use ($poll) {
            $year = Season::query()
                ->whereIn('id', Ranking::where('poll', $poll)->distinct()->pluck('season_id'))
                ->orderByDesc('year')
                ->value('year');

            return (int) ($year ?? $this->resultsYear());
        });
    }

    /** The latest week number that has the given poll, for a season. */
    public function latestRankingsWeek(int $year, string $poll = 'ap'): ?int
    {
        $season = Season::where('year', $year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return null;
        }

        return Week::query()
            ->whereIn('id', Ranking::where('season_id', $season->id)->where('poll', $poll)->distinct()->pluck('week_id'))
            ->orderByDesc('number')
            ->value('number');
    }

    /**
     * A short human summary — "Week 5" during play, the phase otherwise.
     */
    public function label(?CarbonImmutable $at = null): string
    {
        $week = $this->week($at);

        if ($week !== null) {
            return $week->name ?? "Week {$week->number}";
        }

        return $this->phase($at)->label();
    }

    private function seasonContaining(CarbonImmutable $at): ?Season
    {
        return Season::query()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where('start_date', '<=', $at)
            ->where('end_date', '>=', $at)
            /*
             * ESPN's ranges touch at their boundaries — one type's end date is
             * the next one's start — so an instant on a boundary matches two
             * rows. Prefer the types that carry games, then the short offseason
             * bridge, and only then the six-month preseason, which is the
             * vaguest answer of the four.
             */
            ->orderByRaw('FIELD(type, ?, ?, ?, ?)', [
                Season::REGULAR,
                Season::POSTSEASON,
                Season::OFFSEASON,
                Season::PRESEASON,
            ])
            ->first();
    }

    private function nextSeason(CarbonImmutable $at): ?Season
    {
        return Season::query()
            ->whereNotNull('start_date')
            ->where('start_date', '>', $at)
            ->orderBy('start_date')
            ->first();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('cfb.timezone'));
    }
}
