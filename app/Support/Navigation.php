<?php

namespace App\Support;

/**
 * The app's information architecture, in one place.
 *
 * Two levels, and the split is deliberate:
 *
 *   AREAS are the bottom tab bar — a small, fixed set of places the app is
 *   "in". They never change as you move around inside one.
 *
 *   SECTIONS are the scrolling strip at the top, and they belong to the
 *   current area. They change completely when the area does.
 *
 * Before this, both navs listed the same nine sections, so the bottom bar and
 * the top strip were largely the same control rendered twice — and the header
 * above them spent 56px on a brand mark, a search icon and an avatar that the
 * tab bar can carry instead.
 *
 * Some overlap between the two levels is fine and sometimes intended: an area's
 * landing route usually appears as its own first section, because "Scores" is
 * both where the Scores area starts and a thing you navigate back to.
 *
 * Room is left for a sixth area. Pick'em is the product's point and will earn
 * the centre slot when it ships; the tab bar sizes itself from the count rather
 * than hardcoding five.
 */
class Navigation
{
    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     icon: string,
     *     route: string,
     *     routes: list<string>,
     *     sections: list<array{route:string, label:string}>,
     *     guest: bool
     * }>
     */
    public static function areas(): array
    {
        return [
            [
                'key' => 'home',
                'label' => 'Home',
                'icon' => 'home',
                'route' => 'home',
                'routes' => ['home', 'news'],
                'sections' => [
                    ['route' => 'home', 'label' => 'For You'],
                    ['route' => 'news', 'label' => 'News'],
                ],
                'guest' => true,
            ],
            [
                'key' => 'scores',
                'label' => 'Scores',
                'icon' => 'calendar-days',
                'route' => 'scoreboard',
                // A game belongs to Scores: you got there from a scoreboard and
                // that is the tab that should stay lit.
                'routes' => ['scoreboard', 'game'],
                /*
                 * No sections. Bowls and the playoff are entries at the end of
                 * the week scroller rather than a separate screen — ESPN
                 * publishes them as part of the same season and that is where
                 * a reader scrolls to find them. With one screen in the area
                 * the strip would be a single tab, which is chrome, not
                 * navigation.
                 */
                'sections' => [],
                'guest' => true,
            ],
            [
                'key' => 'league',
                'label' => 'League',
                'icon' => 'trophy',
                'route' => 'standings',
                // Teams, players and conferences are all "who's who", so they
                // keep League lit rather than dropping the tab bar's context.
                'routes' => [
                    'standings', 'rankings', 'teams', 'team', 'player',
                    'conference', 'stats', 'leaders', 'recruiting',
                ],
                'sections' => [
                    ['route' => 'standings', 'label' => 'Standings'],
                    ['route' => 'rankings', 'label' => 'Rankings'],
                    ['route' => 'teams', 'label' => 'Teams'],
                    ['route' => 'stats', 'label' => 'Team Stats'],
                    ['route' => 'leaders', 'label' => 'Player Stats'],
                    ['route' => 'recruiting', 'label' => 'Recruiting'],
                ],
                'guest' => true,
            ],
            [
                'key' => 'search',
                'label' => 'Search',
                'icon' => 'magnifying-glass',
                'route' => 'search',
                'routes' => ['search'],
                // One screen, so no strip. "When necessary" is the rule.
                'sections' => [],
                'guest' => true,
            ],
            [
                'key' => 'account',
                'label' => 'Account',
                'icon' => 'user-circle',
                'route' => 'account',
                'routes' => ['account'],
                'sections' => [],
                // Shown to guests too, pointing at sign-in — the tab bar is the
                // only navigation on a phone, so a signed-out visitor with no
                // Account tab has no way to sign in at all.
                'guest' => true,
            ],
        ];
    }

    /**
     * The area the current request is in, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function currentArea(): ?array
    {
        foreach (self::areas() as $area) {
            if (request()->routeIs(...$area['routes'])) {
                return $area;
            }
        }

        return null;
    }

    /**
     * Sections for the current area.
     *
     * Empty when the area has one screen, or when the request is outside every
     * area (auth screens, for instance) — the strip renders nothing rather than
     * showing another area's sections.
     *
     * @return list<array{route:string, label:string}>
     */
    public static function currentSections(): array
    {
        return self::currentArea()['sections'] ?? [];
    }

    /**
     * Where an area's tab points.
     *
     * Account resolves to sign-in for a guest, so the tab is never a dead end.
     */
    public static function href(array $area): string
    {
        if ($area['key'] === 'account' && ! auth()->check()) {
            return route('login');
        }

        return route($area['route']);
    }

    public static function label(array $area): string
    {
        if ($area['key'] === 'account' && ! auth()->check()) {
            return 'Sign in';
        }

        return $area['label'];
    }

    public static function isCurrent(array $area): bool
    {
        return request()->routeIs(...$area['routes']);
    }
}
