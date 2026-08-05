<?php

/*
 * ESPN endpoint map.
 *
 * Every path here was probed live against the API during planning. ESPN runs
 * three different hosts with three different response shapes, and which one
 * serves a given resource is not guessable — so they are named explicitly
 * rather than assembled ad hoc at the call site.
 */
return [

    'hosts' => [
        // Reference data, deep season-scoped resources, $ref pagination.
        'core' => 'https://sports.core.api.espn.com/v2/sports/football/leagues/college-football',
        // Denormalized, view-shaped payloads: scoreboard, roster, schedule.
        'site' => 'https://site.api.espn.com/apis/site/v2/sports/football/college-football',
        // Athlete-centric views: bio, gamelog, overview.
        'web' => 'https://site.web.api.espn.com/apis/common/v3/sports/football/college-football',
        /*
         * Article BODIES, and the only place they exist. The news list gives a
         * headline, a thumbnail and a link; `now/{espnId}` gives the story
         * itself. It is not under the college-football path — it is a
         * league-agnostic news host keyed on the article id alone. Verified
         * live over https (v3 called it over http) and it 404s on an unknown
         * id rather than returning an empty envelope.
         */
        'now' => 'https://now.core.api.espn.com/v1/sports/news',
    ],

    /*
     * Group ids are season-scoped in ESPN's tree, but these top-level nodes
     * have been stable. Verified for 2025: 99 (NCAA) -> 90 (Division I) ->
     * 80 (FBS) + 81 (FCS); 35 is Division II/III.
     */
    'groups' => [
        'ncaa' => 99,
        'division_i' => 90,
        'fbs' => 80,
        'fcs' => 81,
        'dii_diii' => 35,
    ],

    'classifications' => [
        80 => 'FBS',
        81 => 'FCS',
        35 => 'DII-III',
    ],

    'polls' => [
        'ap' => 'ap',
        'coaches' => 'usa',
        'cfp' => 'cfp',
    ],

    'http' => [
        // ESPN is generally fast; a slow response means something is wrong and
        // we would rather fail and retry than hold a worker open.
        'timeout' => (int) env('ESPN_TIMEOUT', 15),
        'connect_timeout' => (int) env('ESPN_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('ESPN_RETRIES', 3),
        'retry_delay_ms' => (int) env('ESPN_RETRY_DELAY', 250),

        /*
         * Requests per minute, enforced process-wide.
         *
         * v3 had no throttle at all: its Saturday schedule fired the games feed
         * every five minutes, each run issuing 70-110 sequential requests, on
         * top of one live ESPN call per page view per viewer. Roster and
         * athlete sync in Phase 2 is an order of magnitude larger again.
         */
        'rate_limit' => (int) env('ESPN_RATE_LIMIT', 240),

        'user_agent' => 'CampusFootball/1.0 (+https://campusfootball.net)',
    ],

    /*
     * Response cache TTLs in seconds, by volatility. Reference data barely
     * moves; a live scoreboard must not be cached at all.
     */
    'cache' => [
        'reference' => 60 * 60 * 12,
        'schedule' => 60 * 30,
        'live' => 0,
    ],

];
