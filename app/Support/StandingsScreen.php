<?php

namespace App\Support;

use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Standing;
use App\Services\CfbCalendar;
use Illuminate\Support\Facades\Cache;

/**
 * The standings screen's cached reads, written from both sides (CFB-130).
 *
 * The screen (resources/views/livewire/standings.blade.php) reads four
 * entries on a bare visit: whether the scoreboard season has published
 * standings, the season menu, the division's conferences and the rendered
 * row set. Each was a read-through that nothing refreshed, so the first
 * reader after every expiry paid for the query, and with the row set on a
 * 900-second TTL that reader was a frequent one rather than a rare one.
 *
 * The queries live here so the screen's reads and the writer-side warm put
 * the same value under the same key. The screen keeps its own Remember
 * calls (filled or orSource, never a bare Cache::remember), so a cold or
 * evicted cache is still correct, only slower.
 */
class StandingsScreen
{
    /** The row set's read-through TTL. */
    public const ROWS_SECONDS = 900;

    /** The menus' and the published flag's read-through TTL. */
    public const MENU_SECONDS = 3600;

    /**
     * How long a WARMED entry lives, TeamGlance::WARM_SECONDS's reasoning.
     *
     * A warm put with the read's own 900 seconds would expire fifteen
     * minutes after the nightly run and warm nobody. Standings only move
     * when a cfb:sync standings, compute or reconcile step writes them, and
     * each of those re-warms, so the entry outlives the gap to the next
     * write. A missed night lets it age out and the read rebuilds it cold.
     */
    private const WARM_SECONDS = 26 * 60 * 60;

    public const SEASONS_KEY = 'standings:seasons';

    public static function publishedKey(int $year): string
    {
        return "standings:published:{$year}";
    }

    /**
     * `v2`: the cached shape changed from a list of ids to id+label rows, and
     * a stale entry from before the change fatalled the live page while every
     * test passed on its fresh array cache. A shape change gets a new key,
     * never a coordinated cache:clear.
     */
    public static function conferencesKey(int $year, string $division): string
    {
        return "standings:conferences:v2:{$year}:{$division}";
    }

    /**
     * VERSIONED, the standings convention: a TTL outlives a deploy, so a
     * change to the shape OR to the order bumps the version and the old
     * entries age out unread instead of outliving the fix. v2 is the week-1
     * seeding fix in Standing::inStandingsOrder.
     */
    public static function rowsKey(int $year, string $scope): string
    {
        return "standings:screen:v2:{$year}:{$scope}";
    }

    /**
     * True when ESPN has published standings for the year, null when it has
     * not. Never false: Remember::filled must not pin "no rows yet" while a
     * standings sync is landing them.
     */
    public static function isPublished(int $year): ?bool
    {
        return Standing::fromEspn()->where('season_year', $year)->exists() ?: null;
    }

    /** @return list<int> */
    public static function seasons(): array
    {
        return Standing::query()
            ->distinct()
            ->orderByDesc('season_year')
            ->pluck('season_year')
            ->all();
    }

    /**
     * The division's conferences with published standings, read through
     * conference_seasons because classification is season-scoped.
     *
     * @return list<array{id:int, label:string}>
     */
    public static function conferences(int $year, string $division): array
    {
        return Conference::query()
            ->whereIn('id', ConferenceSeason::where('season_year', $year)
                ->where('classification', strtoupper($division))
                ->pluck('conference_id'))
            ->where('is_conference', true)
            ->whereIn('id', Standing::fromEspn()->where('season_year', $year)->distinct()->pluck('conference_id'))
            ->orderBy('name')
            ->get(['id', 'short_name', 'name'])
            ->map(fn (Conference $c) => ['id' => $c->id, 'label' => $c->short_name ?: $c->name])
            ->all();
    }

    /**
     * The screen's rows as PLAIN ARRAYS (a cached model is an
     * __PHP_Incomplete_Class on the second request); the screen rehydrates
     * them into light stubs for x-team-link's sake.
     *
     * @param  list<int>  $conferenceIds
     * @return list<array{standing: array<string, mixed>, team: array<string, mixed>|null, conference: array<string, mixed>|null}>
     */
    public static function rows(int $year, array $conferenceIds): array
    {
        return Standing::query()
            ->fromEspn()
            // `location` because the table renders placeName(). Omit it from
            // a constrained eager load and every team silently falls back to
            // its display name, which reads as a design decision rather than
            // a missing column.
            ->with(['team:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark', 'conference:id,name,short_name,abbreviation,logo'])
            ->where('season_year', $year)
            ->whereIn('conference_id', $conferenceIds)
            ->inStandingsOrder()
            ->get()
            ->map(fn (Standing $s) => [
                'standing' => $s->getAttributes(),
                'team' => $s->team?->getAttributes(),
                'conference' => $s->conference?->getAttributes(),
            ])
            ->all();
    }

    /**
     * Re-put what a bare visit to the screen reads, from the writer side.
     *
     * Called by every cfb:sync step that writes standings, once its write
     * has landed (TeamGlance::warmStandingPositions' hook). Zero ESPN
     * requests: four queries over tables the step just wrote.
     *
     * THE YEAR IS THE SCREEN'S, not the step's. The screen opens on the
     * scoreboard season the moment it has published standings and on the
     * results season until then, and the nightly writers run for the results
     * season, so warming the step's year would leave the preseason screen
     * cold. The fallback is applied here, outside the cache, exactly as the
     * screen applies it, so a fallback year cannot be pinned.
     *
     * Only the default scope (FBS) is warmed: it is where the overwhelming
     * majority of readers land, and every conference id would cost more than
     * it saves.
     *
     * NOTHING EMPTY IS PUT. A season with no standings rows warms nothing,
     * so a request racing the standings backfill still recomputes rather
     * than reading a pinned empty menu or screen.
     */
    public static function warm(CfbCalendar $calendar): void
    {
        $scoreboardYear = $calendar->scoreboardYear();
        $published = self::isPublished($scoreboardYear);

        if ($published !== null) {
            Cache::put(self::publishedKey($scoreboardYear), $published, self::WARM_SECONDS);
        }

        $seasons = self::seasons();

        if ($seasons !== []) {
            Cache::put(self::SEASONS_KEY, $seasons, self::WARM_SECONDS);
        }

        $year = $published ? $scoreboardYear : $calendar->resultsYear();
        $conferences = self::conferences($year, Scope::FBS);

        if ($conferences === []) {
            return;
        }

        Cache::put(self::conferencesKey($year, Scope::FBS), $conferences, self::WARM_SECONDS);

        $rows = self::rows($year, array_column($conferences, 'id'));

        if ($rows !== []) {
            Cache::put(self::rowsKey($year, Scope::FBS), $rows, self::WARM_SECONDS);
        }
    }
}
