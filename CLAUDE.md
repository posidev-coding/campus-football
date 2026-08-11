# Campus Football

## ⚡ Non-negotiables — read these first

These apply to every file, so they are not routed by glob. The **area-specific**
rules live in `.ai/rules/` — open `@.ai/rules/index.md`, match the globs against
the paths you are about to touch, and read those files before you write code.
Each rule there is a bug that already shipped and failed silently.

- **Mobile-first, always.** Design at 390px, then widen. Every breakpoint above
  base is ADDITIVE; nothing may be reachable only above `sm`.
- **Never write a default when data is missing.** `null` means "no data" —
  callers skip, they never substitute. This is the mistake that broke three
  previous versions, and it recurs in feeds, caches and fixtures alike.
- **Never hardcode the current season.** `App\Services\CfbCalendar` is the only
  source of truth — a season exists in the database months before it is played.
- **Voice is a product requirement.** Write all three `ContentRating` registers
  when you write the screen. Account, Pick'em, Groups and Notifications are
  LOUD; Scores and League stay factual. Roast the pick, never the person.
- **American spelling, everywhere** — favorite, color, center. Code, comments,
  copy, tests and this file.
- **Respect the ESPN sync cost tiers.** Live scoring is ONE request per minute
  in total, whatever is happening. v3 burst to ~20 requests/second.
- **Every change is programmatically tested**, and no test is deleted without
  approval.

Record a durable new rule with Boost's `record-rule` tool rather than appending
it here — it files the rule into the right area and updates the index.

## Project

A college football pick'em app — two halves, deliberately: a trustworthy data
layer over ESPN's feeds, and a loud, funny social layer on top of it. Fourth
rebuild; v3 is preserved on the `production` branch and this is an orphan `v4`
branch with no shared history. Concept and plan: [docs/roadmap.md](docs/roadmap.md).

PHP 8.4 · Laravel 13 · Livewire 4 + Flux Pro 2 · Tailwind 4 · Filament 5 ·
Pest 4 · MySQL · Redis · Pennant · Reverb · Sanctum · Laravel Cloud.

Served by Herd at `https://campusfootball.test` — never run a command to serve
it. Use Boost's `get-absolute-url` before sharing a URL.

## Commands

```bash
php artisan test --compact --filter=SomeTest   # always run the affected tests
vendor/bin/pint --dirty --format agent         # required after touching PHP
npm run build                                  # required after touching Blade
```

```bash
php artisan cfb:sync --year=2026 [--only=step]  # reference data + standings
php artisan cfb:games --tier=live|today|current|recent|season
php artisan cfb:players [--only=rosters|stats] [--team=61]
php artisan cfb:summaries --missing [--year=2026]   # box scores, 1 req/game
php artisan cfb:coaches [--missing|--current]
php artisan cfb:aggregate                       # season totals, 0 requests
php artisan cfb:doctor                          # coverage check, non-zero exit on failure
```

`cfb:migrate` (multi-season seed) and its worker-drain step have their own
gotchas — see [docs/operations.md](docs/operations.md#commands) before running it.

`--env=testing` does NOT switch databases. Validate schema changes with
`php artisan test`, never by rebuilding the dev database.

## Structure

```
app/
  Actions/        writes that have side effects (FollowTeam dispatches news sync)
  Services/       CfbCalendar, Espn/ (client + Sync/*), Stats/
  Support/        Voice, Brand, Scope, Navigation, TeamGlance, TeamPalette,
                  GameRanks, Search, Remember, CoverageReport
  Jobs/           per-unit fan-out (FetchGameSummary, SyncTeamSeason, ...)
  Console/Commands/  the cfb:* commands
  Livewire/       screens live in resources/views/livewire (Livewire 4 SFCs)
  Filament/       admin panel — Sync Health, Team Branding, App Branding
resources/views/
  components/     shared chrome: plate, filter-menu, gutter-tabs, week-scroller,
                  game-card, team-*, section-nav, area-nav, bottom-nav
  livewire/       one file per screen
  partials/       fragments composed into screens
docs/             long-form reference (see below)
```

Stick to this layout; do not add a base folder without approval.

## Conventions

General PHP, Laravel and Livewire style is covered by the Boost guidelines
below and by `.ai/rules/`. What is specific to this app:

- **Writes with side effects go through `app/Actions`**, never straight to a
  relation — `FollowTeam` dispatches that team's news sync.
- **Screen chrome speaks one vocabulary** — no `<flux:select>` in a screen, and
  nothing scrolls horizontally except the week scroller, the section nav and
  Home's swiper. `ChromeConsistencyTest` enforces it; see
  [docs/ui-system.md](docs/ui-system.md).
- **Prefer Bootstrap Icons** for new work, passed as a child rather than
  through `icon="..."`.
- **`$year`, `$q`, `$scope`, `$sort`, `$view`, `$position`** are the shared
  Livewire property names; `wire:key` prefixes are per-screen.

Project skills live in `.claude/skills/` — activate the relevant one when you
enter its domain rather than waiting until you are stuck.

## Reference documents

Load the one that covers what you are touching.

- [docs/roadmap.md](docs/roadmap.md) — **working document**: concept, current
  state, the multi-phase plan
- [docs/espn-data.md](docs/espn-data.md) — feeds, sync jobs, calendar/seasons,
  rankings, recruiting, news, box scores
- [docs/operations.md](docs/operations.md) — queues, caching stores, workers,
  mail, SMS, uploads, ops tooling, commands
- [docs/data-model.md](docs/data-model.md) — schema, indexes, models, factories,
  caching and MySQL traps
- [docs/ui-system.md](docs/ui-system.md) — layout, navigation, shared chrome,
  team colors, icons, fonts
- [docs/screens.md](docs/screens.md) — behavior of each individual screen
- [docs/product.md](docs/product.md) — voice, identity, brand, follows,
  onboarding

These are the long-form "why", with the measurement kept beside each conclusion.
The short imperative form of the same knowledge is in `.ai/rules/`, which is
what you read before editing; reach for `docs/` when you need the reasoning.

Boost's MCP tools beat manual alternatives: `search-docs` before writing code
(version-aware, multiple broad queries), `database-schema` before a migration,
`database-query` instead of raw SQL in tinker, `browser-logs` for JS errors.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

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

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

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

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

</laravel-boost-guidelines>

## How to verify

Work is not done until these pass. In order:

1. **`php artisan test --compact --filter=…`** on the affected tests, then the
   suite if the change is broad. A new behavior needs a new or updated test —
   this is not optional.
2. **`vendor/bin/pint --dirty --format agent`** if any PHP changed. Never
   `--test`.
3. **`npm run build`** if any Blade changed, or new Tailwind utilities will be
   missing at runtime and it will look like a design bug.
4. **Check it in a browser at real device widths** for anything visual. Chrome
   will not size a window below ~600px, so use the local harness — not a
   resized window:
   `/__device?path=/scoreboard&w=390,768&h=800[&dark=1]`
5. **Verify the END state, not the animation.** The automated tab produces no
   rendering frames: `requestAnimationFrame` never fires and
   `IntersectionObserver` delivers no entries. Drive the reactive end state and
   assert what it toggles.
6. **Verify a fix by breaking it back** where the bug was a wrong default — a
   test for that class of bug passes for the wrong reason more often than not.
