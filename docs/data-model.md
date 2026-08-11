# Data model, schema and query rules

What the schema audit measured, the rules it left behind, and the Eloquent,
caching and MySQL traps this codebase has already paid for.

## Why the critical rules exist (the storage half)

**Conference membership is season-scoped.** ESPN re-parents its group tree every
year. Never store a team's conference as a scalar — join through `team_seasons`.
Oregon is Pac-12 in 2021 and Big Ten in 2025; 513 teams changed conference
between those years. This single mistake is why standings were broken across
three versions.

**MySQL JSON does not preserve object key order.** A keyed stats map comes back
reordered. Store ordering separately in a JSON *array* (see
`athlete_game_stats.display_stats`).

**Constrained eager loads must include the route key.** `with('team:id,name')`
omits `slug` and makes `route('team', $team)` fail with "missing required
parameter" — looks like a null relation, is a missing column.

**Never cache Eloquent models.** They round-trip through Redis as
`__PHP_Incomplete_Class` and fail on the *second* request, not the first. Cache
plain arrays.

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

## `conferences.abbreviation` is not an abbreviation

It holds ESPN's URL slug. Verified: `acc`, `big10`, `usa`, `midam`, `mwest`,
`belt`, `pac12`, `sec`, `ind`. Rendering it puts lowercase slugs in front of the
reader.

    short_name   ACC · Big Ten · CUSA · MAC · Mountain West · Sun Belt · SEC

`short_name` is the display form, everywhere, including where a prop is called
`abbr`.

## Lazy loading is disabled, so a missing eager load is a 500

`x-article-card` renders team chips, so anything selecting Articles must
`->with('teams:id,slug,…')`. Three screens shipped without it and only the one
whose test fixture actually attached an article caught it. **A fixture with no
rows never reaches the render path it is supposed to be testing.**

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
