<?php

namespace App\Support;

/**
 * Which rail panels a screen carries — the third level of the app's
 * information architecture, beside Navigation's areas and sections.
 *
 * Keyed by route rather than declared on each screen, for the same reason
 * Navigation is: "what sits beside /teams/{team}" is IA, not screen state, and
 * a reviewer should find every answer in one file. It is also the only shape a
 * test can SWEEP — RailTest walks the route table and fails on any screen
 * missing a key, so a new screen has to make the decision rather than
 * inheriting a rail nobody thought about. That is precisely what went wrong
 * before: the layout defaulted `rail` to true, no screen ever overrode it, and
 * all eighteen quietly carried the same Top 25 panel — including /rankings.
 *
 * An empty list means the screen renders FULL WIDTH: the layout emits no
 * <aside> at all rather than reserving a column and leaving it blank.
 *
 * Panels resolve their own context from the route (`request()->route('team')`
 * is already hydrated by route-model binding, at no extra query). There is
 * deliberately no `context()` method here — a panel needing something the
 * route cannot give belongs in the screen's own markup, not the shared rail.
 */
class Rail
{
    /**
     * Route name => rail panel components, in render order.
     *
     * @var array<string, list<string>>
     */
    private const MAP = [
        // ── With a rail ──────────────────────────────────────────────────
        'home' => ['rail.top-25', 'rail.leaders'],
        'scoreboard' => ['rail.top-25', 'rail.leaders'],
        // The rail persists across all five team-nav tabs, so the next
        // kickoff stays on screen while a reader is on Roster, Stats or News.
        'team' => ['rail.team-next', 'rail.top-25'],
        'stats' => ['rail.top-25'],
        // The sparsest screen in the app — a hero and one ruled list.
        'coach' => ['rail.top-25', 'rail.news'],
        // The strongest case anywhere: article prose self-caps at 68ch, so a
        // full-width article is ~590px of text sitting in a 1248px box.
        'article' => ['rail.news', 'rail.top-25'],
        // Search results are ordered by demand, so they cannot be split into
        // columns — the rail is the only honest use of the width.
        'search' => ['rail.top-25'],
        // Never top-25 here: that IS the screen. Full width would instead
        // stretch one 25-row table's name column across ~900px of nothing,
        // and this screen may not grow desktop-only columns.
        'rankings' => ['rail.this-week', 'rail.leaders'],

        // ── Full width, no aside emitted ─────────────────────────────────
        // game        in-content sidecar; the league sheet must stay a root sibling
        // standings   two-up needs 484px cells, which only full width gives
        // teams       a three-to-four column index wants every pixel
        // players     index plus an infinite-scroll sentinel
        // player      a variable-column game log inside stat-grid
        // conference  four distinct panels; in-content sidecar
        // recruiting  rows are the documented 516px-in-343px overflow case
        // news        the ideal three-to-four column grid
        // account     in-content two-column; the drag list stays one column
        // get-app     a walkthrough, not data
        'game' => [],
        'standings' => [],
        'teams' => [],
        'players' => [],
        'player' => [],
        'conference' => [],
        'recruiting' => [],
        'news' => [],
        'account' => [],
        'get-app' => [],
    ];

    /**
     * The panels for the current route, or [] for full width.
     *
     * @return list<string>
     */
    public static function panels(): array
    {
        foreach (self::MAP as $route => $panels) {
            if (request()->routeIs($route)) {
                return $panels;
            }
        }

        return [];
    }

    /**
     * Every route that has declared a rail decision — the completeness sweep
     * in RailTest reads this rather than the map itself.
     *
     * @return list<string>
     */
    public static function mapKeys(): array
    {
        return array_keys(self::MAP);
    }
}
