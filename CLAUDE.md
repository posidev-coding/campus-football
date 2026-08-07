<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v13
- laravel/pennant (PENNANT) - v1
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/sanctum (SANCTUM) - v4
- livewire/flux (FLUXUI_FREE) - v2
- livewire/flux-pro (FLUXUI_PRO) - v2
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>

# Campus Football

Fourth rebuild of a college football pick'em app. The v3 codebase is preserved
on the `production` branch; this is an orphan `v4` branch with no shared history.

## Non-obvious constraints

**Conference membership is season-scoped.** ESPN re-parents its group tree every
year. Never store a team's conference as a scalar — join through `team_seasons`.
Oregon is Pac-12 in 2021 and Big Ten in 2025; 513 teams changed conference
between those years. This single mistake is why standings were broken across
three versions.

**A team's ESPN "group" may be a division, not a conference.** Oregon's 2021
group is 54 ("Pac 12 - North", `isConference: false`) whose parent is 9
(Pac-12). `SyncTeams` resolves the parent — without it, conference standings
split into unrollupable halves and division restructuring looks like realignment.

**Never write a default when a feed returns nothing.** v3 defaulted standings to
zero on a lookup miss and overwrote 9-1 teams with 0-0. `EspnClient` returns
`null` for "no data"; callers must skip, not substitute.

**Read ESPN records and stats by NAME, never array position.** v3 indexed
`stats[0]`/`stats[1]` and broke whenever ESPN reordered.

**MySQL JSON does not preserve object key order.** A keyed stats map comes back
reordered. Store ordering separately in a JSON *array* (see
`athlete_game_stats.display_stats`).

**Constrained eager loads must include the route key.** `with('team:id,name')`
omits `slug` and makes `route('team', $team)` fail with "missing required
parameter" — looks like a null relation, is a missing column.

**Never cache Eloquent models.** They round-trip through Redis as
`__PHP_Incomplete_Class` and fail on the *second* request, not the first. Cache
plain arrays.

## ESPN API facts (all verified live, not assumed)

- Per-week game requests silently truncate. Use date ranges.
- A season-wide range decodes to ~92 MB and blows PHP's 128 MB limit. `SyncGames`
  uses overlapping 30-day windows.
- A single-game `summary` payload (523 KB) is LARGER than a whole day's
  scoreboard (440 KB / 25 games). There is deliberately no single-game sync.
- Odds ride along inline on the scoreboard for upcoming games — free. But ESPN's
  opening line is not available and completed games return `odds: null`, so line
  movement cannot be backfilled; we freeze our first observation as `open`.
- `gameQuality` is retrospective and absent on unplayed games. Tiering must use
  `matchupQuality`.
- Only the CURRENT roster is published. `?season=2025` returns zero athletes.
- Recruiting resolves only at `/recruiting/{year}/athletes` (404s on every
  obvious guess) — see the Recruiting section below, which is the one feed where
  the cost was self-inflicted.
- There is no NIL endpoint. `NilNewsProvider` filters team news by keyword.

## Recruiting: 27,000 prospects for 31 requests

The recruiting table held **25 prospects of one class** for months. Nothing was
broken; two assumptions were, and both are the kind that fail silently.

**`limit` caps at 1000, and asking for more gets you 25.**

    /recruiting/2026/athletes            count=5193  pageSize=25    pageCount=208
    /recruiting/2026/athletes?limit=1000 count=5193  pageSize=1000  pageCount=6
    /recruiting/2026/athletes?limit=2000 -> silently ignored, back to 25

That silent fallback is why `EspnClient::MAX_PAGE_SIZE` clamps rather than
trusting the caller: over the ceiling, "fetch everything" becomes "fetch the
first page" with no error to notice.

**Every collection item is ALREADY the whole document.** Diffed an item against
its own `$ref` payload: the key sets are identical, nothing is missing. So
following the ref cost one request per prospect and bought nothing —
`paginate()` now takes `inline: true` to skip it. A full class went from ~5,200
requests to **six**. All eight classes, 27,178 prospects: **31 requests**.

Twenty-three classes are published, **2006-2028**. We hold 2021-2028.

**`alternateId` links a prospect to the player they became**, and it is why
history is worth holding — but the rate is nothing like the top of a class
suggests. Measured against athletes we actually hold:

    2021   82% of the top 1000   51% of the whole class
    2024   89%                   41%
    2026   30%                    4%   (they have not enrolled yet)

### `schools[]` is an interest list, not a visit list

Only **659 of 10,472** entries in the 2026 class carry a date — 6%. Most rows
are "this school was in the running". Hence `recruit_schools`, and hence the
team page can show who a school recruited and lost.

Three traps in it, all measured:

- **Seven rows carry the year 2205** — an ESPN typo for 2025, and MySQL's
  `timestamp` tops out at 2038-01-19. The column is a `date` and the sync drops
  anything outside `[class-3, class+1]` rather than guessing the intended year.
- **The unique cannot be `(recruit_id, team_id)`.** That column is nullable for
  schools we do not carry, and MySQL never matches NULL to NULL in a unique
  index, so those rows were re-inserted on every weekly run — verified, one
  recruit went from 21 interest rows to 22. It is keyed on `espn_team_id`, which
  is never null and also records WHICH school it was.
- **An Undecided prospect's schools all share status id 0.** Matching the
  commitment on status id alone would pick one of their visits at random and
  call it a signing, so `committedTeamId()` bails on a falsy status.

### Recruiting has its own position vocabulary

It is NOT the roster's. Recruits are labelled `QB-PP` (pro-style, 224 in 2026),
`QB-DT` (dual-threat, 85), `OG`, `OC`, `OT`, `OLB`, `TE-H` — plain `QB` has
exactly **one** prospect. A position filter built from roster labels finds
nobody. `/recruiting` derives its position menu from the recruits themselves
and orders it by `positions.parent_id` (70 offense, 71 defense, 72 special),
because recruits carry no `position_group`.

### A class ranking cannot be an average, and cannot be a total

ESPN's `recruiting/{y}/rankings` is an empty shell — `{id, name: "ESPN Class
Rankings"}` with no entries, every sub-path 404 — so it must be derived, and
both naive answers are wrong on real data:

    average alone   a school with ONE 77-grade signee ranked 3rd nationally
    total alone     West Virginia's 71 signees (61.1 avg) outranked LSU's
                    class containing the nation's #1 prospect

`App\Support\RecruitingClasses` sums the **top twenty**, which is the size of a
real class and what a recruiting service actually does. ESPN lists 40-70
"commitments" per school; the walk-on tail must not move a ranking. One place,
shared by `/recruiting` and the team tab, so the two can never disagree about a
team's rank.

### Two more things that bit

- **A conference scope must not be resolved against the recruiting class.**
  `team_seasons` stops at the newest season we hold, so `Scope::teamIds('fbs',
  2028)` is an EMPTY array — not "everyone" — and it excluded every committed
  prospect in the 2027 and 2028 classes. The screen asks the DATA for the newest
  membership year at or before the class; `currentYear()` is the wrong question
  because it falls back to config when no seasons are loaded.
- **Uncommitted prospects are 6-12% of a class** and belong to no team, so a
  scope filter has to admit them explicitly — the same escape hatch the
  scoreboard gives an unannounced fixture.

Dead ends, so nobody re-probes them: `/recruiting/{y}/teams`,
`/recruiting/{y}/schools`, `/recruits/{id}/analysis`, `/recruits/{id}/notes` all
404; a team document carries no recruiting ref; and `attributes` exposes
`fortyYrdDash`, `threeConeDrill` and `twentyYrdShuttle` which are **all sentinel
99** — the same "number that means no data" as `curatedRank`.

## Sync cost tiers

SCORES cost ONE request per minute total, regardless of how many games are in
progress or how many people are watching — that one scoreboard payload carries
every live game's score, clock, period and status, which is also everything
pick'em scoring needs. Respect the tiers in `SyncGames` and
`routes/console.php`; v3 burst to ~20 requests/second.

    live 0-1 · today 1 · current 1 · recent 2 · season 9

BOX SCORES are the other half, and they do not ride the scoreboard —
`cfb:summaries:live` sweeps every in-progress game every two minutes, one
request per game (see the game-summary section). A 30-game Saturday peak is
~15 req/min on top of the tiers, comfortably inside the 240/min budget.

**That budget is OURS, not ESPN's.** `ESPN_RATE_LIMIT` defaults to 240 and no
ESPN document or observed 429 sets it — it was chosen as ~5x below v3's
known-bad burst rate. What ESPN *does* enforce is a User-Agent allowlist; see
below.

Scale-to-zero MySQL means writes are not free: sync only writes rows that
actually changed (`fill` + `isDirty`), and public reads are cache-first. The
summary sync carries the same discipline in `game_summaries.scoring_plays_hash`
— scoring rows are replaced wholesale, so an unchanged payload must skip the
rewrite or the sweep churns every row all Saturday. It is a HASH rather than a
count or a last-sequence check because ESPN issues corrections that rewrite an
existing play, which neither of those can see.

## ESPN 403s a custom User-Agent on the site host

