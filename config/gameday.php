<?php

return [

    /*
     * WHERE THE COLLEGE GAMEDAY LOCATIONS COME FROM.
     *
     * `promo.espn.com/collegegameday/` fetched as HTML returns only ESPN's
     * boilerplate footer — the page is JavaScript-hydrated, so an Http::get()
     * on it gets nothing useful. It hydrates from an `index.json` behind it
     * which carries two weeks of locations.
     *
     * THIS PATH WAS READ FROM A BROWSER'S NETWORK TAB, NOT DERIVED, so it is
     * pinned here rather than guessed at a call site. Unset means the feed
     * path is unconfigured, and `cfb:gameday` says so and stops — it does not
     * try a plausible URL and interpret the 404.
     *
     * NOTE THE `/2025/` IN THE PATH. The scaffold is reused season to season
     * and 2026 content is served from it, so the year there means nothing and
     * must not be computed. If ESPN ever does roll the path this 404s and
     * `cfb:gameday` reports it — at which point the fix is GAMEDAY_FEED_URL,
     * captured the same way this was, not a guess at the new year.
     *
     * Deliberately NOT in config/espn.php and deliberately NOT fetched through
     * EspnClient. That client exists for ESPN's JSON APIs and their cost
     * tiers; a promo page has no business inside its rate limiter or its
     * User-Agent allowlist, and one request a week has no business being
     * counted against a budget sized for live scoring.
     */
    'feed_url' => env(
        'GAMEDAY_FEED_URL',
        'https://a.espncdn.com/prod/styles/pagetype/otl/2025/college-gameday/json/index.json',
    ),

    /*
     * THE SHOW'S OWN SHIELD, for the Home card. Read off the same feed as
     * the locations — `schedule.dates[].events[].imageSrc` beside the alt
     * text "College GameDay logo" — and PINNED here the way `feed_url` is,
     * because that page is hand-maintained decoration GamedayFeed refuses to
     * believe at run time. Same `/2025/` scaffold, same rule: if it ever
     * 404s the fix is GAMEDAY_LOGO_URL captured from a browser, never a
     * computed year. Empty means no mark, and the card wears its tv glyph.
     */
    'logo_url' => env(
        'GAMEDAY_LOGO_URL',
        'https://a.espncdn.com/prod/styles/pagetype/otl/2025/college-gameday/img/static/index-logo.png',
    ),

    /*
     * Short, because this call is on a scheduled command and never on a
     * request path. A promo page that hangs should cost the run, not the
     * window — the same reasoning as the Redis timeouts.
     */
    'timeout' => (int) env('GAMEDAY_TIMEOUT', 10),

];
