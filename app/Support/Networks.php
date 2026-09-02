<?php

namespace App\Support;

use App\Models\Network;
use Illuminate\Support\Facades\Cache;

/**
 * Every broadcast network's mark we hold, as ONE plain map.
 *
 * A scoreboard renders forty game cards and every caption asks for its
 * network's logo, so this is one cache read per request with a static memo
 * on top of it (the TeamGlance pattern, flushed in tests/Pest.php) — never
 * a query per card. Plain arrays only: a model in Redis round-trips as
 * `__PHP_Incomplete_Class` on the second request.
 *
 * The map holds only the networks ESPN has SENT ARTWORK FOR. FOX, CBS,
 * NBC, FS1 and BTN carried no logo in any ESPN feed measured on
 * 2026-09-02, so `mark()` answers null for them and the caller prints the
 * name — the name is the fact `games.broadcasts` holds, and nothing here
 * invents a picture to stand in for it.
 */
class Networks
{
    /** Versioned with the shape (services.md) — bump it when the array changes. */
    public const CACHE_KEY = 'networks:v1';

    public const TTL = 86400;

    /** @var array<string, array{logo: string, logo_dark: ?string}>|null */
    private static ?array $memo = null;

    /**
     * The mark for a network by the short name `games.broadcasts` stores —
     * "ESPN", "SEC Network" — or null when ESPN has never sent one.
     *
     * @return array{logo: string, logo_dark: ?string}|null
     */
    public static function mark(string $network): ?array
    {
        return self::all()[$network] ?? null;
    }

    /**
     * @return array<string, array{logo: string, logo_dark: ?string}>
     */
    public static function all(): array
    {
        return self::$memo ??= Remember::filled(self::CACHE_KEY, self::TTL, fn () => Network::query()
            ->whereNotNull('logo')
            ->orderBy('name')
            ->get(['name', 'logo', 'logo_dark'])
            ->mapWithKeys(fn (Network $network) => [
                $network->name => ['logo' => $network->logo, 'logo_dark' => $network->logo_dark],
            ])
            ->all());
    }

    /**
     * After the sync learns a mark. The day-long entry would otherwise keep
     * spelling "ESPN" in text until tomorrow.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$memo = null;
    }

    /** Test hygiene — the memo outlives the per-test application. */
    public static function flush(): void
    {
        self::$memo = null;
    }
}
