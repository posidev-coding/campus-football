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
  obvious guess).
- There is no NIL endpoint. `NilNewsProvider` filters team news by keyword.

## Sync cost tiers

Live refresh costs ONE request per minute total, regardless of how many games
are in progress or how many people are watching. Respect the tiers in
`SyncGames` and `routes/console.php`; v3 burst to ~20 requests/second.

    live 0-1 · today 1 · current 1 · recent 2 · season 9

Scale-to-zero MySQL means writes are not free: sync only writes rows that
actually changed (`fill` + `isDirty`), and public reads are cache-first.

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
    $calendar->defaultPoll($year)      CFP once it exists, AP until then
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
              Home · Scores · League · Search · Account. They do not change as
              you move around inside one.
    SECTIONS  the scrolling strip at the top, belonging to the CURRENT area.
              Scores shows Scores · Bowls; League shows Standings · Rankings ·
              Teams · Stats · Leaders · Recruiting.

Both once listed the same nine sections, which made the top strip a second copy
of the bottom bar. `App\Support\Navigation` is the single source of truth for
both — add a route to an area's `routes` array or it will not light a tab.

A tab is lit by AREA, not by URL equality: a game page keeps Scores lit and a
player page keeps League lit. Comparing `request()->url()` to the tab's own href
lights up only on the area's landing screen.

**Below `sm` there is no top bar at all** — 56px reclaimed. That is only safe
because every header affordance has a tab: brand → Home, search icon → Search,
avatar → Account. Anything added to the desktop header must get a phone route
too, or it is unreachable at 390px. Log out and Admin live on the Account screen
for exactly this reason.

Pick'em gets the sixth tab when it ships; the bar sizes its columns from the
area count rather than hardcoding five.

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

## Verifying responsive layout

Chrome will not size a window below ~600px, so asking it to resize to 390px is
silently clamped and every media query below `sm` evaluates wrong. An iframe has
no such floor. A local-only harness renders the app at exact device widths:

    /__device?path=/scoreboard&w=390,768&h=800[&dark=1]

Registered inside an `app()->isLocal()` guard, so it does not exist in
production. Use it rather than trusting a resized window.

## `conferences.abbreviation` is not an abbreviation

It holds ESPN's URL slug. Verified: `acc`, `big10`, `usa`, `midam`, `mwest`,
`belt`, `pac12`, `sec`, `ind`. Rendering it puts lowercase slugs in front of the
reader.

    short_name   ACC · Big Ten · CUSA · MAC · Mountain West · Sun Belt · SEC

`short_name` is the display form, everywhere, including where a prop is called
`abbr`.

## The game summary is the only source of a box score

Box scores, scoring plays and drives exist in exactly one payload — `summary` —
and it is 544 KB, LARGER than a whole day's 25-game scoreboard. So it is the
only single-game fetch in the app, and it is bounded twice over:

- A **final** game is fetched once, ever. Its summary cannot change, so
  `game_summaries.is_final` short-circuits every later page view to a pure
  database read.
- A **live** game is throttled by `Cache::lock("espn:summary:{id}", 60)` — keyed
  on the GAME, not the viewer. A hundred people watching one game is one request
  a minute. The lock is never released, only allowed to expire; it rate-limits
  rather than guarding a critical section.

It is also the **only source of historical players.** Rosters publish the
current season only, so a 2021 player has no roster row to have come from; box
scores name everyone who took a snap.

Two shapes in that payload to respect:

- Player lines are POSITIONAL arrays, but a parallel `keys[]` names each slot.
  Zip them; never index `stats[0]`.
- Box scores contain pseudo-athletes with **negative ids** and the name "Team"
  (sack yardage charged to the team). `athletes.id` is unsigned, so inserting one
  fails outright. Skip `id <= 0`.

## News: clamped, rolling, and only one of its filters works

- `limit` is **clamped to 50** however much you ask for. There is no pagination
  parameter that lifts it.
- The window is about **six days**, so article history is ACCUMULATED by syncing
  on a schedule and cannot be backfilled. Nothing in the sync may delete.
- **`?team=` is honoured** and returns a genuinely different set — Georgia shares
  only 5 of 50 articles with the general feed, and reaches back weeks further.
  **`?athlete=` on the same endpoint is silently ignored.** One parameter can be
  trusted and its sibling cannot.
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

Likewise a week selector must key on **week id**: the postseason's "Bowls" is
also week 1, so keying on number collides it with the season opener. And week
date ranges ABUT — week 1 ends the day week 2 starts — so subtract a day before
displaying a range.

## Never cache anything that isn't a plain scalar

Already true of Eloquent models; it bit again with **Carbon**. Caching
`CarbonImmutable` in `weekReleases()` came back as `__PHP_Incomplete_Class` and
fatalled — on the SECOND request, because the first populates the cache and
returns the live object. Cache timestamps as ints.

Any test for this class of bug must **call twice**. A single-call test always
passes.

## Don't name a helper after a base-class method

Two fatals in one sitting, both at class-load time:

    TeamSeasonStat::all()      collides with Model::all()      → use entries()
    MigrateDataCommand::arguments()  collides with Command::arguments()

## Commands

```
php artisan cfb:migrate --from=2021 --to=2026     # empty DB -> fully populated
php artisan cfb:migrate --resume                  # after an interruption
php artisan cfb:migrate --summaries               # opt in to the slow pass

php artisan cfb:sync --year=2025 [--only=step]    # reference data + standings
php artisan cfb:games --tier=live|today|current|recent|season
php artisan cfb:players [--only=rosters|stats] [--team=61]
php artisan cfb:summaries --missing [--year=2025] # box scores, 1 req/game
```
