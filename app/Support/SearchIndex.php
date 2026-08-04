<?php

namespace App\Support;

use App\Models\Athlete;
use App\Models\Conference;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * Global search, shared by the ⌘K palette and the Search area's own screen.
 *
 * Entirely local: 854 teams, ~25,000 athletes and 115 conferences are all in
 * our own database, so this is one indexed query per group and never touches
 * ESPN. Search is the fastest interaction in the app and putting an external
 * dependency behind it would be the wrong trade.
 *
 * Matching is PREFIX-first. A leading wildcard cannot use an index, and across
 * 25,000 athletes that is the difference between an index range scan and
 * reading every row. "Geo" finds Georgia; "eorgia" deliberately does not.
 */
class SearchIndex
{
    /** Below this a query matches most of the database and is not useful. */
    public const MIN_LENGTH = 2;

    public static function tooShort(string $query): bool
    {
        return mb_strlen(trim($query)) < self::MIN_LENGTH;
    }

    /**
     * @return Collection<int, Team>
     */
    public static function teams(string $query, int $limit = 6): Collection
    {
        if (self::tooShort($query)) {
            return collect();
        }

        $term = trim($query);

        return Team::query()
            ->where(fn ($q) => $q
                ->where('display_name', 'like', $term.'%')
                ->orWhere('location', 'like', $term.'%')
                ->orWhere('nickname', 'like', $term.'%')
                ->orWhere('abbreviation', 'like', $term.'%'))
            ->orderBy('display_name')
            ->limit($limit)
            // slug is the Team route key; omitting it from a constrained select
            // breaks route() in a way that looks like a null relation.
            ->get(['id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark']);
    }

    /**
     * @return Collection<int, Athlete>
     */
    public static function players(string $query, int $limit = 6): Collection
    {
        if (self::tooShort($query)) {
            return collect();
        }

        $term = trim($query);

        return Athlete::query()
            ->where(fn ($q) => $q
                ->where('display_name', 'like', $term.'%')
                ->orWhere('last_name', 'like', $term.'%'))
            ->orderBy('display_name')
            ->limit($limit)
            ->get(['id', 'slug', 'display_name', 'short_name', 'headshot_url']);
    }

    /**
     * @return Collection<int, Conference>
     */
    public static function conferences(string $query, int $limit = 4): Collection
    {
        if (self::tooShort($query)) {
            return collect();
        }

        $term = trim($query);

        return Conference::query()
            ->where('is_conference', true)
            ->where(fn ($q) => $q
                ->where('name', 'like', $term.'%')
                ->orWhere('short_name', 'like', $term.'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'short_name', 'abbreviation', 'logo']);
    }
}
