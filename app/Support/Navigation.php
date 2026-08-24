<?php

namespace App\Support;

use Laravel\Pennant\Feature;

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
 * The fifth area is Picks — the slot Search gave its tab up for, claimed ahead
 * of Pick'em shipping so the destination, the tour stop and the muscle memory
 * exist before the product does. The tab bar sizes itself from the count
 * rather than hardcoding it, which is what made the addition a one-entry
 * change.
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
     *     sections: list<array{route:string, label:string, routes?:list<string>}>,
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
                // News has no section strip to live in anymore, so its tab
                // lighting comes from being listed here; the screen itself is
                // reached through Home's "More" link. Search folded into Home
                // when it lost its tab — the bar at the top of Home IS the
                // search experience now, and /search survives only for deep
                // links and shared URLs.
                // `article` rides along so reading a story keeps Home lit, the
                // same way a game page keeps Scores lit. `get-app` is reached
                // from Home's install banner (and Account's row), so Home
                // staying lit is the honest answer to "where am I".
                'routes' => ['home', 'news', 'search', 'article', 'get-app'],
                /*
                 * No sections. "For You" and "News" made a two-tab strip whose
                 * first tab was the screen you were already on, and the search
                 * bar now claims that row of the viewport instead.
                 */
                'sections' => [],
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
                // The product's point takes the center of the five-tab bar:
                // Home and Scores keep their muscle-memory slots on the left,
                // League and Account stay right. "Picks" rather than
                // "Pick'em" — the short form is the nav label; the tab lands
                // on MY PICKS, and the product name lives on the screen.
                'key' => 'picks',
                'label' => 'Picks',
                'icon' => 'check-badge',
                'route' => 'pickem.home',
                // Only routes that RENDER belong here — the permanent
                // redirects (picks.groups, picks.group) never paint a nav
                // to light.
                'routes' => ['pickem.home', 'pickem.lobby', 'pickem.group', 'pickem.room', 'pickem.create', 'pickem.build', 'pickem.join', 'pickem.leaderboard', 'pickem.history'],
                /*
                 * Sections exist only inside the `pickem` flag: outside it
                 * the area is one coming-soon screen and a one-tab strip
                 * would be chrome, not navigation. History earns its slot
                 * because its prime moment — Sunday and Monday — is
                 * exactly when the lobby's inventory is emptiest.
                 *
                 * A room or group visit lights MY PICKS, not the Lobby:
                 * a reader inside one is a seated member playing, and the
                 * Lobby chip is for the browse. The one exception a chip
                 * cannot show is walking from the Lobby into a room you
                 * just joined — which is the moment you stopped browsing.
                 */
                'sections' => Feature::active('pickem') ? [
                    ['route' => 'pickem.home', 'label' => 'My Picks', 'routes' => ['pickem.home', 'pickem.group', 'pickem.room', 'pickem.create', 'pickem.build', 'pickem.join']],
                    ['route' => 'pickem.lobby', 'label' => 'Lobby'],
                    ['route' => 'pickem.leaderboard', 'label' => 'Leaderboard'],
                    ['route' => 'pickem.history', 'label' => 'History'],
                ] : [],
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
                    'standings', 'rankings', 'teams', 'team', 'players', 'player',
                    'coach', 'conference', 'stats', 'recruiting',
                ],
                /*
                 * Team Stats and Player Stats were two sections answering one
                 * question, which spent two of six slots and made "stats" a
                 * place you had to guess at. They are now one screen with a
                 * Team/Players sub-toggle, and the freed slot went to Players —
                 * a player index, which the app did not have at all.
                 */
                'sections' => [
                    ['route' => 'standings', 'label' => 'Standings'],
                    ['route' => 'rankings', 'label' => 'Rankings'],
                    // `routes` lights the section on detail pages inside it,
                    // the same way an area's `routes` lights its tab: a team
                    // page keeps Teams underlined.
                    ['route' => 'teams', 'label' => 'Teams', 'routes' => ['teams', 'team']],
                    // Same idea, and it fixes a real gap: `player` was in the
                    // area's routes but belonged to no section, so a player
                    // page lit League with the whole strip unlit.
                    ['route' => 'players', 'label' => 'Players', 'routes' => ['players', 'player']],
                    ['route' => 'stats', 'label' => 'Stats'],
                    ['route' => 'recruiting', 'label' => 'Recruiting'],
                ],
                'guest' => true,
            ],
            [
                'key' => 'account',
                'label' => 'Account',
                'icon' => 'user-circle',
                'route' => 'account',
                'routes' => ['account', 'notifications'],
                /*
                 * The first strip this area has ever rendered. Notifications
                 * live here rather than behind a header bell because below
                 * `sm` there IS no header — a bell would be unreachable on a
                 * phone, which is where the notifications were tapped from.
                 *
                 * Signed-in only: a guest has no inbox, and a one-tab strip
                 * is chrome rather than navigation.
                 */
                'sections' => auth()->check() ? [
                    ['route' => 'account', 'label' => 'Account'],
                    ['route' => 'notifications', 'label' => 'Notifications'],
                ] : [],
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
