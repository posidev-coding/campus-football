<?php

namespace App\Services;

use App\Enums\Poll;
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
     * Which poll a rankings screen should open on.
     *
     * The CFP committee's rankings are what everyone actually argues about, but
     * they do not exist until week 11 — so AP leads until the first CFP poll of
     * the season appears, and CFP takes over from that moment on. Verified live
     * against 2025: week 10 has five polls, week 11 has six.
     */
    public function defaultPoll(?int $year = null): Poll
    {
        $year ??= $this->rankingsYear(Poll::Ap->value);

        return Cache::remember("calendar:default-poll:{$year}", self::CACHE_TTL, function () use ($year) {
            $seasonIds = Season::where('year', $year)->pluck('id');

            $hasCfp = $seasonIds->isNotEmpty() && Ranking::whereIn('season_id', $seasonIds)
                ->where('poll', Poll::Cfp->value)
                ->exists();

            return $hasCfp ? Poll::Cfp : Poll::Ap;
        });
    }

    /**
     * Polls that actually have rows for a season, in presentation order.
     *
     * @return list<Poll>
     */
    public function availablePolls(?int $year = null): array
    {
        $year ??= $this->rankingsYear(Poll::Ap->value);

        return Cache::remember("calendar:polls:{$year}", self::CACHE_TTL, function () use ($year) {
            // Spans season types — the preseason poll and final rankings live
            // outside the regular season.
            $seasonIds = Season::where('year', $year)->pluck('id');

            if ($seasonIds->isEmpty()) {
                return [];
            }

            $present = Ranking::whereIn('season_id', $seasonIds)->distinct()->pluck('poll')->all();

            // Major polls first, in their own order, then anything else.
            $ordered = collect(Poll::major())
                ->filter(fn (Poll $p) => in_array($p->value, $present, true));

            $rest = collect(Poll::cases())
                ->reject(fn (Poll $p) => in_array($p, Poll::major(), true))
                ->filter(fn (Poll $p) => in_array($p->value, $present, true));

            return $ordered->concat($rest)->values()->all();
        });
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

    /**
     * Every poll release for a season, newest first.
     *
     * A release is a (season type, week) pair rather than a week number, because
     * the polls span three season types and the numbers restart in each:
     *
     *   type 1 week 1    the preseason poll
     *   type 2 weeks 2-16
     *   type 3 week 1    the final rankings
     *
     * Keying a selector on week number alone would collide the preseason poll
     * with the final rankings, both of which are "week 1".
     *
     * @return list<array{week_id:int, label:string}>
     */
    public function rankingReleases(int $year, string $poll): array
    {
        return Cache::remember("calendar:releases:{$year}:{$poll}", self::CACHE_TTL, function () use ($year, $poll) {
            $seasons = Season::where('year', $year)
                ->whereIn('type', [Season::PRESEASON, Season::REGULAR, Season::POSTSEASON])
                ->get()
                ->keyBy('id');

            if ($seasons->isEmpty()) {
                return [];
            }

            $weekIds = Ranking::whereIn('season_id', $seasons->keys())
                ->where('poll', $poll)
                ->distinct()
                ->pluck('week_id');

            return Week::whereIn('id', $weekIds)
                ->get()
                ->map(fn (Week $w) => [
                    'week_id' => $w->id,
                    'type' => $seasons[$w->season_id]->type ?? Season::REGULAR,
                    'number' => $w->number,
                    'label' => $this->releaseLabel($seasons[$w->season_id]->type ?? Season::REGULAR, $w),
                ])
                ->sortByDesc(fn (array $r) => [$r['type'], $r['number']])
                ->map(fn (array $r) => ['week_id' => $r['week_id'], 'label' => $r['label']])
                ->values()
                ->all();
        });
    }

    /** The most recent poll release for a season, as a week id. */
    public function latestRankingRelease(int $year, string $poll): ?int
    {
        return $this->rankingReleases($year, $poll)[0]['week_id'] ?? null;
    }

    private function releaseLabel(int $seasonType, Week $week): string
    {
        return match ($seasonType) {
            Season::PRESEASON => 'Preseason',
            Season::POSTSEASON => 'Final Rankings',
            default => $week->name ?? "Week {$week->number}",
        };
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
