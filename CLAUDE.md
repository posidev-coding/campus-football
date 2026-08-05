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
              League shows Standings · Rankings · Teams · Stats · Leaders ·
              Recruiting. Home and Scores have none.

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

**Two levels of navigation get two visual languages.** The tabs are a segmented
pill group; the scope toggle inside Stats is UNDERLINED tabs, full width at
390px and natural width from `sm`. Rendering both as segmented pills made a
filter *within* a tab look like a sibling *of* the tabs. Its rule reaches both
screen edges by cancelling the container's padding (`-mx-4 px-4`), the same
trick the scoreboard chrome uses.

Both bucket maps keep an "Other" catch-all, because ESPN adds categories
without telling anyone and a hardcoded list silently drops them. Reading
ESPN's own order put `defensive` first and `scoring` near the end, so the
screen opened on tackles rather than points.

## A team logo never sits on the team's color

A one-color mark in the team's own color vanishes into an accent surface —
Tennessee's orange Power T on Tennessee orange was invisible. Two rules, both
in the glance-card header and the team-page hero:

- **The logo rides a neutral puck**: `bg-white` in light mode, `dark:bg-zinc-950`
  in dark — which also matches the logo variant `x-team-logo` picks, since
  ESPN's dark-variant logos are drawn for dark surfaces.
- **Text color on an accent is COMPUTED, never assumed.** See below.

The branding lives in the surface instead: the `team-gradient` utility and a
3px `alt_color` keyline along the header's bottom edge, jersey-piping style.
The flat `team-accent` utility remains for surfaces that carry no logo.

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
    2. white vs primary     >= 4.5   -> white, the sports default (92 teams)
    3. white vs primary     >= 2.2   -> white + subtle dark text-shadow, the
                                        ESPN treatment (14 mid-tone brands:
                                        Tennessee, Clemson, Miami...)
    4. white vs secondary   >= 4.5   -> SECONDARY as the surface (Arizona
                                        State goes maroon)
    5. darken primary                -> last resort; zero FBS teams today

Near-black text exists ONLY behind the explicit `dark-text` override. The
gradient far end still moves AWAY from the text (computed in PHP — CSS cannot
know which way), and `--team-accent`, `--team-accent-far`,
`--team-accent-contrast` and `--team-keyline` are all set per surface.

**`teams.header_style` is the admin override** — a Filament "Team Branding"
page with presets only (Auto / white / secondary-text / secondary-surface /
dark-text), because the last few percent of taste cannot be computed and a
preset cannot be configured unreadable. It is not in the sync payload, so
ESPN can never clobber a curated choice.

**Dark mode is NEUTRAL — the palette is a light-mode concern.** Under `.dark`
the `team-gradient`, `team-invert`, `team-keyline` and `team-text-shadow`
utilities un-brand themselves: page-dark surface, no gradient, no logo puck,
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

## Coaches: the roster names them, the coach sync makes them people

The roster feed delivers a coach as a name and nothing else. Everything else
comes from the core API's per-coach document — birthplace, career record, and
`coachSeasons[]` refs whose URLs carry the season years. Each season document
in turn carries `team.$ref` with the TEAM ID IN THE URL, so a coach's moves
between schools (Riley: Oklahoma 2017-2021, USC 2022-, verified live) parse
out of refs without resolving them — a coach costs 2 + 2N requests, not 2 + 3N.

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
php artisan cfb:coaches [--missing|--current]     # careers + tenures, 2+2N req/coach
```
