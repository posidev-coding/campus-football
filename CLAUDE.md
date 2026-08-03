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

## Commands

```
php artisan cfb:sync --year=2025 [--only=step]   # reference data + standings
php artisan cfb:games --tier=live|today|current|recent|season
php artisan cfb:players [--only=rosters|stats] [--team=61]
```