Measured 2026-08-06, interleaved so ordering and rate effects are ruled out —
the result tracks the header, not the sequence:

    curl/8.7.1                          200
    GuzzleHttp/7                        200
    python-requests/2.31.0              200
    CampusFootball/1.0 (+https://...)   403
    foo/1.0                             403
    Mozilla/5.0 ... Chrome/131 ...      403

Their edge allowlists known HTTP-client agents and refuses everything else,
browser strings included. **Host-specific**, which is why it hid: `core` and
`web` served 200 to the custom agent throughout, so rankings, recruiting,
coaches and team stats all kept working while `site` — the SCOREBOARD and
SUMMARY feeds, which is to say games and box scores — returned nothing.

And it fails SILENTLY. A 403 is not retried (correctly: the request is not
wrong, and repeating it burns allowance), so the client logs a warning and
returns null, and "never write a default when a feed returns nothing" does the
rest — `cfb:games` reported `0 changed, 1 requests` and exited 0, all day.
`config('espn.http.user_agent')` is `GuzzleHttp/7`, which is what Laravel's
client sends when no header is set, and `ESPN_USER_AGENT` overrides it without
a deploy if their policy shifts again.

## Never hardcode the current season

`App\Services\CfbCalendar` is the single source of truth for where we are in
the football year. Do not read `config('cfb.season')` in a screen and do not
select "the latest season" — a season exists in the database months before it
is played, so both land the user on an empty page.

ESPN's four season types are all synced, and their names are MISLEADING —
verified live, do not trust the labels:

    1 Preseason      2025-02-01 -> 2025-08-23   six months
    2 Regular Season 2025-08-23 -> 2025-12-13
    3 Postseason     2025-12-13 -> 2026-01-21
    4 Off Season     2026-01-21 -> 2026-02-01   eleven days

So ESPN's "Preseason" covers what a person calls the offseason, and its
"Off Season" is only the bridge between the playoff and the next cycle.
`SeasonPhase` is our own vocabulary; type 1 is split by proximity to kickoff so
the app never claims it is preseason in March. Ranges abut, so an instant on a
boundary matches two rows — containment prefers the types that carry games.

    $calendar->phase()                 preseason|regular|postseason|offseason
    $calendar->currentYear()           the season we are in or heading into
    $calendar->resultsYear()           the latest season that HAS games
    $calendar->defaultWeekNumber($y)   current week, else last week with games
    $calendar->rankingsYear($poll)     latest season that has THAT poll
    $calendar->pollYear()              latest season with ANY major poll
    $calendar->defaultPoll($year)      first major poll that HAS rows
    $calendar->availablePolls($year)   polls with rows, majors first
    $calendar->rankingReleases($y,$p)  every release, newest first

## Rankings come from the CORE api, not the site one

The site rankings endpoint NEVER returns the CFP rankings — asking it for week
16 gives the same five polls as week 1, and its `type=` parameter is silently
ignored. Only `core/seasons/{y}/types/{t}/weeks/{w}/rankings` exposes them.

Poll keys are derived from ESPN's numeric ranking id, never its `type` field:
AFCA Division II (11) and Division III (12) both report `type: "afca"`, which
merged two polls into one key.

Poll availability is real business logic, verified live for 2025:

    AP / Coaches   preseason poll, weeks 2-16, then final rankings
    CFP            weeks 11-16 only
    CFP Seedings   week 16 only, 12 teams
    divisional     drop out entirely by week 16

A "release" is a (season type, week) pair, not a week number — the preseason
poll and the final rankings are both "week 1" of their own season type, so a
selector keyed on number alone collides them.

## A game card's rank is ESPN's, then ours, and always AS OF KICKOFF

`games.home_rank`/`away_rank` hold ESPN's `curatedRank` and are what a card
shows whenever they exist — `SyncGames` re-patches them on every pass, so they
keep up as polls move. Two things about them:

- **99 is ESPN's "unranked" sentinel.** `SyncGames` already maps it to null on
  write; readers must still guard, because a card printing "#99" is the tell.
- **They are not always populated.** All 946 of 2026's games carry no rank on
  either side while the Coaches preseason poll is out and we hold all 25 rows.
  ESPN does not backfill a schedule when a poll lands — re-fetching week 1
  still returns 99 — so re-syncing does not fix it.

`App\Support\GameRanks` fills that gap from rankings we already hold:

    1. latest poll release published at or before KICKOFF
    2. best poll in it — CFP, then AP, then Coaches; else walk back a release
    3. unranked is null, never 99

Week 1 needs no special case: there is no regular week-1 release, so the latest
one at or before kickoff IS the preseason poll.

**POSTSEASON releases are excluded deliberately.** ESPN files the AP and Coaches
"Final Rankings" under postseason week 1, whose range opens Dec 13 — so a bowl
on Dec 20 would show a poll not published until January. Excluding them leaves
the last regular-season release, which is the CFP final and is what a bowl card
should carry.

The two sources agree where both exist: checked against 2025 week 12, ESPN's
curated value IS the CFP poll (20/8/3/2) rather than AP (19/7/3/2), and all 61
games of that week render identically either way. Which is why the ladder above
is CFP-first — it is ESPN's own.

Resolved per GAME, not per side: mixing ESPN's number on one team with ours on
the other turns a one-rank difference between polls into what looks like a bug.
Costs one lookup per RELEASE, so a 50-card slate is one query, not fifty — and
it reads `season_id` and `kickoff_at` straight off the row rather than needing
`week` eager loaded, because a card renders from six screens and requiring each
to remember a constrained eager load is how a missing column ships.

**A poll's columns are not the same from poll to poll.** Measured over every
stored row, and it decides what `/rankings` can render:

    ap / coaches    points always, first-place votes on ~10% of rows,
                    previous_rank on ~85% (a preseason poll has none)
    cfp             ZERO points, ZERO first-place votes, previous_rank
    cfp-seedings    zero points, zero votes, and NO previous_rank either

So a fixed column set prints an empty column through the whole playoff race and
twenty-five consecutive "NR"s all summer. Rankings renders the movement column
only when some row has a `previous_rank`, decided from the collection it has
already fetched rather than by another query. First-place votes ride in the team
cell instead of a column of their own, because only a handful of teams in any
poll have any and an almost-empty column spends width the team name wants.

**Coaches lands BEFORE AP, and the default has to follow.** Verified live on
2026-08-05: the only poll ESPN published for the entire 2026 season was the AFCA
Coaches preseason (ranking id 2, `type: usa`) at type 1 week 1 — no AP at all.
So `defaultPoll()` returns the first MAJOR poll that actually has rows, in
`Poll::major()` order (CFP, AP, Coaches), rather than "CFP else AP". Naming a
poll with no rows opens the screen empty while a real published ranking sits
one option away in the dropdown — the same failure as a Top 25 filter with no
poll behind it.

Its year cannot come from `rankingsYear('ap')` either, which is circular: in
August that answers LAST season, so every screen defaulting through it opens on
the wrong year. `pollYear()` asks about any major poll instead.

**A preseason poll needs its WEEK row to exist first.** `SyncRankings::season()`
loops the weeks we hold, so with no type 1 week 1 in `weeks` it never asks for
the preseason poll and reports 0 records while ESPN is serving 25. ESPN
publishes that week only when the poll is near, so the seasons step has to run
again before rankings — `cfb:sync --only=seasons` then `--only=rankings`. Worth
remembering every August; nothing about the failure points at weeks.

The distinction between `currentYear()` and `resultsYear()` is the important
one: in August they differ, and conflating them is what empties a dropdown.

Everything derives from date ranges, not from the `is_current` columns on
seasons and weeks — those exist but the sync never populates them, and a stored
flag goes stale the moment a scheduled job misses a run.

## Mobile-first, always

Design at 390px first, then widen. Every breakpoint above base is ADDITIVE —
it may add a column, a rail, or a label, but it must never be the only place
something is reachable. The bottom nav was once `@auth`-gated while the header
links were `sm:hidden`, which left a signed-out visitor on a phone with no
navigation at all; that is the failure mode to avoid.

    base   single column, bottom nav, header nav hidden
    sm     header nav appears, bottom nav retires, cards go two-up
    lg     right rail appears ALONGSIDE content, never instead of it
    xl     third card column
    max    max-w-7xl (1280px), about a 14" laptop

Capped deliberately: past ~1280px line lengths stop being readable and the page
reads as a spreadsheet stretched across a monitor. Desktop should feel like a
traditional sports site — persistent section nav, dense multi-column content,
a standing right rail — not like a phone layout centred in whitespace.

Verify with the device harness rather than a resized window; Chrome will not go
below ~600px. See below.

## Navigation is two levels, and they are not the same list

    AREAS     the bottom tab bar. A small fixed set of places the app is IN.
              Home · Scores · League · Account. They do not change as you
              move around inside one.
    SECTIONS  the scrolling strip at the top, belonging to the CURRENT area.
              League shows Standings · Rankings · Teams · Players · Stats ·
              Recruiting. Home and Scores have none.

**No screen shows a visible heading.** Recruiting was the last holdout with a
`flux:heading`; the section strip already names every League screen, so an `h1`
said the same word twice and is `sr-only` everywhere. Scores remains the one
exception, because it has no strip.

Team Stats and Player Stats were once two sections, which spent two of six
slots on one idea and made "stats" a place you had to guess at. They are one
Stats screen with a Team/Players sub-toggle now, and the freed slot went to
Players — a player index the app did not have at all.

**A section's `routes` list is what lights it on a detail page.** `player` was
in the League area's routes but belonged to no section, so a player page lit the
League tab with the entire strip unlit — you could see you were in League and
not where. Any new detail route needs adding to its section's `routes`, not just
to the area's.

Both once listed the same nine sections, which made the top strip a second copy
of the bottom bar. `App\Support\Navigation` is the single source of truth for
both — add a route to an area's `routes` array or it will not light a tab.

A tab is lit by AREA, not by URL equality: a game page keeps Scores lit and a
player page keeps League lit. Comparing `request()->url()` to the tab's own href
lights up only on the area's landing screen.

**No screen shows a visible heading except Scores.** The section strip already
names every other screen, so an `h1` said the same word twice — it stays as
`sr-only`. Scores is the exception precisely because it has no strip: bowls and
the playoff live in its week scroller, leaving it alone in its area, so it
carries a real heading with the scope filter inline beside it.

Chrome above content went from 97-197px to 32-73px at 390px.

**Below `sm` there is no top bar at all** — 56px reclaimed. That is only safe
because every header affordance has a phone equivalent: brand → Home, ⌘K →
the search bar on Home, avatar → Account. Anything added to the desktop header
must get a phone route too, or it is unreachable at 390px. Log out and Admin
live on the Account screen for exactly this reason.

Pick'em gets the fifth tab when it ships — Search gave its tab up for exactly
that. The bar sizes its columns from the area count rather than hardcoding it.

## Home is the user's teams, swiped

One at-a-glance card per followed team — record, standing, trend pills, live
or next game, last result — in the order the user set on Account, the same
order the scoreboard floats them in. Native `scroll-snap` IS the animation: no JS tween, no
library; momentum scrolling is what feels buttery. An IntersectionObserver
sets the active index; the dots and the per-team news lists key off the same
`glances` array index, so they cannot disagree about which team is showing.

**Every followed team's news renders up front and Alpine toggles it** — at
most 5 teams × 5 articles. A Livewire round trip per swipe puts a visible
stall on the one interaction that has to feel instant.

**One query per CONCERN across all teams, never per card.** Completed games
(form + last result), pending games (live + next), and the news join are each
one query for all five teams; everything else comes from TeamGlance's cached
maps. `HomeTest` asserts the page issues the SAME number of queries for one
followed team as for five — that is the regression that matters once cards
multiply.

Two scoping rules that look wrong until August: form is scoped to the results
year (or it walks back through a decade of games), but pending games are NOT
season-scoped — in August the results year is fully complete and the next
game belongs to the season that has not started counting yet. The card polls
(`wire:poll.30s.visible`) only while one of the teams is actually live, and
reads only our own database.

The Pick'em teaser card is designed and deliberately INERT — the app should
read as a pick'em host from the first screen without promising a screen that
does not exist. It becomes the entry point when Pick'em ships.

**The last card is an empty SLOT until five teams are followed.** Onboarding
happens in place: a signed-in user with no teams gets a swiper holding one
add-card, searches, taps, and the slot becomes a real glance card. The
callout that sent them to Account is gone — it pushed people off the page
they were trying to fill. **The first team added also becomes the FAVORITE**
(`SetFavoriteTeam`, which follows as part of setting), because nobody picks
their one and only team and expects it not to lead the page.

That makes the swiper's card list DYNAMIC, which broke the original
IntersectionObserver: it captured `[...track.children]` once in `x-init`, so
a card added mid-session was never observed and the dots stopped tracking the
swipe. The observer now re-runs `observe()` on every `childList` mutation and
resolves the index from a live `children` lookup — `IntersectionObserver`
ignores a repeat `observe()`, so it stays idempotent. Anything that inserts
into an observed list needs the same treatment.

`TeamGlance::fbsTeams()` is the one FBS picker list, shared by Account's
follow search and Home's quick add so the two cannot drift.

## Search: three surfaces, one backend, and deliberately no FULLTEXT

Search is the bar at the top of Home (expands full-screen IN PLACE — never
navigate, because programmatic focus cannot raise the mobile keyboard; only
the input the user tapped keeps it up), the `/search` deep-link page, and the
desktop ⌘K palette.

That bar STICKS at `top-0` — below `sm` there is no header, so the top of the
screen is the top of the viewport and the offset needs no measuring. It cancels
the container's padding and re-applies it inside (`-mx-4 px-4`, `-mt-5 pt-5`)
so it has nothing to travel through, and `pb-3 -mb-3` gives content somewhere
to disappear without disturbing Home's `gap-6`. That last pair also nets the
wrapper to zero flow height while the panel is open, so the fixed overlay
leaves no residual gap behind it.

**It wears the layout header's own surface**, verbatim: `bg-white/85` with
`backdrop-blur` and a zinc `border-b`. Below `sm` that header is hidden, so on
a phone this bar IS the header — matching it makes them one object at two
widths instead of two pieces of chrome with different ideas, and gives Home a
formal top edge. Verified at both widths: at 390 the bar is 73px and the header
is 0px with no rule; at 768 exactly the reverse, with identical computed
background, blur and border color. Neutral throughout — the separation comes
from the rule and the blur, never from a tint or a brand color.

Translucency is safe HERE and was not on the scoreboard's day headings, which
is worth keeping straight: this sits at z-30, above card contents at z-10, so
it wins on z-index and the blur is decoration. A day heading tied at z-10 and
lost on tree order, and no amount of opacity fixed that.

**But the panel is a `fixed` child of that bar, and every class that makes the
bar a header breaks it.** All three come off while it is open
(`:class="{ 'sticky z-30 backdrop-blur': ! open }"`), and each one fails in its
own way:

    backdrop-blur   a backdrop-filter is the CONTAINING BLOCK for fixed
                    descendants, exactly like transform and filter. `inset-0`
                    resolved against the 33px bar, so full-screen search opened
                    as a 390x32 strip with Home still live underneath
    z-30            a stacking context CAPS the panel's z-50 at 30, under the
                    tab bar at z-40
    sticky          opens a stacking context at `z-index: auto` as well, which
                    `relative` does not — so dropping to z-auto fixed nothing

That last one is the surprise, and it REFINES the note above about
`position: relative` with `z-index: auto` creating no stacking context: sticky
is not the same, it always creates one. Verified with an isolated pair of fixed
divs rather than reasoned about — a z-50 child of a `sticky; z-index: auto`
wrapper loses to a plain z-40 sibling.

Object syntax rather than a ternary, because those classes are also in the
static `class` attribute: Alpine's `setClassesFromObject` removes a class
whatever put it there, so the server still renders a dressed bar and there is
no flash before Alpine boots. Only those three toggle — moving the padding too
would shift the page 32px on every open. All three read `App\Support\Search`, which is Laravel
Scout on the DATABASE engine — the data is already in our MySQL, so search
queries source tables and there is no index to sync or drift.

**No MySQL FULLTEXT, and that is a decision, not an omission.** An InnoDB
full-text index cannot see rows inserted inside an uncommitted transaction —
which is every row a RefreshDatabase test creates — so a full-text arm passes
in production while being unprovable in the suite. LIKE strategies test
honestly. At our sizes only `athletes` (34,836 rows) needs indexes at all:
its strategy is `SearchUsingPrefix` on `display_name` + `last_name`, riding
btrees. Everything else contains-matches, which is not a compromise — it is
required: `games.name` is "Alabama at Georgia" so the away team is
unreachable by prefix, and `games.note` is the real bowl name where the word
someone types is rarely the first.

**Relevance is domain knowledge, not text statistics**, and lives in each
group's `->query()` callback: live > upcoming > finished and nearest-to-now
for games; active players above departed ones, then latest season; FBS teams
first; `is_conference` first (only 79 of 118 rows are real conferences);
current coaches above historical. Ranked teams float by a PHP re-sort of the
fetched page against `TeamGlance::ranks()` — every ranked team is FBS, so the
SQL order has already pulled them into the page.

**Result rows are rich but factual** — search serves Scores and League, so
only the empty state speaks through `Voice`. Rank is a small muted numeral
BESIDE the team name, never a subtext segment. Hometown gets its own micro
line, never another `·` segment — it is the first thing truncation eats, and
only about half of athletes and coaches have one, so every row must read
right without it.

`App\Support\TeamGlance` holds the cached glance maps (records, standings
position, conference names, ranks, conference sizes) as PLAIN ARRAYS — one
query per map over the whole league, never per row. It memoizes in a static
property on top of the cache, which outlives each test's application;
`tests/Pest.php` flushes it in `beforeEach`.

## The Game screen is one shell in three states, and the first tab IS the state

    pre    Preview  — odds, matchup predictor donut, comparison bars, last
                      five, season leaders, last meetings, game information
    live   Live     — situation on the scorebug, win probability, drive feed
    final  Recap    — line score, recap article, game leaders, probability
                      swing, related reading

Box · Scoring · Drives · Odds ride behind whichever leads, each offered only
when its data exists. `$tab` is `#[Url]`; mount() AND poll() normalize it, so
`?tab=live` bookmarked mid-game resolves to Recap after the whistle instead of
an empty pane.

**A game that has not kicked off has exactly ONE tab, so it draws no strip at
all** — the pregame screen is a single scroll in the order above. Odds LEAD it:
the line is the one number a reader checks before kickoff whether or not they
bet, and a two-item strip whose second item is one table charges a tap for
something the page can just show. `partials/game-odds` takes `standalone`,
false when folded in, which drops its quality table (the donut two cards below
already prints matchup quality) and its empty state (the preview owns the
apology, and that apology must check for ODDS too — printing "nothing yet"
above a posted spread is the same mistake as an empty state above a followed
team's game). Odds keep their own tab from kickoff on, where the scroll belongs
to the box score. The game-information card is the parent's, rendered once at
the foot of every state — the preview must never grow its own.

Rules the screen keeps, each one paid for:

- **Drives are read ONLY while a tab showing them is active** — a computed
  gated on `$tab`. game_drives is ~306 KB a row in its own table precisely so
  a page view does not read it; `GamePageTest` asserts the recap tab issues
  zero `game_drives` reads. `hasDrives` (an exists() on the PK) is what offers
  the tab.
- **The scorebug links both teams.** Cards send every tap to the game because
  the team links live HERE — `LinkingTest` enforces it, and it is the first
  thing a redesign quietly drops.
- **The sheet is a sibling of the scorebug, never a child** — the scorebug's
  backdrop-blur is a containing block for fixed descendants (the search-panel
  lesson), and would cap the z-50 sheet at the scorebug's own size.
- **Around the League claims each game once**: followed → Top 25 (via
  GameRanks) → this game's conference(s) via season-scoped team_seasons →
  rest of the ET day, computed only while the sheet is open. Drag-to-dismiss
  and the entrance spring run through `element.animate()` (nothing inline for
  a morph to strand); `x-trap.noscroll` does focus trap and scroll lock in
  one; multi-statement Alpine lives in x-data METHODS.
- **`SyncPredictors` is upcoming-only and that is a one-way door**: ESPN
  serves predictors for unplayed games only, so a projection not captured
  before kickoff never exists. Wed/Thu/Sat-morning passes are the capture;
  `CoverageReport` watches the coming 10 days. The row also keeps
  `pred_pt_diff` (projected margin) and opponent-strength ranks;
  `teamChanceLoss` is the projection's complement and is derived, never
  stored.
- **The live situation clears when a game LEAVES the in state, and only
  then.** A final must not wear a frozen "3rd & 7", but a live payload
  omitting the block is a transient gap — nulling real data over it is the
  default-writing mistake. Possession ids obey the non-positive rule.

### The donut: both arcs leave top dead centre, and nothing animates

Home sweeps clockwise down the right, away is the same arc MIRRORED
(`translate(120,0) scale(-1,1)`) so it leaves the same point going the other
way and runs down the left. Each team's color therefore sits under its own
logo, and the split is at twelve o'clock whatever the numbers say. Two
earlier shapes were wrong: drawing away first put its color on the RIGHT
under the home logo, and starting the second arc where the first ended fixed
the colors but let the origin wander with the split.

Round caps EXTEND a dash by half the stroke width at each end, so the offset
that yields a visible gap of G between neighbouring ends is `G/2 + stroke/2`,
applied at both the twelve o'clock split and the bottom meeting point. A
plain `- $gap` produces no gap at all.

**It is drawn STATIC, and that is the load-bearing part.** Two entrance
animations were tried and both could render an EMPTY ring: an Alpine flag
flipped from `requestAnimationFrame`, and a CSS keyframe animating from a
zero dasharray. Measured in a real browser, `getAnimations()[0]` reported
`playState: "running"` with `currentTime` frozen at 0 across seconds — so the
arcs held their from-state indefinitely and the card showed nothing. This is
the same no-frames condition documented for the automated tab, and the rule
it teaches is general: **a flourish whose stalled state hides the content is
not decoration.** Animate only where the un-animated state is the finished
one.

### Chart marks: team colors in light, neutral in dark, resolved as a PAIR

`TeamPalette::chartColors(away, home)` — the donut and comparison bars draw
in team colors in light mode, and BOTH pairwise failure modes are real: a
near-white brand vanishes into the page, and two red teams merge into one
ring. The away side keeps its primary; the home side yields — its own
secondary first (Alabama gray beside Georgia red is truer than a shifted
red), then a lightness shift, then a neutral that always reads. Dark mode
un-brands both through the `chart-pair` utility (zinc vs accent), the same
rule as every branded surface — and color is never the only distinguisher,
because every mark carries its team's abbreviation. Floors: 2.0 against the
page, 1.25 between the marks; the tests assert RATIOS, not which hex was
picked.

## `games.name` is never the bowl name

It only ever holds "A at B". The event's real name — "Rose Bowl Presented by
Prudential", "College Football Playoff National Championship" — is
`competitions[0].notes[0].headline`, and we discarded it until it was needed.
Verified live: 41 of 41 postseason events carry one, and the 11 playoff games all
begin "College Football Playoff", which is the ONLY way to tell a playoff game
from any other bowl. A heuristic on `name` matches nothing at all.

Stored as `games.note`; `Game::playoff()` and `Game::bowlsOnly()` read it.

## The postseason is one ESPN week, shown as two

`types/3/weeks` returns a single item called "Bowls" spanning Dec 13 to Jan 21
and holding all 46 games. The scroller splits it into **BOWLS** and **CFP**, and
each pill dates itself from its own games — using the shared week would put
"DEC 13" on a playoff that starts a week later.

Both pills share one `week_id`, so the scoreboard keys selection on the PAIR
(`week` + `bracket`). Setting the id alone leaves a stale bracket showing the
wrong half.

There is no `/bowls` route. Note the consequence, which is deliberate but worth
knowing: Scores has no season selector, so **historical** bowls are reachable
only through a team's schedule or a direct game URL, not by browsing.

## An unannounced fixture has a NEGATIVE team id

ESPN publishes every bowl and playoff game months ahead as "TBD at TBD", and it
does not use a null competitor to say so — it sends a real competitor whose team
id is **-1** (home) and **-2** (away), named "TBD". Conference championships are
the same until their standings resolve.

`games.home_team_id` is `mediumint unsigned` with a foreign key, so storing that
verbatim **throws**. Map any non-positive id to null: the column is nullable and
`x-team-link` already renders a null team as "TBD", so the fixture keeps its
date, venue and bowl name and only the matchup is blank — which is exactly what
the schedule is at that point. Same rule as the box-score pseudo-athletes: ESPN
uses non-positive ids for things that are not real entities.

**What made this expensive was the lack of isolation.** The throw aborted the
whole scoreboard request, so every event behind it in the payload was lost —
the 2026 season silently stopped at the first conference championship on Dec 4
and not one of its 43 bowl and playoff games was ever stored. The per-event
`try/catch` in `SyncGames::range()` is what stops one bad game costing a season;
treat a loop over a payload the same way the job fan-out treats a loop over
teams.

**A scope filter must not swallow them.** `Scope::teamIds()` matches on teams, so
a TBD fixture matches nothing and the entire postseason vanishes for the eleven
months when the date and venue are the only things on offer. The scoreboard adds
`orWhere(home IS NULL AND away IS NULL)` — a fixture with no teams cannot be
excluded on the basis of its teams. That is an escape hatch for UNANNOUNCED
games only; a real matchup outside the scope still filters out.

## `wire:sort` takes a bare METHOD NAME, never a call expression

`wire:sort="reorder($item, $position)"` looks more explicit and sends NULLs.
Livewire's `contextualizeExpression()` rewrites every identifier that is not in
the element's own Alpine scope to `$wire.<ident>` — and the `$item`/`$position`
magics arrive as an evaluator OPTION, not as element scope, so they are
rewritten too. The call became `$wire.reorder($wire.$item, $wire.$position)`,
both `undefined`, and the server rejected a null team id with
"Argument #1 ($teamId) must be of type int, null given".

Correct is `wire:sort="reorder"`; Livewire passes the moved item and its new
**0-based** index itself. Two things this cost:

- **Only a real pointer drag reaches it.** SortableJS ignores synthetic
  pointer and mouse events, so no automated interaction test can reproduce
  it — `AlpineExpressionsTest` asserts the rendered ATTRIBUTE is a bare method
  name instead, which is the only layer a test can hold.
- The item id arrives as a STRING (`_x_sort_key` is whatever the attribute
  held), which PHP coerces for an `int` parameter. Fine for numeric ids, and
  worth knowing before typing a handler `string`.

## Reordering needs a FLIP, and it must use `animate()`

The followed-teams list puts the pinned team first, so pinning a lower row
reorders it. Order is not an animatable CSS property, so a Tailwind transition
cannot do this on its own — the list just snaps.

The fix is a FLIP: record each row's offset BEFORE the click goes out (capture
phase, so nothing has moved yet), then once Livewire has reordered the DOM, put
each row back where it was and let it travel to where it now belongs.

Two things that bit, both from Livewire's morph:

- **Consume the captured positions.** The MutationObserver fires more than once
  per update. A second pass measures a row that is already mid-flight, reads a
  delta of zero, and returns early — leaving the row frozen at its full offset.
- **Use `element.animate()`, not a transform cleared on the next frame.** The
  morph can replace a row between setting the transform and the frame that
  clears it, so the cleanup runs against a detached node and the transform is
  stranded in the inline style forever. `animate()` leaves no inline style at
  all, so there is nothing to strand.

Verify the END state, not the tween — animations do not advance in an automated
tab (`currentTime` stays 0). Call `getAnimations().forEach(a => a.finish())` and
assert the transforms are `none` and no `style` attribute survives.

## The voice: `ContentRating` drives copy, and it is not decoration

This app is meant to be **fun, funny, and a bit of a wind-up**. That is a
product requirement, not a coat of paint applied at the end. A pick'em app that
reads like a spreadsheet has already lost to the group chat it is competing
with.

So `$user->content_rating` is not just a flag for generated taunts — it is the
register the whole interface speaks in. **Wherever there is copy with a
personality budget, write all three versions when you write the screen**, not
later: descriptions, subtext, empty states, button labels, confirmations,
tooltips, error messages, instructional text, notifications.

    PG     Mild           clean, still warm — never limp
    PG-13  Locker Room    the default; how the group chat actually talks
    R      Anything Goes  unfiltered, for the people who asked for it

### Where it applies, and where it must not

    LOUD   Account · Pick'em · Gamification · Groups · Notifications
           Anything about YOU, your picks, your record, your rivals.

    PURE   Scores · League (standings, rankings, stats, leaders, teams,
           players, recruiting, news)
           Someone checking a score wants the score. A joke between a reader
           and a fact is friction, and it makes the data look less trustworthy
           — which is the one thing this app cannot afford, given three
           rebuilds went wrong on data.

The line is not "serious vs silly", it is **whose content it is**. A scoreboard
reports what happened. A pick'em screen is talking TO somebody about what they
did, and that is where the voice belongs. Chrome that frames factual screens —
an empty state, an onboarding hint — can still carry personality; the facts
themselves stay untouched.

### Rules the voice does not get to break

- **Roast the pick, the team, the record — never the person.** Already stated
  on the enum, and it is what keeps this funny instead of a liability. It is
  also what keeps the mobile build inside its App Store age rating.
- **PG is not "the boring one".** If the PG variant reads like documentation
  while PG-13 is the only one with jokes, PG has been written as a punishment.
  Every level should feel like it was written on purpose.
- **Never let the joke eat the instruction.** If a user cannot tell what a
  control does after reading the funny version, the funny version is wrong.
- **Fall DOWN the ladder, never up.** `ContentRating::includes()` already
  encodes this: an R user can be shown PG copy, a PG user must never see PG-13.
  Missing copy at a level resolves downward.

### The resolver

`App\Support\Voice::line($key, $replace, $for)`. Copy lives in one map so all
three variants of a line sit side by side — which is how you catch PG being
written as a punishment. Resolution walks `includes()` in reverse and takes the
first level that exists, so a line defining only `pg` is safe to add and a line
defining only `r` never reaches anyone who did not ask for it. Unknown key
returns `''`, never the key.

Account is done and is the reference implementation. Note what was deliberately
left alone there:

- **the search placeholder** — an affordance, read every time the field is
  empty; the AT-LIMIT message beside it does speak, because that one is about
  something the user just did
- **the handle format rule** — "lowercase letters, numbers and underscores" is
  where a joke would eat the instruction
- **field labels and section headings** — people navigate by them

**Copy does not belong in exceptions.** `FollowLimitReached` carries a
developer message for logs; what the user reads comes from `Voice`, because a
string baked into an exception can only ever speak in one register.

## Identity: first/last name, a handle, and a content rating

Registration collects **first and last name separately**, a **handle**, and a
**content rating**; all four are editable from Account. There is no `name`
column — `$user->name` is an accessor over the two halves, which is why nothing
that printed a user had to change.

**Handle, not username.** It is the sport's own vernacular, and it sets the
expectation that this is the name you are shouted at by rather than a login
credential. Unique, and case-insensitively so: the column's
`utf8mb4_unicode_ci` collation makes the unique index reject `@Taylor` when
`@taylor` exists, which is the confusion a handle is for preventing. On edit the
rule needs `Rule::unique(...)->ignore($user->id)` or saving any other field
fails against your own row.

**Mask the handle on the CLIENT, validate on the server.** Livewire will not
overwrite a focused input — that is what stops it clobbering your typing — so a
server-side clean leaves the visible text disagreeing with the stored value
until blur. `x-mask:dynamic` corrects the character as it is typed; the rule
stays as the guarantee.

**`ContentRating` replaced `TrashTalkIntensity`** — the same axis with borrowed
vocabulary, because "Mild / Locker Room / No Holds Barred" needed explaining and
PG / PG-13 / R does not. The old names survive as SUB-labels, except the top
tier: "No Holds Barred" is wrestling jargon and is now "Anything Goes". Values
were remapped in place by the migration so nobody's setting reset. Default is
PG-13, pre-selected at registration rather than blank — an unset radio group
reads as a decision you must research before you are allowed to sign up.

Two Flux details this turned up:

- **`flux:radio` in the `cards` variant nests its description inside an
  `if ($label)` branch.** Passing only a slot silently drops the description;
  pass `label` AND the slot, and the slot still wins for display.
- **Factories must satisfy the app's own rules.** `fake()->userName()` emits
  dots and capitals, so fixtures built a user the handle validation rejects —
  failing only on the runs where faker picked a name with a dot in it.

## Prefer Bootstrap Icons

Reach for [Bootstrap Icons](https://icons.getbootstrap.com) first. They are
16px FILLED paths — no stroke — so they sit lighter than Lucide's 2px outlines,
which read as heavy next to everything else on a dense screen.

`php artisan flux:icon` imports from Lucide ONLY. Bootstrap ones are added by
hand, which is fine because a Flux icon is just a Blade file in
`resources/views/flux/icon/` following a small contract — see
`pin-angle.blade.php` for the shape. Credit the source in a comment; Bootstrap
Icons are MIT.

Two things that shape how they are used:

- **`variant` controls SIZE only.** Bootstrap ships outline and filled as
  separate icons, not variants of one, so a filled state selects a different
  component (`pin-angle-fill`) rather than passing `variant="solid"`.
- **Pass them as a CHILD, never through `icon="..."`.** That prop resolves
  against Flux's own set and falls back silently when the name is not in it, so
  a button renders a stroked 24px glyph while `flux:icon.pin-angle` on its own
  renders the Bootstrap one. As a child it is unambiguous, and its colour can be
  set directly instead of being fought past the button's own `text-*`.

Heroicons stay in place where they are already used and where the set has a good
match — this is a preference for new work, not a migration.

## Follows are an ORDERED list; there is no favorite

A user follows up to `User::MAX_FOLLOWED_TEAMS` (5) teams and controls their
order. That order drives everything — the Home swipe order, the scoreboard
float order, whose news leads. **Position 1 is what "favorite" used to mean.**

`users.favorite_team_id` is gone, and the reason is worth keeping: singling
out one team forced every surface to RECONCILE it with the follow list. The
scoreboard had to union the favorite in, because a row written before
`SetFavoriteTeam` existed might not be followed; `UnfollowTeam` had to null
the column or leave a ghost team leading the home page. An ordered list makes
all of that unrepresentable.

    FollowTeam            appends at max(position) + 1 — a new follow never
                          outranks the teams already there
    UnfollowTeam          deletes, then REINDEXES to 1..N. Sparse positions
                          still sort correctly, which is what makes leaving
                          gaps easy; the cost lands on every later writer
    ReorderFollowedTeams  handle() validates the submitted list is EXACTLY
                          the user's followed set — it is reachable from a
                          public Livewire method

**`game_odds.favorite_team_id` is a different column.** It is the BETTING
favorite, written by `SyncOdds`. Anyone grepping "favorite" will hit it;
`OddsAndPredictorsTest` passing unchanged is the proof the right one went.

**`wire:sort` reports ONE item and its new index, not the whole list**, and
that index is 0-based (Sortable's `newIndex` — verified in
`vendor/livewire/livewire/dist/livewire.esm.js`, the sort of thing that
produces an off-by-one rather than an error). `ReorderFollowedTeams::place()`
rebuilds the full order from it so the drag path gets the same validation as
the keyboard path. Drag is not keyboard-reachable, so the up/down buttons are
not optional.

## Onboarding is one blue button, then four small screens

Home's getting-started card is the front door: guests see `Get started` and
step through name → handle → trash talk → email+password; signed-in users see
`Add your team` and go straight to the picker. Both land in the same
full-screen overlay (`livewire/onboarding.blade.php`, `fixed inset-0 z-50`
over app chrome at z-40) rather than navigating — the same reason the search
panel expands in place.

- **Credentials come LAST**, which is a conversion choice and a security one:
  an abandoned signup has no password or email to leave anywhere.
- **The device draft (`localStorage['cfb.signup']`) stores only the first
  three screens.** Two independent protections, because either alone can be
  undone by a later edit: an explicit allowlist of the three fields, AND no
  save handler on the credentials screen at all. Verify by READING storage,
  not by reading the code.
- **The draft saves from the ELEMENT that fired, never from `$wire`.** These
  bindings are deferred, so `$wire.handle` is still empty while the user types
  into it — saving from component state wrote a step behind.
- **Every step needs its own `wire:key`.** Without one Livewire morphs step
  one's input into step two's — same tag, same position — and the reused node
  kept its old binding long enough for a keystroke to land on the previous
  field. Found in the browser: typing a handle wrote to `first_name`.
- **`register()` does a FULL redirect** to `home?start=team`, not
  `navigate: true`: registering flips the whole page's auth state and every
  `@auth` region has to re-render. The redirect also means nothing client-side
  runs afterwards, which is why an authenticated load clears the draft.
- **Dismissal reuses `onboarded_at`** (guests: a session flag). Adding a team
  stamps it too, so the prompt cannot return on a page that now has their team.

## The pin mark, and where it still lives

Bootstrap's `pin-angle-fill` in blue marks the team a user ranked FIRST on the
scoreboard's floated block — not every followed team, which the reader can
already see from position. Heroicons has no plain pin, only `map-pin`, which
reads as a LOCATION and is actively wrong in an app full of venues.

The pin no longer means "favorite" — that concept is gone (see the ordered
list above) — and Account uses drag handles rather than pins to say the same
thing.

## One card for teams, and never two searchable listboxes on a screen

Account has a single "Your teams" card: a search that follows, and a list with
a drag handle plus up/down buttons on each row.

It was two cards, and the split caused a real bug. Choosing the lead team was
its own `flux:select variant="listbox" searchable` over EVERY FBS team, sitting
on the same screen as the follow search. **Picking a team to follow silently
rewrote the other selection** — the two listboxes shared option values and
cross-wired, so an add wrote to both bound properties. It looked like teams
were vanishing from the follow list, and the tell was the survivor pattern.

Reordering the list the user already has removes the whole class of problem:
one searchable listbox on the screen, so nothing to collide with, and ranking
cannot pull in a new team so it can never hit the follow cap.
`ReorderFollowedTeams` still validates membership, because it is reachable
from a public Livewire method and the client can send any ids.

Both pickers — this one and Home's quick add — read `TeamGlance::fbsTeams()`,
so they cannot drift or pay for the query twice.

## Appearance lives on Account, and Flux owns the mechanism

Light / Dark / System is a segmented `flux:radio.group` bound to
`x-model="$flux.appearance"`. Flux's store already does the four things a
hand-rolled toggle gets wrong: writes `.dark` on `<html>`, persists the choice,
honours the OS setting under "System", and keeps listening for OS changes after
load rather than freezing at page render. `@fluxAppearance` must stay in BOTH
layouts for it.

It sits **in Account's sticky heading**, floated right as three icon-only
segments — the labels were the widest thing on the screen and said less than the
icons do. Account for the same reason Log out and Admin are there: below `sm`
there is no header, so a control that only exists in the desktop avatar dropdown
is unreachable on a phone.

That heading is sticky on the same offsets as the scoreboard's chrome —
`-mt-5` to cancel the container's `py-5`, and `sm:top-[calc(var(--spacing)*14+1px)]`
for the header's `h-14` plus its border — so it rests exactly where it sticks
rather than drifting on the first scroll.

The choice is per-BROWSER, not per-account — it is in localStorage, not on
`users`. Fine for now; syncing across devices would need a column and a write on
every toggle.

**`theme-color` has to be kept in step.** It was hardcoded `#09090b`, so picking
Light left a phone's address bar black. An `x-effect` in `<body>` re-tints it
from `$flux.dark`. It cannot go on the meta tag itself — Alpine only initialises
inside `<body>`, so `x-data` in `<head>` is never picked up.

## Say TRENDS, not "form"

A team's recent W/L run is **trends** — `x-trend-pills`, `$glance['trend']`.
"Form" is the soccer word for it and reads as borrowed in an American football
app, the same instinct as favorite-not-favourite.

While in the neighbourhood: **plural nouns read better in this copy.**
"Records, trends, next games" beats "record, form, next game" — a season is
a run of things, not one of each.

## American spelling, everywhere

**Favorite, not favourite.** This is an American football app; British spellings
read as a mistake in it. The rule covers UI copy, comments, PHPDoc, variable and
method names, tests and this file — not just what a user sees.

The word still appears in `game_odds.favorite_team_id` (the betting favorite),
so a stray "favourite" in a comment sitting next to it was the tell. Same for color/colour, center/centre,
canceled/cancelled.

## Float followed teams by PARTITIONING, never by a second query

A signed-in viewer's teams are lifted to the top of the scoreboard out of the
games the scope already admitted — one pass over one result set, not a separate
fetch per team.

That is what makes the rule hold without anybody writing the rule: pick Top 25
while your team is unranked and their game was never in the set to be lifted out
of, so it does not appear. Fetching it separately and re-checking the scope
afterwards is the same behavior held together by a condition that has to be kept
in step with `Scope` forever.

**All followed teams float, in the user's own order.** Four things the
presentation has to get right:

- **First team to want a game claims it.** Two followed teams playing each other
  is one game, shown once under whichever of them ranks higher — walking the
  teams in priority order and marking each game claimed is what prevents the
  same card appearing under both.
- **Move it, do not copy it.** A pinned game is removed from its day group. A
  card appearing twice reads as a duplicate fixture, not as a ranking.
- **Carry the date on the pinned heading.** Lifted out of the chronology a card
  only says "7:30pm", so the heading reads `Tennessee · Saturday, Sep 12`.
- **No union, and none needed.** This once had to merge a separate favorite
  into the followed set, because a favorite lived outside the list and could
  disagree with it. An ordered list cannot, which is the point of the change.

The empty state must check BOTH halves. A week whose only in-scope games belong
to the viewer's teams leaves the day groups empty, and keying the callout on
those alone prints "Nothing on the slate" directly above their game.

**Follows are capped at `User::MAX_FOLLOWED_TEAMS` (5).** Past a handful the
pinned block stops being a shortcut and becomes the slate again, and each
follow also commits us to syncing that team's news.

`FollowTeam` throws `FollowLimitReached` rather than silently declining, because
a write that quietly does nothing gives you a button that looks like it worked
and a news tab that never fills in. It checks "already following?" BEFORE the
cap, or a user sitting at exactly five could not press Follow on a team they
already follow.

## A filter that cannot mean anything must be disabled, not silently remapped

`Scope::teamIds('top25')` falls back to FBS when a season has no poll — which is
the normal state all summer, since the preseason AP does not land until August.
On its own that made the scoreboard read "Top 25" while showing all 138 FBS
teams.

So `Scope::hasRankings()` drives two things: the option renders disabled with
"No poll yet" beside it, and `Scope::defaultFor()` opens the screen on FBS. The
fallback stays as a backstop for a URL carrying `scope=top25`, because an empty
Top 25 showing "Nothing on the slate" as a visitor's first screen is worse.

Disabled options are rendered as plain divs, NOT `flux:menu.item` — menu items
are focusable and selectable, so a disabled one still lands under the keyboard.

## Sticky offsets are measured, not hardcoded

The scoreboard's title and week strip stick as one block, and day headings stick
below it. That offset comes from Alpine measuring the block into
`--scores-chrome`, because the strip's height varies with font and the title
wraps at narrow widths — a guessed constant leaves a gap or an overlap.

Three things that measurement has to get right, each one already paid for:

**Height alone is not the offset.** The chrome is `top-0` at base but
`sm:top-14`, clearing the layout header that only exists from `sm` up. Its
resting bottom edge is `offsetHeight + getComputedStyle(el).top`. Measuring
height alone parked every day heading 56px too high from `sm` up, behind the
chrome instead of below it.

**Write it to `document.documentElement`, never to the component root.** The
server HTML carries no `style` attribute there, so Livewire's morph treats an
inline one as drift and strips it. Picking a different week wiped the variable,
`top` fell back to 0, and the headings stuck underneath the chrome. Livewire
never morphs `<html>`.

**Observe, don't just init.** A `ResizeObserver` catches the changes a window
resize never sees — the webfont swapping in, the title wrapping, the strip
gaining or losing the postseason pills. A window `resize` listener catches the
reverse: crossing `sm` changes the chrome's `top` without changing its height.

## A sticky block should have nothing to travel through

"The heading drifts up slightly when you scroll" is a sticky element resting
BELOW where it sticks. It scrolls normally until it closes that gap. Three
sources, all of them removed on the scoreboard:

- the layout container's `py-5` — cancelled with `-mt-5` on the sticky block,
  the same way `-mx-4` already cancelled its `px-4`
- the block's own `pt-1` — spacing moved INSIDE as `pt-3`, so it belongs to the
  chrome and travels with it instead of scrolling away
- **one pixel of header border.** `h-14` plus `border-b` is 57px, not 56, so a
  flat `sm:top-14` left exactly 1px of drift. The offset is
  `sm:top-[calc(var(--spacing)*14+1px)]`

Below `sm` that header can be genuinely EMPTY — Scores is a single-screen area,
so the bar is `sm:flex` and the strip renders nothing, leaving an unconditional
`border-b` as a 1px rule floating at the top of the screen. It is now
`sm:border-b`, plus `border-b` at base only when sections exist.

Prefer `sticky` with zero travel over `fixed`. They look identical, but `fixed`
leaves the flow and drops the page underneath it — needing a spacer the exact
height of a block whose height is variable, which is what `--scores-chrome`
exists to measure in the first place.

## `truncate` cannot clip a box that is free to grow

The symptom does not look like a text problem. The page scrolls sideways, and
because the tab bar is `fixed` and the screen chrome is `sticky` — both of which
pin to the VIEWPORT, not to the content — they stay put while everything else
travels underneath them. It reads as the nav losing its positioning and the
screen coming apart on both axes.

The cause is always the same shape. A flex or grid ITEM keeps its automatic
minimum size, which is its **min-content width**. `truncate` sets
`white-space: nowrap`, and the min-content width of unwrappable text is the
whole string. So the item grows to fit the text rather than clipping it, and
truncation never gets a constrained box to work against.

**`min-w-0` on the item is what makes `truncate` work at all.** Three live
instances, all found by measuring rather than reading:

    game card        404px in a 343px track   longest CFP bowl name
    recruit row      516px in a 343px track   high school + hometown
    conference head   select pushed off-screen  long conference name

It surfaces where the longest strings are, so the postseason and a team's
hometown find it before anything else does.

Check for it with the document, never the eye — an element's
`getBoundingClientRect()` still reports its full width inside an
`overflow-x: auto` container, so a `stat-grid` table reads as an overflow when
it is behaving exactly as intended. The real test is whether the document
actually scrolls:

    scrollTo({left: 999}); window.scrollX === 0

**In a TABLE the fix is `w-full max-w-0`, not `min-w-0`.** Same cause — a cell
sizes to its content's min-content width, and `truncate` makes that the whole
string — but a `<td>` does not respond to `min-w-0`. Zeroing the max width lets
the cell be told its size instead of asking for one, and `w-full` hands it
whatever the fixed numeric columns leave. Rankings went from an 18px inner
scroll at 390px to fitting exactly, with full team names and no ellipsis.

Three more things a dense table needs, all measured on Standings at 390px,
where six columns had been forced into a `min-w-md` horizontal scroll:

- **The HEADERS set the column widths, not the values.** "Overall" claimed 69px
  for a value needing 30. Abbreviate them and keep the full word as `sr-only`,
  so nothing is lost to a screen reader.
- **`whitespace-nowrap` on the TABLE.** Abbreviating a header makes its column
  narrower than a four-character record, so "13-0" wrapped to two lines and made
  the top three rows of every conference 6px taller than the rest — which reads
  as a rendering glitch, not as a wrap. The team cell overrides it with
  `truncate`; that is the one place text may be cut instead of wrapped.
- **`px-1.5` on the numeric columns.** Worth 24px across five of them.

Together: the team column went 108px to 158px and the names that no longer fit
went from 90 of 136 to 4, each clipping by a pixel or three.

**Say the PLACE, not the mascot, in any dense table** — "Ohio State", never
"Ohio State Buckeyes". `x-team-link label="location"` and `Team::placeName()`,
the same call the game card makes. It is the single biggest saving available,
and a table is scanned rather than read. Remember `location` in the constrained
eager load: omit it and every team silently falls back to its display name,
which reads as a design decision rather than a missing column.

## An opaque background does not win a z-index tie

Sticky headings need an OPAQUE background — `bg-white/90` with `backdrop-blur`
softens what scrolls behind but does not stop it competing. That was necessary
and NOT sufficient, and the second half looks identical to the first: team names
painting over the heading reads as "the background is gone".

A game card's inner wrapper is `relative` with `z-index: auto`, which opens **no
stacking context**. So the team rows' `relative z-10` stays in the ROOT context,
ties with the day heading's `z-10`, and wins on tree order because cards come
later in the document.

The ladder, and the rule behind it — app chrome is always above anything a
screen sticks to its own viewport:

    40   layout header, bottom tab bar      app chrome
    30   scoreboard title + week strip      screen chrome
    20   day headings
    10   game card contents

`position: relative` with `z-index: auto` creating no stacking context is the
part that surprises. Check for it before assuming a paint order is safe.

## Beware a random factory date in a shared fixture

`GameFactory` defaults `kickoff_at` to `dateTimeBetween('-4 months', '+1 month')`.
A `beforeEach` game with that default landed on an upcoming Saturday about one
run in seven, and was then counted by a sibling test asserting exactly one
slate-eligible game. It passed under `--filter` and failed in the full suite,
because the faker sequence differs. Pin the date on any fixture a
slate-eligible or date-window query might pick up.

**Worse: a factory that derives one column from another in `definition()`.**
`SeasonFactory` built its dates from the random faker year, so
`Season::factory()->create(['year' => 2025])` kept some OTHER year's dates. The
calendar reads date ranges and never the `year` column, so that row became "the
season we are heading into" and pulled `scoreboardYear()` back a year — Home
served last season's bowls about one run in twelve. Derive in `configure()`'s
`afterMaking`, which runs AFTER overrides are applied, and leave anything the
caller pinned alone.

`GameFactory` had the same shape in `kickoff_day`, computed in `definition()`
from the random date an override was about to discard — so every fixture that
pinned `kickoff_at` carried some other date's weekday. Nothing reads that column
yet, which is precisely why it would have surfaced as a mystery: it is what
`Game::slateEligible()` filters on. Derived in `afterMaking` now, in
`cfb.timezone` rather than UTC, matching `SyncGames`. `tests/Feature/FactoryFixturesTest.php`
holds both factories to the rule.

**The other half is the fixture's own unpinned columns**, and they do not have
to be dates. `TeamFactory` mints a random `alt_color`, which drives
`TeamPalette`'s ladder: a light secondary crosses the 7.0 rung and swaps
`--team-accent-contrast` from white to that hex, so a hero renders a different
set of six-digit strings from run to run on every screen the fixture reaches.
`abbreviation` is worse than random — it is derived from the faker city, so a
team pinned to "Georgia" got some other place's letters. Pin what a shared
`beforeEach` renders, or an `assertDontSee` is one coin flip from a red suite.

## An Alpine expression that starts with a comment never runs

Alpine compiles a directive as `__self.result = <expr>` and only wraps it in an
IIFE when the expression STARTS with `let`/`const` (the regex is in the
vendored bundle). Home's swiper opened its `x-init` with a block comment, so
the heuristic missed it, `result = const io = …` was a SyntaxError, and the
whole directive silently never ran: no IntersectionObserver, `active` frozen at
0, dots that never tracked a swipe.

Nothing throws where you are looking — the feature is not broken, it is INERT.
Put any multi-statement body in an `x-data` METHOD, where declarations and
comments are both legal, and leave a plain call in `x-init`.
`AlpineExpressionsTest` sweeps every `x-init`/`x-effect` in the views for the
shape.

Keep the call on the element that owns the `x-ref` it needs. Alpine walks the
tree top-down, so a parent's `x-init` fires before its children register their
refs; on the element itself, `ref` is ordered before `init`.

## Athletes route by id, not slug

326 athlete slugs collide (`xavier-williams` ×5, `cam-smith` ×5). `Athlete` has
no `getRouteKeyName()`, deliberately — making player URLs "pretty" would break
routing for hundreds of players. Teams route by slug because theirs are unique.

## `athlete_game_stats.display_stats` holds {name, label} pairs

Written by the game summary sync with ESPN's own column headings — C/ATT, YDS,
AVG, TD, INT, QBR — which beat anything we would name ourselves. Readers must
handle both that and the older flat list of names. Passing a pair to a method
typed `string` fatalled the player page, and nothing caught it because
`athlete_game_stats` was empty until the summary backfill ran.

## Rebuild assets after touching Blade

Tailwind 4 only emits utilities it finds in source. Adding a class to a Blade
file and NOT running `npm run build` means that class silently does nothing —
and it fails in a way that looks like a design bug, not a build one. A missing
`size-14` rendered a 500px team logo at full size; missing `w-28` made inline
selects stack full-width; the custom `@utility team-accent` and `stat-grid` were
absent entirely because nothing used them at the previous build.

    npm run build     # after any new utility class, always

## Naming a font in `@theme` does not load it

`--font-sans` sat in `app.css` naming a family that was never fetched, so the
whole app rendered in system-ui and looked merely "a bit off" rather than
broken. `@vite` does NOT emit font faces; the layout needs `@fonts`, in BOTH
`layouts/app` and `layouts/auth`.

The face is Archivo, self-hosted as a real VARIABLE font — one 35 KB file whose
`font-weight: 100 900` covers `font-thin` through `font-black`. Two dead ends
before that, both worth not repeating:

- **bunny/google css2 have no variable Archivo.** `wght@100..900` is accepted
  and silently returns the same nine static cuts, so a full range would be
  eighteen downloads.
- **`fontsource()` cannot resolve a variable package.** It matches subset files
  by `-{subset}-{weight}-{style}`, which never matches
  `archivo-latin-wght-normal` whose weight parses as the string "100 900". It
  throws at build time.

So the woff2 is checked into `resources/fonts/` and declared with `local()` at
weight `'100 900'`. Only the `latin` subset: verified, not assumed — zero of
34,836 athlete names use a character outside Latin-1.

## `teams.nickname` is not the nickname

Same trap as `conferences.abbreviation`. ESPN uses `nickname` for a short
LOCATION alias — App State's is "App State", Georgia's is "Georgia" — while
the mascot lives in `teams.name`: Mountaineers, Bulldogs, Volunteers.
`Team::mascotName()` reads the right one.

The team hero writes identity as two lines, place over mascot, so a long name
is never truncated: `placeName()` in bold, then `mascotName()` beneath, lighter
but NOT italic. Under both, one subtle KPI pair — `8-4 (4-4) · 6th in SEC` —
where the position phrase IS the conference link, so the conference page stays
one tap away. `x-conference-link` takes a slot for exactly that.

**Livewire's `<!--[if BLOCK]-->` markers ride inside a slot's string.** Casting
a slot to a string to test whether it is empty, then echoing it through `{{ }}`,
ESCAPES those markers into visible text on the page. Strip them first. A test
must assert the absence of the ESCAPED form (`&lt;!--[if`) — the raw markers
are legitimate comments on every Livewire page — and `assertSee` matches
straight through the junk, which is how it shipped.

## The team page: four tabs, schedule first

    Schedule · Roster · Stats · News

Schedule leads because it is what someone opening a team page came for. There
is no Overview — its only content was the leaders, which belong under Stats.
The season select sits inline to the RIGHT of the tab strip, unlabeled (four
digit years are self-evident, and the label was the widest thing on the row);
its accessible name is an `aria-label`. The strip scrolls inside its own
`min-w-0` track so it shrinks rather than pushing the select off-screen.

Stats answers two different questions and so carries its own toggle:

    Players   who on this team is good — headline lines with a full stat
              line, then per-position groups (Passing, Rushing, Receiving,
              Defense)
    Team      how good the team is — categories bucketed into Offense,
              Defense, Special Teams

**Two levels of navigation get two visual languages**, and on this screen the
two languages SWAPPED. The section tabs are `x-team-nav` — underlined, ruled,
edge to edge; the scope toggle inside Stats is a segmented pill gutter. It was
the other way round, and the swap came with the nav: once the top row owns the
underline-on-a-rule idiom, leaving the Stats toggle as a bleeding `x-plate` put
two ruled underlined rows on one screen, a child that looks exactly like its
parent. Which is the same confusion the rule has always been about, arriving
from the other direction.

So on a team page: **NAVIGATION underlines, a FILTER INSIDE a section is
pills.** The roster's squad filter was already a gutter, so the two sub-filters
now agree with each other. `/stats` keeps its plate — no hero, no nav above it
to collide with.

Both bucket maps keep an "Other" catch-all, because ESPN adds categories
without telling anyone and a hardcoded list silently drops them. Reading
ESPN's own order put `defensive` first and `scoring` near the end, so the
screen opened on tackles rather than points.

**TEAM leads, PLAYERS follows** — here and on League's Stats screen, so the
same control does not read two ways in one app. The leftmost tab is the
default, as everywhere else.

## Navigation is chips; the underline belongs to controls

The section strip (`x-section-nav`) speaks the CHIP language of the desktop
area nav (`x-area-nav`) — active section on a soft zinc chip, the rest muted
text. It used to render BYTE-IDENTICAL underlined tabs to `x-plate`, which
forced a "distinguished only by bleed" rule and a page-wide class count in
`NavigationTest` that read 2 on any screen with a plate. Now the split is
semantic: APP-LEVEL NAVIGATION (area nav, section strip, bottom bar) is chips
and color; the UNDERLINE is the in-content idiom — a reader never has to ask
whether an underlined row navigates or filters.

`ChromeConsistencyTest` allowlists `border-b-2` in exactly two files:
`plate.blade.php` and `team-nav.blade.php`. The second is the team page's own
sub nav, which wants the plate's shape — a rule reaching both edges with the
active tab's underline resting ON it — but has FIVE tabs, and the plate throws
past three deliberately (a plate is a fork in a screen, not a menu of
sections). The two never appear together: where the team nav rules a screen,
the level beneath it is pills.

**Its underline is NEUTRAL, not the team color.** `--team-accent` is the
obvious choice and is wrong on real data — the palette ladder's rung 1 leaves
a LIGHT surface behind dark text, so Colorado's gold rule would sit at 1.6:1
against the page and vanish. Making it safe would need a second contrast
ladder for a 2px line. The hero directly above already carries the brand.

**Weight does not change between active and inactive** either, in this or the
plate. Bolding the active tab reflows the row on every switch, so the labels
visibly shift as the reader moves along them; color and the underline do the
work.

Two consequences worth keeping straight:

- **The active chip classes are shared with the area nav's current tab**,
  which is `md:flex`-hidden but in the DOM on every League page. A test for
  "which section is lit" must slice the page between `aria-label="Sections"`
  and the strip's `</nav>` — counting the chip string page-wide reads 2 by
  design. `NavigationTest` does exactly that.
- **The bleed rule survives on its own merits**: chrome bleeds, a control
  inside content does not. The team page's stats toggle still bleeds because
  it is a hero-led screen whose tabs run the viewport; the League Stats
  screen's plate sits in the content column and must not.

## League chrome speaks one vocabulary

Every screen's top chrome is built from five components in
`resources/views/components/` — `filter-menu` (and its wrappers
`scope-filter` and `season-menu`), `plate`, `gutter-tabs`, `filter-bar` —
plus the existing `week-scroller`. `ChromeConsistencyTest` sweeps the views,
so inlining the old markup is a red test rather than a quiet drift. The rules
the components encode:

1. **Nothing scrolls horizontally except `x-week-scroller`, `x-section-nav`
   and Home's card swiper.** The week scroller earns it because a season's
   weeks are a spatial sequence you scrub along; the section nav because six
   sections measure 461px at 390 and navigation auto-centers its active item;
   the swiper because it is content, not a control. Every other list that
   outgrows its row goes in a menu that scrolls VERTICALLY — which is why the
   22-position filter on `/players` is a `filter-menu`, not the pill strip it
   used to be. Data tables still scroll inside their own `stat-grid`
   container; the ban is on chrome and the document.
2. **There are NO select boxes in screen chrome.** Every dropdown — scope,
   season, class, poll, position — is the same text-button-plus-menu idiom
   (`x-filter-menu`), because a boxed `flux:select` beside a text-button
   dropdown reads as two different kinds of control doing one kind of job.
   The sweep fails any `<flux:select` in a view; the one segmented control
   outside the components is Account's appearance toggle, which binds
   `$flux.appearance` through Alpine and renders identically to a gutter.
3. **WHO** (Top 25 / FBS / FCS / a conference): `x-scope-filter`, or bare
   `x-filter-menu` where a screen splits the division out. The division
   options read **"All FBS" / "All FCS"** — beside a list of conferences the
   bare acronym reads as one more league rather than the whole division.
   Standings splits the division into plate tabs instead (FBS | FCS are
   different LISTS, not a narrowing of one — almost nobody leaves FBS), whose
   tabs write `$scope` directly while its menu holds only the active
   division's conferences; a conference id still lights its division's tab,
   because `division()` looks the classification up rather than assuming.
   `$scope` speaks the same values everywhere: `fbs`, `fcs`, a conference id
   as a string.
4. **WHEN** (season, recruiting class, poll): `x-season-menu` (or a poll
   `filter-menu`), always the LAST control on its row, menu aligned `end`.
   Never a scroller; **period within a season** (weeks, poll releases) is
   always `x-week-scroller`, never a menu.
5. **`x-plate`** is the ruled "which list am I looking at" row: two tabs,
   three at the very most (the component THROWS past three), resting their
   active underline directly on the rule, with the row doubling as the shelf
   for right-aligned actions — typically the scope and season menus.
   Standings, Stats and Recruiting speak it, value-compatible
   (`team`/`players`). Its `bleed` variant now has NO caller — the team page
   was the only hero-led screen it existed for, and that screen has
   `x-team-nav`; it stays for the next one.
6. **`x-team-nav`** is the plate's shape for a hero-led screen with more tabs
   than a plate holds: bled to both edges (`-mx-4 px-4`), pulled flush under
   the hero (`-mt-5` cancelling the container's `gap-5`), one `border-b` the
   full width with the active tab's `border-b-2` resting on it. Labels are
   left-aligned with a shared gap and size to their own words — not equal
   cells, which put the widest label over its padding at 390. Team page only,
   and the level beneath it must then be pills.
7. **`x-gutter-tabs`** — the zinc track with the raised white pad — is for
   tab sets neither of those holds, and for any FILTER sitting under one:
   `shrink` drops into a flex row (roster squads, centered over content),
   `block` fills it and divides it equally (stat categories, the team page's
   stats scope). `block` runs `px-2` where `shrink` runs `px-3` — "Special
   Teams" at `px-3` sits 0.03px from clipping a three-up cell at 390, and
   five equal cells put "Schedule" 5.4px over its padding, which is what sent
   the team page's sections to `x-team-nav`. Neither scrolls; a set that
   cannot fit either way belongs in a `filter-menu`.
8. **Row order, top down**: plate or team nav → filter bar → gutter →
   content. The WHEN menu rides the plate's actions slot when one exists,
   else the filter bar's — or, on the team page, the hero.
9. **Names**: `$year`, `$q`, `$scope`, `$sort`, `$view`, `$position`;
   `$perPage` never `#[Url]`; `wire:key` prefixes are per-screen (the team
   page and `/stats` once collided on `statsview-`).

The team page's five tabs FIT at 390 instead of scrolling, and the margin is
the whole budget. Measured in the browser at a 358px row: 223.9px of labels
(Schedule 59.8, Recruits 53.0, Roster 42.4, News 35.7, Stats 33.0) plus four
20px gaps is 303.9, leaving 54px spare. That is also why the tab says
"Recruits" rather than "Recruiting", and why a sixth tab or a longer word has
to be measured before it ships — `x-team-nav` deliberately does not scroll.
Widths this marginal come from the font file (`fontTools` against
`archivo-variable-latin`) or the rendered document, never the eye.

**The team page's season menu is the ONE exception to rule 4, and it is in the
hero.** It does not fit beside those tabs: 350px of strip plus a 12px gap plus
a 52px menu is 414 in a 358px row, so it wrapped to a line of its own and cost
the screen a 32px band before any content. The hero already had 48px of unused
height beside its 80px logo, so the menu stacks under the follow button —
measured after: the strip has its row to itself at both 390 and 768, the hero
did not grow, and the document still does not scroll sideways.

That needed `x-filter-menu`'s **`accent` variant**, because the default trigger
is hardcoded `text-zinc-500` and no fixed zinc reads against 136 team colors.
`accent` sets NO color at all — it inherits `currentColor`, which is the hero's
computed text color and therefore the one pairing `TeamPalette` already proved
readable (verified on Tennessee: the trigger resolves to the hero's exact
white, 2.49:1 on the accent, identical to the follow button). It wears the same
`ring-current/50` as the Following state, so action and qualifier read as one
stack. One home at every width, deliberately — a control that sits in the hero
on a phone and beside the tabs on a laptop is two controls to learn.

Note the verification trap this turned up: stripping `.dark` from `<html>` at
runtime to "check light mode" reports a color mid-transition — the trigger read
zinc-100 against a light hero, which looks exactly like a broken inherit. Set
`localStorage['flux.appearance']` and RELOAD instead.

## Position data exists for the CURRENT roster only

Measured across `athlete_team_seasons`, and it is what shapes `/players`:

    2026   13,580 rows   13,580 with a position   ALL FBS, no FCS roster
    2025   12,571 rows      398 with a position
    2024   12,307 rows      700 with a position

ESPN publishes only the current roster, so every earlier row is derived from a
box score: it carries a jersey and a team and no position. Two rules follow:

- **There is NO season picker on `/players`**, and that is the screen's shape
  rather than an omission: an earlier season is a name list with the position
  filter switched off. The year is the newest season that HAS a roster — not
  `resultsYear()`, which points at the last season with GAMES and is a year
  behind all summer. A player's history lives on their own page.
- **The position filter gates on COVERAGE, not presence.** Those 398 rows span
  most abbreviations, so a strip built from "distinct positions this season"
  looks complete and filters to 3% of the roster. It renders only where
  positioned rows are at least half the season — which, with no season picker,
  now only fires if the newest roster is itself box-score-derived.

And `athlete_season_stats` tops out at 2025, so that year has no stats at all —
`/players` shows roster facts and nothing else. That is honest for a season
that has not kicked off, and it is why it is not a stats screen.

**The position filter is a MENU, ordered by SQUAD, not alphabetically.** It
was a scrolling pill strip — 1,015px of pills in a 390px track — until the
no-horizontal-scroll rule (see "League chrome speaks one vocabulary") moved
any open-ended set into an `x-filter-menu`, which also gave the screen back a
whole chrome row. Order still matters in a menu: alphabetical buried QB
seventeenth behind C, CB, DB, DE, DL, DT, EDGE... It sorts by ESPN's own
`position_group` — offense, defense, special teams, the order every roster
page uses including our own team page — then by squad size within each, which
puts QB fifth. Derived rather than a hardcoded list, so a position ESPN adds
lands in its group instead of being dropped. Note the cache:
`players:positions:{year}` holds the ORDER too, so changing the sort needs
`cache:clear` to be visible. The menu's trigger shows only the current
selection, which is why the search placeholder names the position — "Search
Quarterbacks…" — instead of a heading repeating the filter.

**Position ABBREVIATIONS collide across ids.** Among positions with 2026 rows,
`LS` resolves to two (39 with 256 players, 78 with 13). A select keyed on
`position_id` renders "LS" twice and each entry silently hides the other's
players. Key the filter on the abbreviation and match every id that shares it.

`/players` is driven from `team_id IN (...)` via `Scope::teamIds()` because
there is NO index leading with `season_year` — the usable one is
`(team_id, season_year, position_group)`. Measured: 64ms unfiltered and sorted,
2ms once a name prefix rides `athletes_last_name_index`.

Its name filter is a PREFIX, matching `Search::players()` and the model's own
`#[SearchUsingPrefix]`: "Smith" finds every Smith through `last_name`, "mith"
finds nobody. A screen matching differently from the search bar above it would
read as a bug.

Sorting is **Name · Last (A–Z) · Last (Z–A) · Team**, defaulting to Last
ascending — how a roster, a box score and a depth chart are all listed, and it
agrees with what the name filter matches. Name means first-then-last; sorting a
roster by given name alone answers nothing.

**Direction rides IN the sort value, not in a second property.** Only surname
sorting has two useful directions — "teams, Z first" is not a question — so a
separate `$direction` would be a control meaning nothing for three of four
options, the same trap as a Top 25 filter with no poll behind it. One value also
keeps every option directly clickable rather than hiding the reverse behind a
second click on the option already selected.

Ties break on whatever half of the name is left, **in the same direction**: a
list reversed at the top and ascending underneath is not reversed, it is two
sorts. Verified on the 44 Williamses — ascending gives Aaron, Anthony, Arion;
descending gives Zach, Xavier, Tyson.

That is a reading choice, not a cost one: measured at 118/91/116ms, the options
are the same price, because the query is driven from `team_seasons` into
`athlete_team_seasons` and so ordering on any `athletes` column is a filesort.
`athletes_last_name_index` serves the name FILTER, never the sort.

`$sort` is normalized in BOTH `mount()` and `updatedSort()` — `#[Url]` hydrates
from the querystring without firing the update hook, so a bookmarked
`?sort=nonsense` would otherwise reach the query builder as a column name.

## Infinite scroll grows a LIMIT, and the payload is the price

`/players` loads 50 rows and grows by 50, driven by `wire:intersect` on a
sentinel that is also a real `<button wire:click>` — the observer never runs for
a visitor with JS off or a throttled background tab, and 50 of 13,580 with no
way forward is not a list.

It cannot run away: a chunk is 50 rows at 64px, so loading one pushes the
sentinel ~3,200px down, past any viewport plus the 600px margin, and
`wire:intersect` only fires on ENTERING. `loadMore()` guards on the total anyway.

**The query cost is flat** — measured 259/164/154/187ms at limits of
50/200/500/1000. The filesort over 13,580 dominates, so fetching more rows is
free. That is the opposite of the intuition and it is why a growing LIMIT is
tenable at all.

**The response is not.** Livewire re-sends the whole rendered component, so each
load carries every row already on screen: measured **1,244 KB** for the load
that took 500 rows to 550, then 1,354 KB, then 1,463 KB — about 110 KB more each
time. Realistic depth (2-5 chunks behind a position filter) is 250-600 KB, which
is heavy but works; deliberate deep scrolling is not.

The cheaper shape is `@island(name:…)` plus `wire:island.append`, which sends
only the new chunk — a constant ~110 KB. It was NOT taken, and the reason is
worth keeping: an island is skipped on a parent re-render and replaced wholesale
when forced to run with `always: true`, so its body must render only the current
chunk (`forPage`). Any parent re-render that does not first reset the page then
collapses the list to whatever that chunk happens to be. Today every filter path
does reset, so it would work — and it would keep working only for as long as
nobody adds a control that re-renders without resetting. Growing a LIMIT is the
slower option and the one that cannot show the wrong rows.

**Verifying it needs the button, not the scroll.** The automated tab delivers no
IntersectionObserver entries, so `wire:intersect` cannot fire there — drive the
end state instead: click the sentinel, or call `loadMore()` and assert the row
count. The scroll trigger itself only fires on a real device.

`x-search.player-row` takes an optional `season`, defaulting to `latestSeason`.
Search wants the default — it has no year in mind. A YEAR-SCOPED caller must
pass the row it is showing or a 2024 list prints everyone's 2026 team.

## A team logo never sits on the team's color

A one-color mark in the team's own color vanishes into an accent surface —
Tennessee's orange Power T on Tennessee orange was invisible. Two rules, both
in the glance-card header and the team-page hero:

- **The logo rides a neutral puck**: `bg-white` in light mode, `dark:bg-zinc-950`
  in dark — which also matches the logo variant `x-team-logo` picks, since
  ESPN's dark-variant logos are drawn for dark surfaces.
- **Text color on an accent is COMPUTED, never assumed.** See below.

The branding lives in the surface instead: the `team-accent` utility and a
3px `alt_color` keyline along the header's bottom edge, jersey-piping style.

**That surface is FLAT, and the utility used to be called `team-gradient`.**
It painted `linear-gradient(115deg, accent 35%, accent-far)`, where
`--team-accent-far` was the primary shifted 22% away from the text color —
darker under white text. It did not read as depth; it read as a shadow falling
across the header, which is the failure mode of any gradient subtle enough to
be tasteful: too weak to be a deliberate effect, too strong to go unnoticed.
The color itself is the branding.

So there is no second surface color anywhere now — `--team-accent-far`,
`TeamPalette::$far`, `GRADIENT_SHIFT` and `shiftAwayFrom()` are all gone, and
`TeamPaletteTest` asserts a palette has EXACTLY `surface` and `text` so nothing
can reintroduce one. The old flat `team-accent` utility was dead (defined,
never used in a single view) and its name was the right one, so it was
absorbed rather than left as a near-duplicate.

## Legibility is the floor, not the target

`App\Support\TeamPalette` picks a branded header's colors, and it took three
passes to learn what the rule actually is. A YIQ brightness rule chose
Auburn's orange on navy — **brightness difference is not contrast** (99.8
points of YIQ, 4.2:1 of ratio, with white available at 11.6:1). A strict
WCAG-4.5 rule then chose near-black on Tennessee orange — perfectly legible,
and wrong to every fan who has seen a jersey, because white-on-orange at
2.49:1 IS Tennessee. **No purely ratio-driven picker can produce a school's
actual branding.** The target is what the fan expects; the ratio is the floor.

The ladder, applied in light mode only:

    0. teams.header_style set        -> the admin picked; render it
    1. secondary vs primary >= 7.0   -> SECONDARY as text (Michigan maize,
                                        Colorado gold). A secondary must EARN
                                        text duty; Auburn's 4.2:1 does not.
    2. white vs primary     >= 2.2   -> white, the sports default — down
                                        through the mid-tone brands
                                        (Tennessee, Clemson, Miami)
    3. white vs secondary   >= 4.5   -> SECONDARY as the surface (Arizona
                                        State goes maroon)
    4. darken primary                -> last resort; zero FBS teams today

**Rung 2 was two rungs.** White above 4.5 rendered plain; white in the 2.2-4.5
band picked up a subtle dark text-shadow — the ESPN treatment, reaching 25
teams. They always chose the same COLORS and differed only in that flourish,
which is gone: a mid-tone header renders flat white, which is what the jersey
does. `TeamPalette` no longer carries a `shadow` flag and there is no
`team-text-shadow` utility; `HomeTest` asserts its ABSENCE on Tennessee, which
is what stops it creeping back. `WHITE_COMFORT` (4.5) survives as the bar a
SECONDARY must clear to be swapped in as the surface, not as a text rule.

Near-black text exists ONLY behind the explicit `dark-text` override. A palette
resolves exactly two colors, and `--team-accent`, `--team-accent-contrast` and
`--team-keyline` are set per surface.

**`teams.header_style` is the admin override** — a Filament "Team Branding"
page with presets only (Auto / white / secondary-text / secondary-surface /
dark-text), because the last few percent of taste cannot be computed and a
preset cannot be configured unreadable. It is not in the sync payload, so
ESPN can never clobber a curated choice.

**Dark mode is NEUTRAL — the palette is a light-mode concern.** Under `.dark`
the `team-accent`, `team-invert` and `team-keyline`
utilities un-brand themselves: page-dark surface, no logo puck,
no keyline, zinc text, neutral buttons, and `x-team-logo`'s dark-mode mark
sits directly on the page. A brand color block on a dark theme was the harder
half of every contrast fight, so it no longer exists.

**A control ON the accent must draw its colors FROM it** — the follow button
uses the `team-invert` utility (the hero's text color as fill, the accent as
label), in CSS rather than an inline style precisely so dark mode can
neutralize it.

**Verifying that a color was APPLIED is not verifying that it is READABLE.**
The browser probe that "confirmed" Tennessee checked which variable was set,
never the ratio, so a 2.49:1 regression passed review twice. Read the computed
`color` and `background-color` and compute the ratio, and sweep all 136 teams
rather than spot-checking one.

## A game card goes to the GAME

The whole card is one link to the game page; team names inside it are plain
text (`x-team-link :link="false"`), and every row above the overlay anchor is
`pointer-events-none` so taps fall through. A reader tapping a game card wants
the game summary far more often than an opponent's team page — teams are one
more tap away on the Game screen, which is where the team links live.

**A control ON the accent must draw its colors FROM it.** The follow button
lives in the hero and is hand-rolled rather than a `flux:button`: no fixed
variant holds contrast across 136 team colors. Follow INVERTS the hero —
`background: var(--team-accent-contrast); color: var(--team-accent)` — so it
reuses the one pairing the header already proved readable, and Following
recedes to an outline in `currentColor`. Same rule for the limit message
beside it: `opacity-90` on the inherited color, never a fixed amber.

## A game card names the PLACE, not the team

`Team::placeName()` — "North Carolina", never "North Carolina Tar Heels". A card
is scanned rather than read, and the nickname is decoration sitting in front of
the word the reader is looking for.

Past 16 characters it falls back to `short_display_name`, ESPN's own shortening
(FIU, Jax State, Mississippi St, N Illinois). That is 4 of 136 FBS teams; for
103 the two columns are already identical, so the substitution is invisible
wherever it is not needed.

**It is not breakpoint-gated, and must not be.** A card is roughly 334px inside
at 390px single-column but only ~276px when it goes two-up at `sm` — so the
phone is the WIDEST case, and a `sm:` swap would put the short name where there
is most room and the long one where there is least. The threshold is sized to
the two-up card's ~144px name column.

`location` must be in every constrained eager load feeding a game card
(scoreboard, home, team, conference, game) — the usual missing-column trap.

## Verifying responsive layout

Chrome will not size a window below ~600px, so asking it to resize to 390px is
silently clamped and every media query below `sm` evaluates wrong. An iframe has
no such floor. A local-only harness renders the app at exact device widths:

    /__device?path=/scoreboard&w=390,768&h=800[&dark=1]

Registered inside an `app()->isLocal()` guard, so it does not exist in
production. Use it rather than trusting a resized window.

**The automated tab produces NO rendering frames at all** — measured:
`requestAnimationFrame` never fires and `IntersectionObserver` never delivers
an entry, which also means `scrollTo` with smooth behavior never moves. This
generalizes the FLIP lesson ("animations do not advance"). Verify
frame-driven behavior by driving the reactive END state — set the Alpine
property directly and assert what it toggles — and scroll with
`behavior: 'instant'`; the trigger itself only fires on a real device.

## `conferences.abbreviation` is not an abbreviation

It holds ESPN's URL slug. Verified: `acc`, `big10`, `usa`, `midam`, `mwest`,
`belt`, `pac12`, `sec`, `ind`. Rendering it puts lowercase slugs in front of the
reader.

    short_name   ACC · Big Ten · CUSA · MAC · Mountain West · Sun Belt · SEC

`short_name` is the display form, everywhere, including where a prop is called
`abbr`.

## Schema: what the audit measured, and the rules it left behind

Audited against a fully seeded database — 5,793 games, 34,919 athletes,
305,269 box-score lines, 182,100 season-stat rows. Most of the schema was
already right: **all 45 foreign keys have matching types on both sides**, the
ESPN-id primary keys are already narrow (`teams.id` mediumint, `athletes.id`
int, `games.id` int, `conferences.id` smallint), 56 columns already carry
deliberate lengths, and only two non-id integers anywhere exceed smallint.
Four things were not.

**`game_summaries.drives` was 86% of the database.** 306 KB per row average,
600 KB at the worst, 1,408 MB in total — and the game page loads its summary
with a plain `first()`, a SELECT *, so every view read all of it to render a
box score that never touches it. Drives live in **`game_drives`** now, one row
per game, loaded only by something that explicitly asks. Never eager-load it
beside a game or a summary; that is the exact amplification the split removed.

**An index is only worth what a query can use.** `athlete_season_stats` is read
by every leaderboard, filtering `(season_year, season_type, category, team_id)`
— but its unique index leads with `athlete_id`, so none of that could use it.
MySQL fell back to the `team_id` foreign-key index and scanned 11,337 rows at
**0.1% selectivity**; one pass of the app's screens cost 1,821,000 row reads.
`athlete_season_stats_leaderboard` matches the filter exactly.

**Three indexes were measured dead and dropped**, using
`performance_schema.table_io_waits_summary_by_index_usage` after resetting the
counters and exercising every screen:

    games.kickoff_day            7 distinct values, 83% 'Sat', and its only
                                 query (slateEligible) asks for that 83%
    games (week_id,kickoff_day)  redundant: (week_id,kickoff_at) serves every
                                 week query AND satisfies the ORDER BY
    athletes.is_active           99.7% true over 34,919 rows; 1 read

Two of those sit on `games`, which the live tier rewrites every minute all
Saturday, so dropping them buys write throughput as well.

**Narrowing a VARCHAR saves no disk.** InnoDB stores it variable-length — a
`varchar(255)` holding "Georgia" already costs 8 bytes. What an oversized
declaration costs is **sort and temp-table memory**, which MySQL allocates at
the DECLARED width: utf8mb4 `varchar(255)` is 1,020 bytes per row in a
filesort. So right-sizing is worth doing on columns that are indexed, sorted
or grouped — `athletes.last_name`/`display_name`, which `/players` filesorts
13,580 rows by — and is churn everywhere else. URLs and headlines stay wide;
`articles.image_url` was WIDENED to 512 after measuring 242 characters across
6,153 rows, one long CDN URL from throwing under strict mode.

Re-run the audit with the scripts' method: `MAX(CHAR_LENGTH())` per column,
`MIN`/`MAX` per integer against its type's ceiling, and index reads from
performance_schema after driving real traffic. Statistics in
`information_schema` are cached for 24h — `ANALYZE TABLE` first or the sizes
lie.

## The game summary is the only source of a box score

Box scores, scoring plays and drives exist in exactly one payload — `summary` —
and it is 544 KB, LARGER than a whole day's 25-game scoreboard. So it is the
only single-game fetch in the app, and it is bounded twice over:

- A **final** game is fetched once, ever. Its summary cannot change, so
  `game_summaries.is_final` short-circuits every later page view to a pure
  database read.
- A **live** game is fetched at most once per 60s staleness window, keyed on
  the GAME rather than the viewer.

**Nothing fetches this inline.** The game page dispatches `FetchGameSummary`
and renders from the database (the athlete game-log pattern); a gameday sweep
(`cfb:summaries:live`, every two minutes) keeps every in-progress game
hydrated whether or not anyone is watching it, so opening a game never shows a
box score as stale as the last viewer left it.

**Three layers keep concurrent viewers from stacking fetches**, and each
catches a race the others cannot:

    ShouldBeUnique          collapses simultaneous DISPATCHES (a page full of
                            viewers plus the sweep) into one queued job.
                            Does NOT apply inside Bus::batch — batched jobs
                            skip unique locks entirely
    in-handle isStale()     a copy that sat queued while another source
                            refreshed the game becomes a no-op instead of a
                            request. `force: true` skips this, and only the
                            just-final dispatch and the backfill carry it
    released Cache::lock    two workers genuinely executing at once for one
                            game — the backfill-beside-live case uniqueness
                            cannot see. RELEASED in a finally, not expired:
                            its never-released predecessor silently swallowed
                            any fetch made within a minute of the last, the
                            same bug the game-log lock had

`SyncGameSummary::isStale()` is game-aware where the model's own is not:
"a final summary never changes" holds only while the GAME agrees it is final,
and they disagree in both directions — a completed game with a non-final
summary means the just-final fetch was swallowed, and a live game with a final
summary means ESPN flipped a game back after briefly reporting it complete,
which would otherwise freeze that box score for the rest of the game.

**Queues are split by latency class**, since a thousand-game backfill must not
starve a Saturday: `live` (sweep, view boost, just-final), `default`
(game logs, coaches, team news), `backfill` (`cfb:summaries` batches). Workers
want SMALL concurrency — `ThrottleEspn` RELEASES a job when the shared 240/min
window is spent, so adding workers past ~3 on `backfill` lowers throughput
rather than raising it.

**Production queues ride Laravel Cloud managed queues, and deploying one sets
`QUEUE_CONNECTION=cloud` — do not set it back.** Each of the three names is
its own managed queue (Flex, max 2 workers; 512 MiB where FetchGameSummary
runs, because it decodes a 544 KB payload). What must NEVER move off redis is
`CACHE_STORE`: the limiter window, the in-flight locks and the uniqueness
locks all ride the cache store, and managed-queue workers are separate
instances — split the cache and every no-stacking guarantee above silently
voids, one limiter and one set of locks per worker. Locally the queue stays
redis; `aws/aws-sdk-php` is required by the cloud driver at deploy time.
`queue:failed`/`queue:retry` do not exist for managed queues — failed jobs
live in the Cloud dashboard's Queues tab.

`GameScoreChanged` and `GameWentFinal` (dispatched from `SyncGames::store()`,
after save, never on a first insert) are the pick'em subscription points — a
contest recompute listens there rather than polling. They carry scalars, never
the model.

## Which store lives where, and the two queue tables Redis does not replace

    cache + locks   redis      CACHE_STORE, connection `cache`, DB 1 — always
    queue           cloud on Laravel Cloud (managed queues, set at deploy);
                    redis locally, connection `default`, DB 0
    batching        MYSQL      job_batches
    failed jobs     MYSQL      failed_jobs (locally; Cloud has its own view)
    sessions        MYSQL      SESSION_DRIVER=database

`cache`, `cache_locks` and `jobs` are gone from the migrations — Redis holds
all three. **`job_batches` and `failed_jobs` are not**, and that is the part
worth knowing: `queue.batching` and `queue.failed` are configured SEPARATELY
from the queue connection and both default to the database, so a redis queue
still writes them. `cfb:summaries` dispatches a real `Bus::batch`, so dropping
`job_batches` breaks the backfill rather than merely losing bookkeeping.

**`cache:clear` calls `flushdb()`** — it wipes the whole Redis database for
the cache connection, ignoring key prefixes. Cache sits on connection `cache`
(`REDIS_CACHE_DB`, database 1) and everything else on `default` (database 0),
so clearing the cache is safe today. If sessions ever move to Redis on a
managed instance that exposes only database 0, `cache:clear` becomes a
site-wide logout — check `SESSION_CONNECTION` before making that move.

It is also the **only source of historical players.** Rosters publish the
current season only, so a 2021 player has no roster row to have come from; box
scores name everyone who took a snap.

Two shapes in that payload to respect:

- Player lines are POSITIONAL arrays, but a parallel `keys[]` names each slot.
  Zip them; never index `stats[0]`.
- Box scores contain pseudo-athletes with **negative ids** and the name "Team"
  (sack yardage charged to the team). `athletes.id` is unsigned, so inserting one
  fails outright. Skip `id <= 0`.

## A player's game log is POLLED, and Saturday is not like other days

The game log is the one genuinely per-athlete feed, so bulk syncing it would
cost one request for each of 34,836 players. Opening a player page therefore
DISPATCHES `FetchAthleteGameLog` rather than fetching inline — the page renders
what we already hold and returns in ~92ms instead of waiting on ESPN.

Freshness is `athletes.game_log_fetched_at`, and the window depends on the day:

    Sun-Fri   24 hours     nothing is moving
    Saturday  15 minutes   the numbers actually change

Fifteen minutes is a per-ATHLETE ceiling — four requests an hour for a player
somebody is watching, none at all for the rest of the roster — so it sits an
order of magnitude under the live scoreboard tier and well inside the 240/min
allowance.

**Saturday is decided in `config('cfb.timezone')`, never UTC.** A UTC Saturday
opens at 8pm Friday Eastern, which would put Friday night's games on the gameday
cadence and Saturday night's on the 24-hour one — exactly inverted, and only
ever visible in the evening.

Three rules the polling has to keep, all the same shape as `articles.story`:

- **The timestamp records that we ASKED, not that we got rows.** Most athletes
  never record a stat, so reading an empty `athlete_game_stats` as "never
  fetched" dispatches a job on every view of every one of them, forever.
- **An empty answer still stamps; a failed request does not.** A transient 500
  must not permanently demote a player to "no stats" — leaving the timestamp
  null is what makes the next view try again.
- **Persisted, not cached.** A `cache:clear` would otherwise re-open the tap on
  all 34,836 at once.

The job is unique on the ATHLETE, so a player trending after a big game is one
request rather than one per viewer — verified live: three page views, one queued
job. The service keeps a 60-second in-flight lock as well, deliberately shorter
than the gameday window so it cannot veto the cadence it exists to protect.

The page's two empty states are keyed on the TIMESTAMP, not on the log being
empty: "Fetching…" (with a `wire:poll` that reads only our own database) until
the first answer lands, then "No game log" forever after. Keyed on emptiness, a
player with no stats would sit under a spinner that never resolves.

**A "Refresh" button is offered only when nothing is outstanding**, and forces
past the staleness check — the whole point is a log that is not due one.
Offering it while the page-load job is still in flight invites a second request
for the answer already on its way and reads as though the first one failed.

### An in-flight guard must be RELEASED, not given a TTL

That lock was `Cache::add($key, true, 60)` with no release, which made it a
60-second freshness gate wearing an in-flight label. It silently swallowed any
hand-asked refresh made within a minute of the last fetch: no request, no stamp,
so the page had no "it came back" signal to wait for and spun until its own
30-second ceiling. It looked like a hang with a healthy queue worker behind it.

`Cache::lock()` acquired for the duration of the fetch and released in a
`finally` blocks only genuine concurrency. Redundant repeats on the unforced
path were never this lock's job anyway — `FetchAthleteGameLog` re-checks
staleness before spending a request.

The test that "proved" the old behavior called the service three times in a row
and asserted one request. Sequential calls are not concurrent viewers, so it was
passing for the wrong reason and pinned the bug in place. It asserts through the
JOB now, which is where the guarantee actually lives.

**The page's "did it come back?" signal compares second-resolution stamps**, so
it also treats a stamp at or after the moment it queued as landed — `timestamp`
has no sub-second precision, and a refresh landing inside a second of the last
one would otherwise look like it never arrived.

## Coaches: the roster names them, the coach sync makes them people

The roster feed delivers a coach as a name and nothing else. Everything else
comes from the core API's per-coach document — birthplace, career record, and
`coachSeasons[]` refs whose URLs carry the season years. Each season document
in turn carries `team.$ref` with the TEAM ID IN THE URL, so a coach's moves
between schools (Riley: Oklahoma 2017-2021, USC 2022-, verified live) parse
out of refs without resolving them — a coach costs 2 + 2N requests, not 2 + 3N.

- **Venue photos are probed, not fetched.** ESPN has them on its CDN but
  hands them to no feed a pregame screen can reach — `gameInfo.venue.images`
  lives in the summary payload, and an unplayed game has no summary. The URL
  is not derivable either: measured across six venues, three answer only
  under `day/interior`, one only under `day`, two under both, and one has
  none. So `cfb:venues` HEADs both patterns once per venue and stores only a
  200; `venues.image_checked_at` separates "asked, and there is nothing" from
  "never asked", which is what stops the 93 photoless venues being re-probed
  every run. 149 of 242 have one, so the game-information card must read
  correctly without it.
- **There is no coach headshot endpoint.** `players/full/{id}.png` resolves
  only where a coach's id matches their old player id (Smart yes, Riley no).
  One HEAD against the CDN — not the API, so not against the rate ceiling —
  stored only on 200. Every surface must look right without one.
- **ESPN writes coach birthplaces with FULL state names** ("Montgomery,
  Alabama") while athletes carry codes ("TX"). `SyncCoaches` normalizes to
  the two-letter form on write so a search list never shows both formats.
- A season whose record 404s stores the tenure row WITHOUT a record — skip,
  never default. A season whose team we do not know stores nothing.
- Coach pages route by ID, matching athletes — no slug column, and 326
  athlete slugs already collide.
- The schedule runs `cfb:coaches --current` weekly in season: only the
  latest season changes, because published career history never does.

## News: clamped, rolling, and only one of its filters works

- `limit` is **clamped to 50** however much you ask for. There is no pagination
  parameter that lifts it.
- The GENERAL feed's window is about **six days**, so history from it is
  ACCUMULATED by syncing on a schedule. Nothing in the sync may delete.
- **`?team=` is honoured** and returns a genuinely different set — Georgia shares
  only 5 of 50 articles with the general feed. **`?athlete=` on the same
  endpoint is silently ignored.** One parameter can be trusted and its sibling
  cannot.
- **History CAN be backfilled, through the team parameter.** This file used to
  say it could not, and that was wrong: fanning `SyncTeamNews` across all 811
  teams in the current season took the archive from 50 articles to **6,153,
  spanning 2012-09-14 to 2026-08-05 — 13.9 years**, in one pass of 811
  requests. Each team's own feed reaches back years, not days; only the
  undifferentiated national feed is a six-day window. Worth re-running after
  any data loss, and the reason a team's news tab has real depth.
- Every article on the college-football path carries an `NCAA Football` tag, so
  no filtering is needed. Basketball tags appear as ADDITIONAL tags on
  multi-sport stories, not as off-topic articles.
- `categories[]` lists each team **twice** ("Georgia Bulldogs" and "University of
  Georgia", same `teamId`). Dedupe or the pivot doubles.

**Following a team is what fetches its news.** `FollowTeam` dispatches
`SyncTeamNews`, because a follow is the moment a team's feed becomes worth a
request — measured live, Alabama's feed held 25 articles we did not have and
Miami's 19. The job is unique on the TEAM, so a team gaining 500 followers after
an upset is one fetch, not 500. Note what it does and does not do: it DENSIFIES
the window, it does not extend it — the earliest article date barely moves.

Every write that creates a follow goes through `app/Actions`, never straight to
the relation, so the dispatch cannot be forgotten by a new caller.

## Article BODIES live on a fourth host, one request each

The news list carries a headline, a thumbnail and a link — never the story. The
body is only at `now.core.api.espn.com/v1/sports/news/{espnId}`, which is NOT
under the college-football path: it is league-agnostic and keyed on the article
id alone. Verified live over https (v3 called it over http) and it 404s on an
unknown id rather than returning an empty envelope.

Bounded exactly like the game summary, because the shape is identical — one
payload, and it cannot change once published:

    fetched ONCE, ever          a stored story makes every later view a pure
                                database read, so a shared article costs one
                                request no matter how many people open it
    throttled per ARTICLE       Cache::lock("espn:story:{id}", 60), not per
                                viewer

**A third of articles have NO body.** `Media` is a video or photo post — 78 of
our 212, and every one of eight sampled came back empty. So `story` being null
cannot mean "not fetched yet" or every view of every video post is a request:
`story_fetched_at` is what separates "asked, and there is nothing" from "never
asked". A failed request writes NEITHER — a transient 500 must not permanently
demote an article to a link.

**A story is not plain HTML.** It carries ESPN's own pseudo-tags — `<photo1>`,
`<inline1>`, `<video1>`, `<alsosee>` — which their renderer fills in and a
browser keeps as empty inline nodes. Observed across 18 stories: `alsosee` on 8,
`photoN` on 4, `inlineN` on 4. `<photoN>` resolves against `images[N]`, index 0
being the lead image the page renders itself. The rest are cross-promotion back
to espn.com and are dropped — **along with the paragraph wrapping them**, or the
prose is left with blank gaps; one conference roundup had seven.

`App\Support\ArticleStory` does that, then rewrites espn.com team and player
links to OUR pages (two queries per article, and `teams.id` IS the ESPN id),
then runs a deny-by-default tag and attribute allowlist. That last part is not
optional: this is third-party HTML rendered unescaped, which is the exact shape
of a stored XSS. Unknown tags are UNWRAPPED rather than deleted, so a wrapper
ESPN adds next season cannot silently eat a paragraph.

What is stored is ESPN's RAW markup; rendering happens at read time and is
memoized as a plain string. So improving the renderer never means re-fetching
200 articles.

Store the story in `mediumtext`, not `text`: measured 1.6-28 KB, and `text`
tops out at 64 KB — close enough to a long ranked-list feature that a silent
truncation is a real risk.

## Lazy loading is disabled, so a missing eager load is a 500

`x-article-card` renders team chips, so anything selecting Articles must
`->with('teams:id,slug,…')`. Three screens shipped without it and only the one
whose test fixture actually attached an article caught it. **A fixture with no
rows never reaches the render path it is supposed to be testing.**

## National leaders and ranks are already computed for us

- `core/seasons/{y}/types/{t}/leaders` returns **13 categories × 100 athletes in
  ONE request**. The site equivalent 404s — core is the only source, same trap as
  the CFP rankings.
- It spans **every division** (245 teams for 2025 vs 136 in FBS), so a
  leaderboard must scope through `team_seasons.classification`.
- Team `statistics` carries a **national `rank` on every stat**. Keep it — the
  national stats screen is then a sort, not a computation over 136 teams.

## Season id is not chronology

`resultsYear()` once read `Game::max('season_id')`, which worked only while
seasons happened to be inserted in year order. Backfilling 2021-2024 gave those
older seasons HIGHER ids and moved every default season in the app backwards.
**Order by `year`.**

Two different questions, and conflating them empties a screen:

    resultsYear()      latest season with games PLAYED     — standings, rankings
    scoreboardYear()   season we are in or heading into    — scores

In August they differ. A scoreboard on `resultsYear()` shows last season's bowls.

**A screen that lets you PICK a season must feed the selector the same
question it defaults on.** The team page defaulted with `resultsYear()` and
built its year dropdown from it too, so from February to kickoff it showed a
finished schedule and did not even OFFER the season 946 already-synced games
were sitting in. It opens on `scoreboardYear()` now.

**Where the data genuinely does not exist yet, fall back and SAY SO.** Stats,
standings and leaders only exist once games are played, so the team page's
Stats tab shows the most recent season that has them under a label —
"2026 hasn't kicked off yet, so these are 2025 numbers" — rather than an
empty state for a season that has not started. That is the same call
`rosterYear()` already makes for ESPN's current-roster-only limitation, and
it is the right shape for any preseason screen: schedule and roster are real
now, results are not.

Likewise a week selector must key on **week id**: the postseason's "Bowls" is
also week 1, so keying on number collides it with the season opener. And week
date ranges ABUT — week 1 ends the day week 2 starts — so subtract a day before
displaying a range.

## Leaderboards are DERIVED, not read from ESPN's leaders feed

ESPN's national leaders endpoint spans every division, and only about half its
top 100 is FBS. Read directly, a scoped leaderboard breaks three ways: ranks go
non-contiguous (1, 3, 4, 9...), "top 100" can only ever return ~55, and a
conference collapses outright — **the MAC had FOUR players** in the national top
100 for passing yards.

`AggregateAthleteStats` folds `athlete_game_stats` into `athlete_season_stats`
instead. Zero ESPN requests; it is arithmetic over box scores we already hold.
The MAC goes from 4 rows to 43, ranked 1..N. Validated before being trusted:
our sum for Drew Mestemaker's 2025 regular season is 4129, which is exactly what
ESPN reports.

Four rules the aggregation must keep:

    SUM      counting stats
    MAX      longRushing, longReception, longPunt... a season's longest run is
             the longest single run, not the total of every game's longest
    RATE     recomputed from summed components. Averaging per-game averages
             weights a 1-carry game like a 30-carry one
    DROP     adjQBR is a proprietary model, not arithmetic. Approximating it
             would be inventing a number

Rate leaderboards need a minimum-attempts floor or they are won by whoever
attempted once.

**`season_type = 0` means the whole year, bowls included.** ESPN's headline
leaders are cumulative — its stats page reports 4,379 for the passer whose
regular season was 4,129 — so the screens read type 0. It is stored as its own
row rather than summed at read time, because rate stats cannot be added.

`national_leaders` stays as a cross-check, the same dual-source discipline the
standings reconciler uses.

### `interceptions` means two opposite things

It exists in the `passing` category (thrown — bad) and the `interceptions`
category (caught — good). Same key, opposite meaning. A leaderboard keyed on the
stat name alone ranks quarterbacks by how often they were picked off and calls
them leaders. Always pair a stat with its category.

### Top 25 is a TEAM filter

Right on Scores — "the games that matter". Wrong on a leaderboard, where it
silently means "the leading rusher among 25 teams" and reads as national. The
scope filter takes `:top25="false"` on Stats and Leaders, and both screens
rewrite the value on mount AND on update so a bookmarked or carried-over
querystring cannot reintroduce it.

## Fan out for isolation and latency, not for throughput

Steady-state load is about **1,600 requests a week** — under seven minutes of
request time against a 240/min ceiling. Parallelism buys essentially nothing
day to day. Running an army of workers would idle six days out of seven.

What queueing actually buys, and why every fan-out here exists:

    ISOLATION   one team failing must not take the other 135. Not
                hypothetical — a single historical athlete with an unknown
                position id aborted the whole 2022 stats backfill.
    LATENCY     SyncGames dispatches FetchGameSummary the moment a game flips
                to completed, so a Saturday 11pm final has its box score in
                about a minute instead of at 05:00 the next morning.
    MEMORY      one payload per job instead of a thousand in one process.

So: **size workers for the one real burst** — Saturday evening, ~60 finals
arriving together — and let them scale to zero the rest of the week. Managed
queues are the right fit precisely because they do that.

### What must NOT be decomposed

    cfb:games --tier=live    ONE request covers every live game. Splitting it
                             per-game takes a Saturday from 1 req/min to ~50.
                             This is the v3 failure the design exists to avoid.
    national leaders         already one request for 1,300 rows
    news (general feed)      already one request

Decomposing something that is already a single request is strictly worse. Fan
out by natural unit only where the unit count is high: per game (~960/season),
per team (136), per week (17), per conference (11).

## Don't re-sync what cannot have changed

`SyncRankings::season()` re-read all 18 weeks on every scheduled run — ~126
requests, twice a week, to learn ONE new week of polls. Published rankings never
change retroactively. The schedule calls `current()` (6 requests); `season()`
survives for backfills only.

Worth checking the same question of any sweep before scheduling it: what new
information does this run actually obtain?

## The ops layer: feed_runs is the ledger, sync_runs is cfb:migrate's alone

Every recurring `cfb:*` command wraps its work in `TracksFeedRun::trackRun()`,
which writes one **feed_runs** row per invocation — records, ESPN requests
(the same singleton counter the console line prints), duration, error, and
the batch id for fan-out commands — then RETHROWS on failure so scheduler
exit codes still mean what they meant. Pruned after a fortnight. `sync_runs`
is a different thing and must stay one: cfb:migrate's resume ledger, unique
per (step, season), overwritten on re-run.

`App\Support\CoverageReport` is the expected-vs-actual layer — team stats,
summaries, standings (FBS+FCS members ONLY: D2/D3's 400 conference members
carry zero standings by design, and counting them turns a healthy 265/265
into a red 265/796), rosters, aggregates, predictor coverage, freshness.
Shared verbatim by the Filament **Sync Health** page and `cfb:doctor`
(non-zero exit on any failure), so the panel and the terminal cannot
disagree. Every check names its remedy command.

The Sync Health page introspects `Schedule::events()` rather than keeping a
second registry — but routes/console.php only loads when the CONSOLE kernel
bootstraps, so in an HTTP request the resolved Schedule is empty until
`app(Console\Kernel::class)->bootstrap()` runs. That is safe precisely
because the schedule file is guaranteed side-effect-free while loading. The
overdue flag comes from each event's own cron expression, evaluated only when
its filters pass, so August does not flag offseason-gated tasks. Manual
triggers dispatch from a curated allowlist — the options ARE the validation.

The chrome-consistency sweeps exclude `filament/` views: the admin panel
renders inside Filament's design system, and the phone-first rules enforced
on an admin table is the right rule on the wrong product.

**The panel does NOT load `resources/css/app.css`, so Tailwind utilities
written in an admin view have no definitions behind them.** The first Sync
Health page laid itself out with `grid grid-cols-2 gap-4` and `flex
items-center gap-3` and rendered as one unaligned column — every class
silently absent, which reads as bad design rather than a missing stylesheet.
So the page is built entirely from Filament's own widgets and tables, which
carry their own CSS: a `StatsOverviewWidget` for spend, `TableWidget`s with
`->records(array)` for the computed coverage and schedule rows, and a normal
Eloquent table for failures. Anything genuinely custom needs a Filament theme
registered first. Page-scoped widgets set `protected static bool $isDiscovered
= false` so they do not also appear on the dashboard — and their content is
NOT in the page's own HTML, so a test must target the widget class, not the
page.

## `queue:work --memory` is useless below PHP's own limit

Ordering matters and getting it wrong looks like the guard simply not working:

    PHP memory_limit   512M   the hard kill, mid-job, no cleanup
    --memory           200    Laravel's graceful restart, checked BETWEEN jobs

Laravel only checks its threshold between jobs. With the CLI default of 128M,
`--memory=256` can never fire — PHP kills the process first. Run summary workers
as `php -d memory_limit=512M artisan queue:work --memory=200`.

Game summaries need this because memory grows roughly a megabyte per game and
never comes back inside one process. A job per game plus a recycling worker is
the fix; a longer loop is not.

## Bus batches need the Batchable trait

`Illuminate\Foundation\Queue\Queueable` does NOT include it, so `$this->batch()`
is a fatal error at run time rather than a compile-time one. Any job dispatched
in a batch that checks for cancellation must `use Batchable` explicitly.

## `STORED` is reserved in MySQL 8

`count(x) stored` in a selectRaw is a 1064 syntax error — it is the keyword from
generated columns. Alias it something else rather than reaching for backticks.

## Never cache anything that isn't a plain scalar

Already true of Eloquent models; it bit again with **Carbon**. Caching
`CarbonImmutable` in `weekReleases()` came back as `__PHP_Incomplete_Class` and
fatalled — on the SECOND request, because the first populates the cache and
returns the live object. Cache timestamps as ints.

Any test for this class of bug must **call twice**. A single-call test always
passes.

## Never Cache::remember an empty list on a screen fed by queued jobs

The cache-layer twin of "never write a default when a feed returns nothing".
Production served a fully populated stats screen whose season menu had NO
options: the menu is built from "which years have rows", the page was first
opened while the team-stats backfill was still draining, and `Cache::remember`
pinned `[]` as authoritative for an hour at a time — while the boards beside
it healed on their own per-year keys. **`App\Support\Remember::filled()`**
serves a cached value only when non-empty, stores only non-empty results, and
treats an already-cached empty as a miss — so deploying it healed production
with no `cache:clear`. Every year-list/latest-year lookup that gates a screen
goes through it, with any calendar fallback applied OUTSIDE the cache so a
fallback year can never be pinned either.

## An arrow function captures by VALUE, and a mutation inside one is lost

`fn ($g) => $claimed[$g->id] = true` writes to a copy — silently, no warning —
so the Around the League sheet's claim-once rule marked nothing and every
group re-claimed the whole slate. Any closure that MUTATES captured state must
be a full `function () use (&$ref)` or a plain foreach; the test that caught
it asserts `pluck('id')->duplicates()` is empty, which is the right shape for
any partitioning that promises each row appears once.

## Don't name a helper after a base-class method

Two fatals in one sitting, both at class-load time:

    TeamSeasonStat::all()      collides with Model::all()      → use entries()
    MigrateDataCommand::arguments()  collides with Command::arguments()

## Commands

```
# ALWAYS raise the memory limit for a multi-season migrate. Memory accumulates
# across steps in one process and PHP's CLI default is 128M — it dies partway
# through with a fatal, not an error the command can report. --resume picks up
# from sync_runs, so nothing is lost, but it costs a restart.
php -d memory_limit=1024M artisan cfb:migrate --from=2021 --to=2026
php artisan cfb:migrate --resume                  # after an interruption
php artisan cfb:migrate --summaries               # opt in to the slow pass

php artisan cfb:sync --year=2025 [--only=step]    # reference data + standings
php artisan cfb:games --tier=live|today|current|recent|season
php artisan cfb:players [--only=rosters|stats] [--team=61]
php artisan cfb:summaries --missing [--year=2025] # box scores, 1 req/game
php artisan cfb:coaches [--missing|--current]     # careers + tenures, 2+2N req/coach
php artisan cfb:aggregate                         # season totals, 0 requests
```

**A seed is not finished when `cfb:migrate` exits.** Its `rosters` and `stats`
steps QUEUE `SyncTeamSeason` jobs rather than running inline, and seeding
completed games trips the just-final branch in `SyncGames::store()`, so a
six-season run leaves ~4,800 summary jobs on `live` and ~1,600 roster jobs on
`default`. With no worker running, the command reports success and exits 0
while those tables stay empty — the same "looks done, did nothing" shape as a
403. Start workers, then let the queues drain:

```
for i in $(seq 1 12); do
  php -d memory_limit=512M artisan queue:work --queue=live,default,backfill \
      --memory=200 --stop-when-empty &
done
```

`--env=testing` does NOT switch databases — there is no `.env.testing`, so
artisan loads `.env` and `migrate:fresh --env=testing` drops the DEVELOPMENT
database. phpunit.xml's `<env>` block applies to PHPUnit runs only. Validate
schema changes with `php artisan test`, never by rebuilding the dev database.
