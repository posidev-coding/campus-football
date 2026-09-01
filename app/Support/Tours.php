<?php

namespace App\Support;

/**
 * The guided walks, as step lists.
 *
 * TWO WALKS, ONE COMPONENT. `home` is the app's first-run story, closing on
 * the install; `picks` is the economy's, added when Tallboys gained two
 * sinks and a cooler worth explaining. They share every line of spotlight
 * geometry in `livewire/tour.blade.php` and differ only in the stops, the
 * copy those stops resolve, and the column the finish stamps.
 *
 * The lists live HERE rather than on the component because the component is
 * an anonymous class inside a single-file view: nothing outside it can name
 * the constant, and a step list nothing can read is a step list nothing can
 * check. It is also the ONE source the view reads twice — Blade renders the
 * copy blocks by index and Alpine walks the spotlights by index, and those
 * two used to be typed separately, where a mismatch showed one stop's words
 * over another stop's highlight without erroring.
 *
 * A stop rides its list unconditionally. An anchor that is not on the page
 * — the pick'em teaser only wears `data-tour="room"` while the flag is open
 * — makes the stop step over ITSELF, which is exactly how a pre-flip tour
 * skips the beat.
 */
class Tours
{
    public const HOME = 'home';

    public const PICKS = 'picks';

    /**
     * Walk => its stops, in order. Each stop is both a `[data-tour]` key and
     * the `tour.{key}.heading` / `.body` Voice family, so adding one means
     * adding a target and three registers of copy.
     *
     * @var array<string, list<string>>
     */
    public const WALKS = [
        self::HOME => ['glance', 'search', 'scores', 'picks', 'room', 'wallet', 'league', 'account', 'install'],
        self::PICKS => ['week', 'seats', 'balance', 'room', 'how'],
    ];

    /**
     * The stops for a walk. An unknown name falls back to the app's own
     * rather than throwing: a bad prop should cost the reader the wrong
     * walk, never the screen.
     *
     * @return list<string>
     */
    public static function stepsFor(string $walk): array
    {
        return self::WALKS[$walk] ?? self::WALKS[self::HOME];
    }

    /** Whether this is a walk at all. */
    public static function known(string $walk): bool
    {
        return array_key_exists($walk, self::WALKS);
    }
}
