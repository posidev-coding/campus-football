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
     * Deliberately NOT in config/espn.php and deliberately NOT fetched through
     * EspnClient. That client exists for ESPN's JSON APIs and their cost
     * tiers; a promo page has no business inside its rate limiter or its
     * User-Agent allowlist, and one request a week has no business being
     * counted against a budget sized for live scoring.
     */
    'feed_url' => env('GAMEDAY_FEED_URL'),

    /*
     * Short, because this call is on a scheduled command and never on a
     * request path. A promo page that hangs should cost the run, not the
     * window — the same reasoning as the Redis timeouts.
     */
    'timeout' => (int) env('GAMEDAY_TIMEOUT', 10),

];
