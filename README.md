# Campus Football

A college football pick'em app, built around live ESPN data.

Two halves, deliberately. **A trustworthy data layer** — scores, standings,
rankings, box scores, rosters, recruiting and news, synced from ESPN and served
fast. And **a group chat with a scoreboard attached** — pick'em, groups,
streaks and taunts, which is the part people actually come back for.

This is the fourth rebuild. v3 is preserved on the `production` branch; `v4` is
an orphan branch with no shared history. The concept and the phase plan live in
[`docs/roadmap.md`](docs/roadmap.md).

## Stack

PHP 8.4 · Laravel 13 · Livewire 4 · Flux Pro 2 · Tailwind 4 · Filament 5 ·
Pest 4 · MySQL · Redis · Vite 8

Also in use: Pennant (feature flags), Reverb (websockets), Sanctum, Scout on
the database engine, Laravel Boost.

Hosted on Laravel Cloud, with uploads on Cloudflare R2, mail through Cloudflare
Email Service and SMS through Vonage.

## Getting started

Requires PHP 8.3+ (8.4 in development), MySQL, Redis and Node. Local
development assumes
[Laravel Herd](https://herd.laravel.com), which serves the app at
`https://campusfootball.test` — there is no need to run a web server.

```bash
composer setup
```

That copies `.env.example`, generates a key, runs the migrations, installs npm
packages and builds the front end. Then fill in the ESPN and service keys in
`.env` and seed some data (below).

For front-end work:

```bash
npm run dev
```

Redis must be running — the cache store, the rate limiter and every in-flight
lock ride on it, and several guarantees quietly void without it.

### Seeding data

The app is empty until it is synced. A multi-season seed:

```bash
php -d memory_limit=1024M artisan cfb:migrate --from=2021 --to=2026
```

**Raise the memory limit.** Memory accumulates across steps in one process and
PHP's CLI default is 128M — it dies partway through with a fatal, not an error
the command can report. `--resume` picks up from the `sync_runs` ledger.

**A seed is not finished when the command exits.** Its roster and stats steps
queue jobs rather than running inline, so a six-season run leaves thousands of
jobs waiting. Start workers and let the queues drain:

```bash
for i in $(seq 1 12); do
  php -d memory_limit=512M artisan queue:work --queue=live,default,backfill \
      --memory=200 --stop-when-empty &
done
```

A fully seeded database is roughly 5,800 games, 34,900 athletes, 305,000
box-score lines and 27,000 recruiting prospects.

## Commands

Recurring syncs are scheduled in `routes/console.php` and can be run by hand:

```bash
php artisan cfb:games --tier=live|today|current|recent|season
php artisan cfb:sync --year=2026 --only=<step>   # seasons, teams, rankings, standings, news, …
php artisan cfb:players [--only=rosters|stats] [--team=61]
php artisan cfb:summaries --missing               # box scores, one request per game
php artisan cfb:coaches [--missing|--current]
php artisan cfb:aggregate                         # season totals, zero ESPN requests
php artisan cfb:doctor                            # coverage check; non-zero exit on failure
```

Live scoring costs **one ESPN request per minute in total**, however many games
are in progress — one scoreboard payload carries every live score, clock and
period. The cost tiers in `SyncGames` and `routes/console.php` are load-bearing;
see [`docs/espn-data.md`](docs/espn-data.md).

## Testing

```bash
php artisan test --compact                       # whole suite
php artisan test --compact --filter=SomeTest     # one test
vendor/bin/pint --dirty --format agent           # formatting, after touching PHP
npm run build                                    # after touching Blade
```

Every change is expected to carry a test. `npm run build` is not optional after
a Blade change — Tailwind 4 only emits utilities it finds in source, so a new
class silently does nothing until the build runs.

`--env=testing` does **not** switch databases. Validate schema changes with
`php artisan test`, never by rebuilding the development database.

### Checking layout at real device widths

Chrome will not size a window below ~600px, so a resized window evaluates every
media query below `sm` incorrectly. A local-only harness renders the app at
exact widths in iframes:

```
/__device?path=/scoreboard&w=390,768&h=800&dark=1
```

## Admin

Filament panel at `/admin`:

- **Sync Health** — expected-vs-actual coverage, spend, the schedule, and
  recent failures. Shares its checks with `cfb:doctor`, so the panel and the
  terminal cannot disagree.
- **App Branding** — colors, mark, fonts and favicons, as overrides over the
  shipped defaults in `public/brand/`.
- **Team Branding** — per-team header color overrides.

## Project layout

```
app/
  Actions/            writes with side effects (FollowTeam dispatches a news sync)
  Services/           CfbCalendar, Espn/ (client + Sync/*), Stats/
  Support/            Voice, Brand, Scope, Navigation, TeamGlance, TeamPalette,
                      GameRanks, Search, Remember, CoverageReport
  Jobs/               per-unit fan-out (FetchGameSummary, SyncTeamSeason, …)
  Console/Commands/   the cfb:* commands
  Filament/           the admin panel
resources/views/
  components/         shared chrome and cards
  livewire/           one file per screen
docs/                 long-form reference
```

## Documentation

Documentation is layered so that agents and people load only what they need:

- [`CLAUDE.md`](CLAUDE.md) — short and always loaded. Non-negotiables, commands,
  structure, how to verify.
- [`.ai/rules/`](.ai/rules/index.md) — the imperative rules, routed by file
  glob. The index maps globs to rule files, so editing a migration pulls in the
  schema rules and nothing else. Managed by Laravel Boost; `.ai/rules/boost/`
  is regenerated on `boost:update` and should not be hand-edited.
- **`docs/`** — the long-form reasoning behind those rules:

| File | Covers |
| --- | --- |
| [roadmap.md](docs/roadmap.md) | Concept, what is shipped, the phase plan |
| [espn-data.md](docs/espn-data.md) | Feeds, sync jobs, calendar, rankings, news, box scores |
| [operations.md](docs/operations.md) | Queues, caching, workers, mail, SMS, uploads, ops tooling |
| [data-model.md](docs/data-model.md) | Schema, indexes, models, factories, caching traps |
| [ui-system.md](docs/ui-system.md) | Layout, navigation, shared chrome, team colors |
| [screens.md](docs/screens.md) | Behavior of each screen |
| [product.md](docs/product.md) | Voice, identity, brand, follows, onboarding |

Every fact in those files was measured rather than assumed, and the measurement
is kept beside the conclusion so nobody re-probes a dead end.

## Conventions worth knowing before the first commit

- **Mobile-first, always.** Design at 390px, then widen. Every breakpoint above
  base is additive; nothing may be reachable only on a wide screen.
- **Conference membership is season-scoped.** Never store a team's conference as
  a scalar — join through `team_seasons`. This single mistake is why standings
  were broken across three versions.
- **Never write a default when a feed returns nothing.** `null` means "no data";
  callers skip, they do not substitute.
- **Never hardcode the current season.** `App\Services\CfbCalendar` is the only
  source of truth — a season exists in the database months before it is played.
- **The voice is a product requirement.** Copy has three registers driven by the
  user's content rating, written when the screen is written. Personal screens
  talk to you; Scores and League report facts and stay out of the way.
- **American spelling, everywhere** — including code, comments and tests.
