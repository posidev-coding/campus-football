<?php

namespace App\Support;

use App\Models\Conference;

/**
 * Every conference's mark by its ESPN slug, as ONE plain map.
 *
 * `LobbyFlavor::conference()` names a room's conference through
 * `conferences.abbreviation` — ESPN's URL slug (`sec`, `big10`), the same
 * key SlateFilter resolves the slate through — and the lobby, My Picks and
 * the switcher each render a stack of rooms. So the lookup is one cache
 * read per request with a static memo on top (the TeamGlance pattern,
 * flushed in tests/Pest.php), never a query per row.
 *
 * Only conferences ESPN shipped a logo for are in the map. A slug with none
 * answers null and the caller keeps the mode tile: a picture nobody sent is
 * not a fact, and a substitute would be the wrong shield on somebody's room.
 */
class ConferenceMarks
{
    /** Versioned with the shape (services.md) — bump it when the array changes. */
    public const CACHE_KEY = 'conference-marks:v1';

    public const TTL = 86400;

    /** @var array<string, string>|null */
    private static ?array $memo = null;

    /** The mark for a slug, or null — for a null slug too, so a plain group reads as "no conference". */
    public static function logo(?string $abbreviation): ?string
    {
        if ($abbreviation === null) {
            return null;
        }

        return self::all()[$abbreviation] ?? null;
    }

    /**
     * @return array<string, string> abbreviation => logo
     */
    public static function all(): array
    {
        return self::$memo ??= Remember::filled(self::CACHE_KEY, self::TTL, fn () => Conference::query()
            ->whereNotNull('logo')
            ->whereNotNull('abbreviation')
            ->orderBy('id')
            ->pluck('logo', 'abbreviation')
            ->all());
    }

    /** Test hygiene — the memo outlives the per-test application. */
    public static function flush(): void
    {
        self::$memo = null;
    }
}
