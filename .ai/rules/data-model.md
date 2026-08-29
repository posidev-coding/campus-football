---
paths:
  - app/Models/**
  - database/migrations/**
  - database/factories/**
---

# Models, schema and factories

Long-form reference: `docs/data-model.md`.

## Conference membership is season-scoped
Never store a team's conference as a scalar — join through `team_seasons`.
Oregon is Pac-12 in 2021 and Big Ten in 2025; 513 teams changed conference
between those years. This one mistake broke standings across three versions.

## MySQL JSON does not preserve object key order
A keyed stats map comes back reordered. Store ordering separately in a JSON
*array* — see `athlete_game_stats.display_stats`.

## Athletes and coaches route by id, not slug
326 athlete slugs collide. `Athlete` deliberately has no `getRouteKeyName()`.
Teams route by slug because theirs are unique.

## An index is only worth what a query can use
`athlete_season_stats` filters on `(season_year, season_type, category, team_id)`
but its unique index leads with `athlete_id`, so none of it was usable — MySQL
scanned 11,337 rows at 0.1% selectivity. Check the leading column against the
real filter before adding an index, and drop indexes measured dead.

## Narrowing a VARCHAR saves no disk, only sort memory
InnoDB stores it variable-length. MySQL allocates the DECLARED width in a
filesort (utf8mb4 `varchar(255)` = 1,020 bytes/row). Right-size only columns
that are indexed, sorted or grouped; leave URLs and headlines wide.

## Never eager-load a huge column beside its parent
`game_summaries.drives` was 86% of the database and every page view read it via
`SELECT *`. It lives in `game_drives` now — load it only when something asks.

## Factories must not derive one column from another in definition()
Overrides are applied after `definition()`, so a derived value keeps the random
source's year/day. Derive in `configure()`'s `afterMaking`, and leave anything
the caller pinned alone. `tests/Feature/FactoryFixturesTest.php` holds this.

## Factories must satisfy the app's own validation
`fake()->userName()` emits dots and capitals, which the handle rules reject —
it failed only on the runs where faker picked a name with a dot.

## Don't name a helper after a base-class method
`TeamSeasonStat::all()` collides with `Model::all()`; a command's `arguments()`
collides with `Command::arguments()`. Both are fatal at class-load time.

## STORED is reserved in MySQL 8
`count(x) stored` in a selectRaw is a 1064 syntax error. Alias it something else.

## SeasonFactory draws years without replacement — don't undo unique()
seasons carries a (year, type) unique index and SeasonFactory's range is only 12 years, so any fixture graph reaching Season::factory() down two chains (a pick'em slate game does: Week AND Game) collided about one run in twelve — passing under --filter, failing in the suite. fake()->unique() makes the draws collision-free within a test; a test wanting 13+ unpinned seasons overflows loudly, which is the correct failure. Pin or share a season when building multi-game fixtures.

## foreignId() cannot reference the ESPN-keyed tables
`teams.id` is mediumint unsigned and `games.id`/`venues.id` are int unsigned — right-sized for ESPN's own ids. `foreignId()` emits bigint, and MySQL refuses to constrain a wide child against a narrow parent.

It fails on the ALTER that adds the constraint, which runs AFTER `Schema::create` has already made the table, so the migration errors, leaves the table behind, and still reports itself Pending — a confusing second run that dies on "table already exists".

Use `unsignedMediumInteger('team_id')` / `unsignedInteger('game_id')` plus an explicit `$table->foreign(...)->references('id')->on(...)`, the pattern every games/standings/rankings migration already uses. (Hit 2026-08-25 building gameday_weeks.)

## A denormalized column is an INDEX, never the truth
`games.kickoff_day` is written by `SyncGames` from the ET kickoff, and exists so `slateEligible()` can ask "Saturday?" in SQL, where the timezone conversion cannot go. `Game::inSlateWindow()` used to read the COLUMN for the weekday and `kickoff_at` for the hour — two sources for one question. When a kickoff MOVES and the column does not follow, the game answers yes to "Saturday game": publishable onto a Saturday slate, and counted by `Cadence::saturdaysIn()` as one of the week's Saturdays. That is how a clubhouse came to look for its slate on a Saturday nobody played, and how main went red on 2026-08-29 — with a time-of-day tell, because the hour check only lets the mismatch through after noon ET.

Both halves read the converted timestamp now; the column stays the SQL pre-filter, which can only over-select a row the PHP check then rejects. A fixture that changes only `kickoff_day` is claiming a weekday it does not have — move `kickoff_at` too.
