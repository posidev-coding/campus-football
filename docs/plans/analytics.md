# Analytics — collecting, analyzing and visualizing how people use the app

**Status: proposed Sep 6, 2026 (workbook card CFB-66). Scope decisions
settled with the founder in the planning session and recorded below; nothing
in the Decisions table is open for re-litigation unless it appears again under
Risks. Handed off to a fresh session: that session ships THIS document on
`CFB-66-analytics` and stops; every phase after it is its own card, its own
branch and its own pull request, merged bottom-up in the order the phase table
gives.**

## Context

The card's brief, verbatim: *"Let's go deep with site analytics. Build a plan
for collecting, analyzing, and visualizing data to support telling a story
about how users are using the app. Critical findings and insights should be
reported to the workbook if action should be taken to fix problems or make
improvements. This plan should include work to build more robust and elegant
dashboards in my admin panel, rather than just a bunch of KPI tiles and the
very basic bar charts. Search the filament marketplace for plugins that might
provide a better/prettier charting library. Also, give your thoughts on this
'Data Copilot' plugin."*

What the app can measure today, and what it cannot:

- **The funnel is ten counters with no people in them.** `App\Enums\UxSignal`
  is a bounded string enum (onboarding opened, credentials reached,
  registered, team picked, skipped, two tour dismissals, invite opened, slate
  entered, first pick made). `App\Actions\RecordUxEvent` increments a Redis
  hash on connection `pulse` (Redis DB 2, out of `cache:clear`'s reach) and
  `cfb:ux-rollup` persists finished days into `ux_events(day, signal, count)`
  at 04:55 league time, a zero row per signal per day included. The snapshot
  reads seven days plus today. No user id, session or free text is ever
  counted; the one dedupe key that holds a user id is a TTL'd Redis set member
  that is never persisted. This is a design, not an omission — the AI-layer
  plan's Phase 1 chose it, and `TelemetryTest` asserts `/ops/telemetry`
  carries no email, handle, id or `user_id` after every sensor has been fired
  by an identified user.
- **Pulse measures the machine, seven days deep.** Slow requests, slow
  queries, slow jobs, slow outgoing requests, exceptions, and per-user request
  counts, on a Redis stream drained by a `pulse:work` daemon, trimmed at seven
  days. Nothing in it can answer "did the people who registered in week two
  come back in week four."
- **Nothing counts a screen.** There is no page-view sensor, no request
  middleware beyond the two `/ops` guards, no `users.last_seen_at`. The only
  per-request trace is `sessions.last_activity`, which nothing reads. The
  browser-error beacon (`window.cfbErrors` in `resources/js/app.js`) already
  ships `viewport` and `standalone` with every report, so the client side
  knows how to describe a device; the server never asked it to on a normal
  request.
- **Engagement is recoverable from the truth tables.** `picks`,
  `slate_entries` (created lazily on a member's first pick), `group_members`
  (`created_at` is the join date), `wallet_entries`, `conversation_posts`,
  `team_follows`, `group_invites`, `push_subscriptions` and six lifecycle
  stamps on `users` all carry a user id and a timestamp. Adoption of a
  feature can be joined; attention to a screen cannot.
- **The admin panel shows five widgets on a two-column stock dashboard.** Two
  `StatsOverviewWidget`s (people funnel, engagement), three `ChartWidget`s
  (top teams and top groups as horizontal Chart.js bars, picks per Saturday
  as a line), every one with `$pollingInterval = null` and no filters. Sync
  Health holds the ops widgets. No third-party Filament plugin is installed,
  `package.json` has no chart library, and the two classes that already
  aggregate application health — `App\Support\OpsReport` and
  `App\Support\TelemetrySnapshot` — have no panel surface at all.
  `OpsReport`'s docblock names the gap itself: *"one admin page could show
  them side by side."*
- **Findings reach the workbook through one door.** The maintenance advisor
  is a Claude Code cloud routine that reads `GET /ops/telemetry` and POSTs
  `/ops/workbook` — a stable key, a category, a severity, evidence quoted
  from the snapshot, and a scaffolded prompt naming the file, the line and the
  fix. Its skill's own rule: *"If a finding has no file, no line and no
  prompt, you have not finished it."* Nothing in the app files a card, and
  the `/ops` routes are the only externally reachable surfaces the AI layer
  adds. That is the routing "critical findings" already has; this plan feeds
  it rather than building a second one.

So the shape of the work is: add the one sensor that is missing (attention),
derive the story tables from it and from the truth tables, expose the story
to the advisor as aggregates, and give the founder dashboards that read like a
narrative rather than a tile wall.

## Decisions (settled)

| Question | Answer |
| --- | --- |
| How much identity does collection carry? | **A per-user clickstream in MySQL.** Raw rows with user id, route name and timestamp, pruned after a fixed number of days, with day-grain rollup tables derived from it. Ingest follows the house pattern — a Redis stream on DB 2, drained out of band; **nothing writes MySQL on the request path.** The ops snapshot stays identity-free. |
| Where does a per-user stream live? | MySQL. A different store would not make it faster — the request-path cost is decided by the ingest, not the destination — and at this traffic MySQL absorbs the batched writes without noticing. A second store earns its keep on retention, isolation of analytical scans and logs, which is what the cold tier below is for. |
| Charting library | **`leandrocfe/filament-apex-charts` ^5.0.** A new Composer dependency, approved by the founder in the planning session; it supersedes the admin-panel plan's "no new composer dependencies" line for this one package. |
| What this card ships | **This document, linked from the roadmap.** Sessions cannot file workbook cards, so the founder files the phase cards from the table at the end. |
| Cloudflare | **Included as a scoped late phase** — Pipelines HTTP ingest into R2 Data Catalog (Iceberg), queried with R2 SQL or DuckDB — for raw events and application logs. Nothing before it depends on it. |
| Page views versus actions | **Page views are the clickstream; a domain action that already has a truth table is joined, not re-recorded.** A second row for a fact `picks` or `group_members` already holds is a second counter that can disagree with the first — the `UxSignal` docblock's own rule — and a stream entry can be trimmed under load while the truth row cannot. The raw table therefore holds page views plus the handful of moments with no other home (a search, a stat or help question, a notification toggle, a share). Per-user adoption is derived by joining the truth tables into the daily rollup. *Confirmed in the planning session as the intended reading of "every page view and action".* |

## Verified facts this plan rides on

Checked against the working tree on 2026-09-06.

- `Redis::connection('pulse')` is DB 2 (`config/database.php`, `REDIS_PULSE_DB`);
  the test suite pins it to DB 15 (`phpunit.xml:56`) so tests cannot write a
  developer's telemetry. Pulse's own drain is `XRANGE` → store → `XDEL`
  (`vendor/laravel/pulse/src/Ingests/RedisIngest.php`), and `OpsReport`
  already reads a stream length with `->xlen()` for its ingest row.
- A `wire:navigate` hop is a full GET carrying `X-Livewire-Navigate: 1`.
  Component updates and `wire:poll` are POSTs to `/livewire/update`. A
  GET-only, HTML-only filter therefore sees exactly one request per screen a
  person read, with no URI knowledge.
- `EncryptCookies` swallows a `DecryptException` and hands the middleware
  `null`, so a cookie written by JavaScript in plaintext must be listed in
  `$middleware->encryptCookies(except: [...])` in `bootstrap/app.php` or the
  server never sees it. `bootstrap/app.php` already customizes the stack
  (`validateCsrfTokens(except:)`), and `web(append:)` is the hook for a group
  middleware.
- `resources/views/partials/head.blade.php` already runs one pre-paint inline
  script (the `data-standalone` stamp, around line 48) and is shared by both
  layouts — the right place for a pre-paint cookie.
- `Filament\Pages\Dashboard` exposes `$routePath`, `getWidgets()` (defaults
  to the DISCOVERED set), `getColumns()` (default 2), and embeds a filters
  form when the page uses `Filament\Pages\Dashboard\Concerns\HasFiltersForm`
  (state path `filters`, persisted in session under an md5 of the class
  name). Widgets read page filters through
  `Filament\Widgets\Concerns\InteractsWithPageFilters`. Per-widget filters
  are `Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema`. The panel
  discovers every widget under `app/Filament/Widgets` recursively unless it
  sets `$isDiscovered = false` (`AdminPanelProvider.php:151-159`), so a widget
  meant for a second dashboard must opt out or it lands on `/admin` too.
- `tests/Feature/Admin/DashboardTest.php` is the model for widget tests:
  the widget CLASS through `Livewire::test()` and `invade()->getData()`,
  never the page HTML (widget content is not in its page's markup). It pins
  `getColumns() === 2` today; `PanelNavigationTest` pins the seven nav groups
  in order; `PanelThemeTest` pins the two `@source` lines of the panel theme
  with `toContain`, so a third line is compatible.
- `TelemetryTest` pins the exact top-level key list of the snapshot
  (`tests/Feature/TelemetryTest.php:40-45`) and the no-identity sweep
  (`:63-91`). Both change in Phase 4 and the change is named there.
- `App\Support\Navigation::areas()` is the one source of truth for which
  route belongs to which area (Home, Scores, Picks, League, Account); the
  rollup reads it rather than keeping a second map.
- `App\Support\LiveState::build(?CarbonImmutable $saturday, bool $names)`
  already computes per-slate members, entries and picks for a Saturday with
  names switchable off — the machine skin the snapshot uses. `groups()` and
  `people()` feed the two stat widgets.
- `App\Support\Cadence`: `TURNOVER_DOW = 2` (the pick'em week turns on
  Tuesday), `LAST_CALL_MINUTES = 90`, `saturdaysIn(Week)`,
  `currentSaturday()`, `slateDeadline()`. `App\Services\CfbCalendar` is the
  only source of the season year. `config('cfb.timezone')` is the league day.
- `App\Support\SyncSchedule::ledgerKey()` needs a `match` arm per command
  that gains `trackRun()`, or the Scheduled Tasks row renders "untracked" —
  its own comment says so (`app/Support/SyncSchedule.php:149-190`).
- `model:prune` runs daily at 04:50 in season and Sundays 07:10 off season
  over `[ClientError, FeedRun, User, StoredNotification]`
  (`routes/console.php:434-444`); `ClientError` keeps 30 days, `FeedRun` 14,
  unverified `User`s go at 14 (`User::VERIFICATION_GRACE_DAYS`) — so
  `users.created_at` UNDERCOUNTS registrations for any cohort older than a
  fortnight, and `ux_events.onboarding_registered` is the durable count.
- `cfb:ux-rollup` is scheduled at 04:55, ungated by season, riding the
  04:00–07:00 wake; the file's comment names the rule — a scheduled task holds
  a scale-to-zero cluster up for the whole sleep timeout, so a new task rides
  an existing wake or justifies its own.
- `App\Support\Release::version()` returns the running version or null.
- There is no privacy policy anywhere in `resources/views`; the only
  disclosure is one line in the feedback sheet
  (`resources/views/livewire/help-sheet.blade.php:402-407`).
- `composer show`: `filament/filament 5.7.5`, `laravel/ai 0.11.0`,
  `laravel/pulse 1.8.0`, `livewire/livewire 4.3.5`. No `leandrocfe/*`,
  no chart library in `package.json`.
- Class names proposed below were grepped and do not exist under `app/`:
  `ActivityKind`, `ActivityEvent`, `PageViewDaily`, `UserDay`,
  `ViewportBucket`, `ActivityArea`, `ActivityFeature`, `RecordActivity`,
  `RecordPageView`, `ActivityRollup`, `AnalyticsCatalog`,
  `AnalyticsWindow`, `PerformanceReport`, `DrainActivityCommand`,
  `RollUpActivityCommand`, `AudienceDashboard`, `PickemDashboard`,
  `HealthDashboard`, `OpsQuestion`. `Feature` is avoided as an enum name
  because `Laravel\Pennant\Feature` is imported in `AppServiceProvider`.

## The story the data must be able to tell

The analysis catalog. Every row names its denominator and the floor below
which the rate is not read; **Snapshot** says whether the aggregate is
identity-free enough to hand the advisor. Every windowed number carries a
`since` — the first day the sensor behind it was counting — and a window whose
`since` falls inside it is not that window's number (the `funnel_since` rule
from `.ai/rules/enums.md`, generalized).

| # | Question | Shape | Denominator | Snapshot | Floor |
| --- | --- | --- | --- | --- | --- |
| 1 | Acquisition by cohort week | cohort = registration week (Tue–Mon). Registered from `ux_events.onboarding_registered` summed over the week; then `users` in that week: verified, onboarded, reached Picks (`picks_first_seen_at`), entered a slate (`min(slate_entries.created_at)`), installed (`standalone_seen_at`) | registered (ux_events) | `audience.cohorts[]`, eight weeks | cohort ≥ 10 |
| 2 | Activation | share of a cohort whose first entry is within 7 days of `users.created_at`, computed only for cohorts at least 7 days old | matured registrations | `activated_7d`, null until matured | ≥ 10 |
| 3 | Daily / weekly / monthly actives, stickiness | distinct users in `user_days` per day, per pick'em week, per 28 days; stickiness = mean daily / monthly over 28 days | counts | `audience.actives` | none (counts) |
| 4 | Weekly retention cohorts | cohort week × weeks since; cell = distinct users active that week / cohort size | cohort size | `audience.retention[]`, cells null under 10 | ≥ 10 |
| 5 | Saturday-to-Saturday retention | active on Saturday N and on N+1 | active on N | `audience.saturday_retention[]`, six pairs | ≥ 10 |
| 6 | Feature adoption | share of weekly actives with each `features` bit set | weekly actives | `audience.adoption` | WAU ≥ 10 |
| 7 | Route popularity and quiet screens | `page_views_daily` summed over 28 days, members only, staff excluded; quiet = a named, linked screen under 5 views | total views | `routes.top[]`, `routes.quiet[]` (null until `since` covers 28 days) | window fully covered |
| 8 | Device mix | views and people by viewport bucket and installed state, "not reported" as its own category | views; monthly actives | `devices` | informational |
| 9 | Time-of-week heat | raw events over 28 days grouped by weekday and league hour | none | dashboard only (168 cells is prompt noise) | none |
| 10 | Pick'em health per slate | `LiveState::build($saturday, names: false)` for this Saturday and last, plus late-pick share (picks updated inside `Cadence::LAST_CALL_MINUTES` of first kickoff / picks) and reminder lift (entries created between `picks_reminded_at` and first kickoff / members without an entry at the reminder) | members at first kickoff (`group_members.created_at <= kickoff`) | `pickem_health[]` — ids and counts, never a group name | entries ≥ 5 |
| 11 | Guest versus member traffic | `page_views_daily` by audience, 7 and 28 days | total views | `traffic` | none |
| 12 | Error rate per route | `client_errors` in 24h grouped by `path`, resolved to a route name through the router, divided by raw views of that route in the same 24h | views on that route | `errors.client[].route`, `views_24h` | views ≥ 50 |
| 13 | Onboarding drop by step | unchanged: `funnel` and `funnel_since` | — | already there | — |
| 14 | Pick timing inside a slate | `picks.created_at` and `updated_at` against publish, deadline, reminder and first kickoff | — | dashboard only | — |

The numbers the dashboards print and the numbers the advisor reads come from
ONE implementation, `App\Support\AnalyticsCatalog` (Phase 4) — the
`CoverageReport` rule: the panel and the terminal must not be able to
disagree.

## Phase 1 — Schema, enums, models (S)

One migration, `database/migrations/2026_09_xx_create_activity_tables.php`,
with a docblock in the `ux_events` migration's voice. Three tables plus one
column on `users`.

**`activity_events`** — the raw clickstream, **30 days** of retention.

| Column | Type | Why |
| --- | --- | --- |
| `id` | `bigIncrements` | |
| `stream_id` | `varchar(24)` ascii, **unique** | the Redis stream entry id; makes the drain idempotent across a crash between insert and `XDEL` |
| `kind` | `varchar(24)` | `ActivityKind` value; a string so a rename is a data migration, as `ux_events.signal` is |
| `user_id` | `foreignId()->nullable()->constrained()->cascadeOnDelete()` | `users.id` is bigint, so `foreignId()` is right here. **Cascade**: a deleted account's clickstream goes with it |
| `visitor` | `char(32)` ascii, nullable | guests only: the first 32 hex of `hash('sha256', session()->getId())` — the one-way shape `RecordUxEvent::handleOnce` already uses. Exactly one of `user_id` / `visitor` is non-null; the sensor enforces it |
| `audience` | `unsignedTinyInteger` | 0 guest, 1 member, 2 staff (`users.admin`). Recorded at request time; the drain does not know. Lets every dashboard exclude the founder's own browsing at pilot scale |
| `route` | `varchar(48)` | the route NAME (`pickem.group`), never a path. Bounded cardinality, no ids, no query strings |
| `facet` | `varchar(16)` nullable | one allowlisted second dimension (below) |
| `subject_type` / `subject_id` | `varchar(16)` / `unsignedBigInteger`, nullable | morph alias (`group`, `team`, `game` — the enforced morph map) plus id, for actions only; no FK on purpose (mixed parents, and `teams.id` is mediumint). Never on a page view |
| `occurred_at` | `datetime` UTC | |
| `day` | `date` | the league day, derived ONCE by the drain from `occurred_at`. A denormalized INDEX, never edited afterward (`.ai/rules/data-model.md`) |
| `hour` | `unsignedTinyInteger` | league hour 0–23, same derivation, so the hour × weekday heat never needs `CONVERT_TZ` (DST is not askable in SQL) |
| `viewport` | `unsignedSmallInteger` nullable | the raw width from the client cookie, the shape `client_errors.viewport` uses. Null = not reported; bucketed at rollup |
| `standalone` | `boolean` **nullable** | null = not reported. Not `default(false)`: that would write a default where data is missing |
| `via_navigate` | `boolean` | from the request header; never null |
| `release` | `varchar(32)` nullable | `Release::version()`; null when there is no stamp |

Indexes, each against the query that uses it: `unique(stream_id)`;
`(day, route)` for the page-view rollup and the heat; `(day, user_id)` for the
user-day rollup; `(user_id, occurred_at)` for a member's recent activity on
`ViewUser`; `(occurred_at)` for `Prunable` and the 24-hour error-rate join.

Retention is `ActivityEvent::KEEP_DAYS = 30`, via `prunable()`, added to BOTH
`model:prune` lines. Why 30: it matches `ClientError`, so an error can be read
against the traffic that produced it for as long as the error row lives; it is
long enough to re-derive every rollup after a rollup bug without a backfill;
and the identity-bearing table has a hard ceiling nobody has to remember.
The rollups are what live on.

**The `facet` allowlist.** Only the clubhouse screen, whose `#[Url] public
string $view` (`resources/views/livewire/group.blade.php`, values
slate / standings / talk / invite / season) is the difference between "opened
the group" and "read the talk". The sensor records `facet` for the
`pickem.group` and `pickem.room` routes only, and only when the value is in
the allowlist; anything else is null. No other query parameter is ever read.
Talk READS need this; talk POSTS are `conversation_posts`.

**`page_views_daily`** — attention, aggregated, kept indefinitely.

| Column | Type |
| --- | --- |
| `day` | `date` |
| `route` | `varchar(48)` |
| `facet` | `varchar(16)` default `''` — an empty string, not null, because MySQL treats nulls as distinct inside a unique key |
| `audience` | `unsignedTinyInteger` |
| `viewport_bucket` | `unsignedTinyInteger` — `App\Enums\ViewportBucket` int enum: `Unknown = 0`, `Compact = 1` (< 400), `Phone = 2` (400–767), `Tablet = 3` (768–1023), `Desktop = 4` (≥ 1024). Unknown is a real category, "not reported", not a fabricated width |
| `installed` | `unsignedTinyInteger` — 0 unknown, 1 browser, 2 standalone, same reasoning |
| `views` | `unsignedInteger` |
| `visitors` | `unsignedInteger` — `count(distinct coalesce(user_id, visitor))` INSIDE this cell; documented as non-additive across cells |
| `navigate_views` | `unsignedInteger` — hops; `views - navigate_views` is cold loads |

Unique `(day, route, facet, audience, viewport_bucket, installed)` is the
upsert key; index `(route, day)` serves route popularity over a window. A few
hundred rows a day in practice.

**`user_days`** — presence, one row per person per league day, kept
indefinitely. A row exists only when the person did something; absence is the
datum.

| Column | Type |
| --- | --- |
| `user_id` | `foreignId()->constrained()->cascadeOnDelete()` |
| `day` | `date` |
| `views` | `unsignedSmallInteger` |
| `actions` | `unsignedSmallInteger` — non-page-view kinds that day |
| `areas` | `unsignedTinyInteger` bitmask — `App\Enums\ActivityArea`: `Home = 1, Scores = 2, Picks = 4, League = 8, Account = 16`; route → area read from `Navigation::areas()` at rollup time |
| `features` | `unsignedSmallInteger` bitmask — `App\Enums\ActivityFeature`: `Picked = 1` (`picks.updated_at` that day), `Talked = 2` (`conversation_posts`), `ReadTalk = 4` (facet talk), `Followed = 8` (`team_follows`), `Joined = 16` (`group_members`), `Lobby = 32` (route `pickem.lobby`), `Stats = 64` (the stats routes), `Searched = 128`, `Asked = 256`, `Invited = 512` (`group_invites`), `Installed = 1024` (any standalone view) |
| `first_seen_at` / `last_seen_at` | `datetime` UTC bounds of the day's activity |
| `viewport_bucket` | `unsignedTinyInteger` — the mode of the day's views |

Unique `(user_id, day)`; index `(day)`. The truth-table bits are computed per
league day by selecting each table's rows inside the day's UTC window
(`[day 00:00 ET, day+1 00:00 ET)` converted once in PHP), which is what makes
`user_days` honest for somebody who picked from a notification deep link and
never rendered a second screen.

**`users.last_seen_at`** — nullable `datetime`, indexed. Written by the
drain only (Phase 3), never on the request path. For the User resource; it
never enters the snapshot.

Models: `App\Models\ActivityEvent` (`$timestamps = false`, `Prunable`,
casts for `occurred_at`, `day`, `standalone`, `via_navigate`,
`kind => ActivityKind::class`), `App\Models\PageViewDaily`,
`App\Models\UserDay`; factories for all three that derive nothing in
`definition()` (`FactoryFixturesTest`).

**Tests** — `tests/Feature/Analytics/ActivitySchemaTest.php`: the column
list of every table is pinned (the privacy pin, as `UxFunnelTest:161` does for
`ux_events`); prune keeps 30 days and not 29; deleting a user cascades;
`ViewportBucket::for()` boundaries; `ActivityArea::forRoute()` reads
`Navigation::areas()` and maps an unknown route to nothing, never to Home.

## Phase 2 — Sensor, cookie, action, emitters (M)

**The vocabulary.** `App\Enums\ActivityKind`, string-backed and bounded on
purpose, like `UxSignal`. A seventh case needs the same argument: a thing
that HAPPENED with no other home.

| Case | Value | Emitter (one file each) | Facet |
| --- | --- | --- | --- |
| `PageView` | `page_view` | `RecordPageView` middleware only | clubhouse `view` |
| `Searched` | `searched` | the search surfaces (`search-panel`, `search-page`, `search`) — once per search session, when `$q` first crosses the minimum length in a component lifecycle, never per debounce tick | null |
| `StatAsked` | `stat_asked` | `App\Livewire\Concerns\AsksQuestions::ask()` after the answer resolves | the decline reason |
| `HelpAsked` | `help_asked` | the help sheet's ask | answered / unanswered |
| `NotificationToggled` | `notification_toggled` | `PushSubscriptionController` and the Account screen's opt-in toggles | `push_on`, `push_off`, `newsletter_on`, … |
| `Shared` | `shared` | only where the tap already round-trips to the server (an invite-kit Livewire method); a pure-Alpine copy button records nothing and no beacon is added for it | `link`, `qr`, `text` |

Invite OPENED is the page view of the join route — no action row, and adding
one would be a second emitter beside `UxSignal::InviteOpened`.

**The cookie.** Viewport and installed state come from the client, and a
header cannot be attached to Livewire's navigate fetch without hooking its
internals, so: one pre-paint inline line in
`resources/views/partials/head.blade.php` beside the `data-standalone`
stamp writes `cfb_client=w{innerWidth}.s{0|1}` with `path=/`, a 30-day
`max-age`, `SameSite=Lax` and `Secure` on https. Refreshed on every load so a
rotation is picked up; no identifier in it. `bootstrap/app.php` lists it in
`$middleware->encryptCookies(except: ['cfb_client'])`. The very first HTML
response of a session has no cookie, so `viewport` and `standalone` are null,
honestly; everything after carries it.

**The page-view sensor.** `app/Http/Middleware/RecordPageView.php`, a
terminable middleware appended to the `web` group
(`$middleware->web(append: [RecordPageView::class])`). `handle()` passes
through; `terminate()` records when ALL of these hold:

1. `GET` — excludes every Livewire update and upload POST without knowing
   its URI.
2. `isSuccessful()` and a `text/html` content type — a 302 to login, a 403,
   a JSON manifest and the offline page are not screens somebody read.
3. A named route, not prefixed `filament.`, `pulse`, `livewire.`, `dev.`, and
   not in a short exclusion list (manifest, favicon, offline, the two beacon
   routes). The admin panel and Pulse are staff surfaces, never product
   traffic.
4. Not ajax unless `X-Livewire-Navigate` is present — a hop is a real
   screen; a stray XHR is not.

Why `terminate()` and not a Livewire mount hook: a hop re-mounts several
layout islands (help sheet, search palette, tour, beacon), so a mount hook
counts three or four per screen; the GET is exactly one. Why a group
middleware and not a route alias: forgetting an alias on a new route is
silent; the group is on by default. The `/ops/*` routes are outside the `web`
group and never reach it.

**The action.** `app/Actions/RecordActivity.php`:

```php
final class RecordActivity
{
    public const STREAM = 'cfb:activity';
    public const MAXLEN = 200_000;
    public const COOKIE = 'cfb_client';

    /** A rendered screen. Called only by RecordPageView::terminate(). Swallows every Throwable. */
    public function pageView(Request $request, Response $response): void;

    /** A named moment with no truth table. Swallows every Throwable. */
    public function action(ActivityKind $kind, Request $request, ?string $facet = null, ?Model $subject = null): void;

    /** Buffered entries not yet written, or null when Redis is unreachable — the OpsReport row. */
    public function pending(): ?int;

    /** XRANGE → insertOrIgnore (stream_id unique) → XDEL, then one batched users.last_seen_at update. Returns rows written. */
    public function drain(int $max = 20_000): int;

    /** The flat XADD dictionary — public so a test can assert it never carries a raw session id. */
    public static function fields(ActivityKind $kind, Request $request, ?string $facet, ?Model $subject): array;
}
```

Transport is `XADD cfb:activity MAXLEN ~ 200000` on connection `pulse` —
the shape Pulse's own ingest uses, not a list (no per-entry id for an
idempotent delete, no approximate `MAXLEN`, no `XLEN` for the monitor row)
and not Pulse's stream (it unserializes with an `allowed_classes` list and
stores into `pulse_*`). Fields are flat scalars; nulls travel as `''` and the
drain maps `''` back to null — never to 0 or false. Consumer groups add
`XACK` bookkeeping for a single consumer and buy nothing the unique
`stream_id` does not already guarantee. `MAXLEN` trims the oldest when the
drain falls 200k behind (accepted); a Redis outage is a swallowed Throwable
and a `Log::debug`, the `RecordUxEvent` shape; a request never learns any of
it happened.

`fields()` reads `$request->user()` (id, and `isAdmin()` for the audience),
the sha256 of the session id for guests, `$request->route()?->getName()`,
the navigate header, the `cfb_client` cookie through a strict regex with
out-of-range widths mapped to null, and `Release::version()`. The cookie
parse and the facet allowlist are the only two places client input enters,
and both are allowlists.

**Tests** — `tests/Feature/Analytics/PageViewSensorTest.php` (Redis DB 15
flushed in `beforeEach`, clock frozen): records a GET HTML named route once;
ignores a POST to the Livewire update endpoint; ignores a 302, a 403, JSON,
the manifest, and every `filament.*` route; sets `via_navigate` from the
header; parses `cfb_client` and leaves nulls without it; a guest row carries
a 32-hex `visitor` and the raw session id string is absent from the stream
payload; an admin records audience 2; the facet is recorded only for the
clubhouse routes and only for allowlisted values; Redis pointed at a dead
port throws nothing and the page still returns 200; **zero `activity_events`
inserts during a request**, asserted through `DB::listen`.
`ActivityEmittersTest.php`: each `ActivityKind` case is emitted from exactly
one file — `UxFunnelTest`'s source sweep, generalized. A
`ChromeConsistencyTest`-style pin that the cookie name appears once in the
head partial and once in the bootstrap except list.

Verification: `php artisan test --compact --filter=Analytics`; a browser
walk Home → Scores → a game shows three entries in `XLEN cfb:activity`.

## Phase 3 — Drain, rollup, schedule (M)

**`cfb:activity-drain`** — `app/Console/Commands/DrainActivityCommand.php`,
`use TracksFeedRun`, ledger key `activity:drain`, body
`$this->trackRun('activity:drain', null, fn () => $record->drain())`. A Redis
failure inside `drain()` is rethrown by `trackRun` so the ledger row reads
failed — bookkeeping, not a rescue. Add the arm
`str_starts_with($command, 'cfb:activity-drain') => 'activity:drain'` to
`SyncSchedule::ledgerKey()` or the Scheduled Tasks row lies.

Schedule, written against the scale-to-zero rule in `routes/console.php`:

```php
// In season the cluster is already awake 08:00–03:00 for reminders and the live tier;
// five minutes is the staleness a Saturday widget tolerates, and it adds no wake of its own.
Schedule::command('cfb:activity-drain')->everyFiveMinutes()->timezone($tz)->between('08:00', '03:00')->when($inSeason)->withoutOverlapping(5);
// Off season: ride the six-hourly news wake exactly, so June stays asleep.
Schedule::command('cfb:activity-drain')->everySixHours()->timezone($tz)->when($offSeason)->withoutOverlapping(30);
```

`MAXLEN` 200k comfortably covers six off-season hours. **Not a daemon**:
`pulse:work` is already a Cloud daemon configured outside this repository,
and a second one is a process `SyncSchedule` cannot see and the ledger cannot
report overdue.

`users.last_seen_at` is stamped inside `drain()` — one
`UPDATE … CASE` per batch for the user ids it just wrote — so it moves at most
once per drain cadence per person and never on the request path.

**`cfb:activity-rollup`** — `app/Console/Commands/RollUpActivityCommand.php`,
signature `cfb:activity-rollup {--day= : Y-m-d} {--today}`, ledger key
`activity:rollup`. It calls `drain()` FIRST so the day it rolls is complete,
then `App\Support\ActivityRollup`:

```php
final class ActivityRollup
{
    /** Recompute one league day into both tables — upsert on the unique keys, so a re-run corrects and never doubles. */
    public function day(CarbonImmutable $day): array;   // ['page_views' => int, 'user_days' => int]
    /** Today so far, on the same code path; the dashboards label it "so far". */
    public function today(): array;
    /** The first day either table holds — every window's `since`. */
    public function since(): ?string;
}
```

Schedule: `dailyAt('04:56')`, ungated (right after `cfb:ux-rollup` at 04:55,
on the wake that already exists) for yesterday; `hourly()` between 08:00 and
03:00 in season with `--today` for the live partial. Off season the
six-hourly drain is enough and `--today` is not scheduled.

**Windows.** 7, **28** and 90 days, each with
`since = max(window start, ActivityRollup::since())`. Twenty-eight and not
thirty: four whole pick'em weeks, Tuesday to Monday (`Cadence::TURNOVER_DOW`),
so a window never holds 4.3 Saturdays. `App\Support\AnalyticsWindow` is the
small value object (`from`, `to`, `since`, `label`, `from(array $filters)`)
that every widget and every catalog query resolves dates through, so no two
of them can disagree about what "28d" means.

**Two `OpsReport` rows**, in the shared `{key, label, status, detail,
remedy}` shape: `activity_ingest` (`RecordActivity::pending()`; WARN at
5,000, FAIL at 50,000; remedy `php artisan cfb:activity-drain`) and
`activity_rollup` (yesterday's `page_views_daily` missing after 06:00 ET is
WARN; remedy `php artisan cfb:activity-rollup --day=…`). The advisor's first
read of `ops` then catches a dead drain the same way `pulse_ingest` catches a
dead `pulse:work`.

`ux_events` is not touched. The funnel stays the funnel;
`SlateEntered` / `FirstPickMade` remain the pick-through rate's two sides, and
`user_days.features & Picked` is a per-person adoption bit read for
retention, never divided against a `UxSignal`.

**Tests** — `ActivityDrainTest.php`: drains and `XDEL`s; a re-drain of the
same stream id is ignored (crash idempotency); `''` becomes null, not 0;
`day` and `hour` are league time (01:00 UTC on a Sunday lands on Saturday at
21); `last_seen_at` is stamped by the drain and by nothing on the request
path; a `feed_runs` row is written. `ActivityRollupTest.php`: the
`page_views_daily` cell math including non-additive visitors; `areas` from
`Navigation`, `features` from the truth tables (a pick with no page view
still yields a row); a re-run corrects and never doubles; `today()` is partial
and `since()` is the first day. `OpsReportTest`: the two new rows, their
thresholds and remedy strings. `SyncScheduleTest`: the drain rides
`between('08:00', '03:00')` in season and six-hourly off season; both rollup
entries present; `ledgerKey()` resolves both commands.

Verification: `cfb:activity-drain` and `cfb:activity-rollup --today` run
green locally; Scheduled Tasks shows both rows tracked.

## Phase 4 — Snapshot, catalog, advisor (M)

**`App\Support\AnalyticsCatalog`** holds the named queries of the analysis
table — one method per question, each returning its numerator, denominator,
window and `since`, applying the floor by returning null below it. Widgets
(Phases 5–7) and the snapshot both call it.

**`TelemetrySnapshot::build()`** gains five sections, appended after
`funnel_since` and before `workbook` (`TelemetryTest::carries every section`
is updated to the new key list in the same PR):

```
traffic         { window_days: 7, views: {guest, member, staff}, visitors: {guest, member}, since }
audience        { actives:   {dau, wau, mau, stickiness_28d, since},
                  adoption:  {wau, since, features: {picked: {users, share}, talked, read_talk, followed, joined, lobby, stats, searched, asked, invited, installed}},
                  cohorts:   [{week, registered, verified, onboarded, reached_picks, entered, installed, activated_7d|null}],   // eight weeks
                  retention: [{cohort, size, weeks: [w0..w7 | null]}],
                  saturday_retention: [{from, to, active, retained, share|null}] }
routes          { window_days: 28, since, top: [{route, views, visitors}], quiet: [{route, views}] | null }
devices         { window_days: 28, since, by_bucket: {unknown, compact, phone, tablet, desktop}, installed_share|null }
pickem_health   [ LiveState::build(..., names: false) rows for this Saturday and last, plus late_share|null, reminder_lift|null ]
```

`errors.client[]` rows gain `route` (resolved from `path` through the
router, null when unresolvable) and `views_24h` (null when the raw table has
no rows for that route). No new key ever carries a user id, an email, a
handle or a group name. `TelemetryTest::carries counts and not people` is
extended to seed `activity_events`, `user_days` and `group_invites` rows for
its identified user and assert the payload contains none of it.
`TelemetrySnapshot`'s private `performance()` is extracted into
`App\Support\PerformanceReport` so the Health dashboard and the snapshot read
one implementation. `cfb:telemetry`'s terminal read prints the actives block
with its `since`.

**`.claude/skills/maintenance-advisor/SKILL.md`** gains rows in its section
table for the five sections, and these reading rules:

- Every analytics section carries `since`, and the `funnel_since` rule
  generalizes: a window whose `since` is inside it is not that window's
  number. Do not file "traffic fell" or "dead screen" until `since` predates
  the window.
- `null` in a rate is "too few to read", never zero. A retention cell of
  null (cohort under 10), an `activated_7d` of null (cohort under 7 days
  old), `quiet: null` — none of these is a finding.
- Compare Saturday to Saturday and week to week (Tuesday to Monday), never
  day to day; check `season.phase` before reading a drop.
- `routes.quiet` is a UX question, not a bug, and only for a screen that is
  linked from somewhere. Quote the route name and the 28-day count; propose
  removing or moving the door, and name the Blade that renders it.
- `pickem_health.late_share` high with `reminder_lift` low is the one
  analytics finding that can earn `high`: the reminder wave is not moving
  people. Quote the slate id, members, entries and both stamps; never a group
  name — the payload does not carry one.
- `errors.client[].route` with `views_24h` turns a count into a rate. Do not
  read a rate under 50 views; a bug on a screen ten people opened is still a
  bug, but its evidence is the `reports` count.
- Evidence, every time: the section path, the window, both sides of any
  rate, and the date —
  `{"section": "audience.adoption", "window_days": 7, "numerator": 3, "denominator": 11, "since": "2026-09-12"}`.
- What NOT to file: device mix (informational), stickiness under 30 people,
  cohort comparisons across the launch date, anything whose remedy is "get
  more users".
- Severity: a participation collapse on a live Saturday (entries near zero
  with kicked games on the slate) is the only analytics finding that may
  reach `critical` — it is "losing a Saturday". Everything else caps at
  `high`. A dead activity drain is `ops` / `high`: it loses telemetry, not
  product data.

No in-app rule files a card. The app computes and exposes; a card needs a
file, a line and a prompt, which only the routine that reads the repository
can write.

**Tests** — `TelemetryTest`: key list updated; the identity sweep extended;
every `since` present; null and not 0 for a cohort of 3 and for `quiet`
before 28 days; `activated_7d` null for a three-day-old cohort;
`errors.client[].route` resolves a group path to `pickem.group` and
`views_24h` is null with no traffic. `tests/Feature/AdvisorSkillTest.php`:
every top-level snapshot key appears in `SKILL.md`'s section table — a
sweep, so a sixth section cannot ship undocumented.

## Phase 5 — ApexCharts, the Analytics group, Overview and Health (L)

**Dependency.** `composer require leandrocfe/filament-apex-charts:^5.0`
(5.1.4 at planning time, released 2026-08-30, declaring
`filament/widgets ^4.0|^5.0`; record the pinned version in the PR). Register
`FilamentApexChartsPlugin::make()` in `AdminPanelProvider->plugins([...])`.
The panel theme scans only `app/Filament/**` and
`resources/views/filament/**`; if the plugin's widget Blade carries classes
the theme must compile, add a vendor `@source` line to
`resources/css/filament/admin/theme.css` and pin it in `PanelThemeTest`
(verify after install — the plugin registers its own CSS through
`FilamentAsset`, so this may be unnecessary). `npm run build` after.

**Brand, dark mode, empty states.** Every `getOptions()` sets
`'colors' => [Brand::color('lager'), …]` — `Brand` is read at request time,
so a rebrand reaches the charts without a rebuild (the `PicksTrendChart`
precedent). Dark mode is on by default in the plugin; verify it follows
Filament's `dark` class, and if not emit the theme mode through
`extraJsOptions()`. Every chart sets `noData.text` to "No data yet"; every
stat prints "no data" when its section's `since` is younger than the window
— never 0.

**Navigation.** A new group **Analytics** (`Heroicon::OutlinedPresentationChartLine`)
inserted after Work in `AdminPanelProvider`; `PanelNavigationTest`'s
expected order changes in the same PR. Four dashboards, each extending
`Filament\Pages\Dashboard` with `getColumns(): 12`, an explicit
`getWidgets()` (never discovery), `HasFiltersForm`, and widgets under
`app/Filament/Widgets/Analytics/` that set `$isDiscovered = false` except the
four that belong on Overview.

| Dashboard | Class | `$routePath` | Filters |
| --- | --- | --- | --- |
| Overview | `Dashboard` (existing; `$title = 'Overview'`, `getColumns()` 12) | `/` | range, staff toggle |
| Audience | `AudienceDashboard` | `/audience` | range, audience, staff |
| Pick'em | `PickemDashboard` | `/pickem` | Saturday (from `Cadence::saturdaysIn()` over the season's weeks), season (`CfbCalendar::currentYear()`) |
| Health | `HealthDashboard` | `/health` | none (24 hours is `OpsReport`'s window) |

Shared filters: `Select::make('range')` with 7d / 28d / 90d / season,
default 28d; `Select::make('audience')` members / guests / all, default
members; `Toggle::make('staff')` default off. Session persistence is the
trait's default. Widgets read `$this->pageFilters` through
`AnalyticsWindow::from()`. Polling is `null` everywhere except widgets marked
live, which override `getPollingInterval()` to `'60s'` when
`Cadence::currentSaturday()` is today and the live window is open;
`$deferLoading` on the heavy ones.

**Overview** (`/admin`, replacing the five current widgets):

1. `ActivesStats` (StatsOverview, span 12) — daily actives today, weekly,
   monthly, stickiness, each with a 14-day sparkline from `user_days`.
2. `TrafficArea` (Apex area, span 8, deferred) — views per day by audience,
   stacked, over the range.
3. `TodayPickem` (StatsOverview, span 4, **live**) — this Saturday's entries,
   members and picks from `LiveState::build()`.
4. `RouteTreemap` (Apex treemap, span 12, deferred) — views by route over the
   range, members only.

**Health** (`/admin/health`) — the operator's view of the snapshot:

- `OpsChecks` — a `TableWidget` over `OpsReport::checks()` in exactly the
  `DataCoverage` shape (badge, label, detail, copyable remedy).
- `IngestBuffers` (StatsOverview) — Pulse stream length, activity stream
  length, last drain, last rollup, from `feed_runs`.
- `ErrorRateByRoute` (Apex horizontal bar, deferred) — catalog row 12,
  floor 50 views.
- `PerformanceTop` (`TableWidget`) — `PerformanceReport` rows.
- `AdvisorLedger` (`TableWidget`) — the last ten `advisor:review` runs and
  open workbook counts by severity (today a single stat on `SyncSpend`).

Sync Health stays as it is (is the DATA there); Health is "is the app
behaving" — `OpsReport`'s own distinction.

The five current widgets move rather than vanish: `UserFunnelStats` becomes
Audience's `LifecycleFunnel`; `EngagementStats` becomes Pick'em's
`PickemStats`; `TopTeamsChart` and `TopGroupsChart` convert to Apex bars on
Audience; `PicksTrendChart` becomes Pick'em's `PicksBySaturday` (unplayed
Saturdays stay absent, never zero — keep that test).

**Tests** — `tests/Feature/Admin/Analytics/OverviewDashboardTest.php`:
`/admin` is 200 for an admin and 403 otherwise; `getColumns()` is 12 (update
`DashboardTest`'s two-column pin); each Overview widget renders through
`Livewire::test()`; `invade(new TrafficArea)->getOptions()['series']` matches
seeded `page_views_daily` rows; `ActivesStats` prints "no data" when
`user_days` is empty, and the fix is broken back by seeding one row and
asserting a number. `HealthDashboardTest.php`: `OpsChecks` renders every
`OpsReport` key including `activity_ingest`; `ErrorRateByRoute` refuses a
rate under 50 views; `PerformanceTop` and the snapshot produce identical rows
for one seeded `pulse_entries` set. Deferred widgets: check whether the
plugin defers through Livewire lazy loading or `wire:init` — for the former,
`Livewire::withoutLazyLoading()` before EVERY render and render twice
(`.ai/rules/tests.md`); for the latter, call its load method in the test.

Verification: `npm run build`; `/admin` and `/admin/health` render in light
and dark; editing App Branding's accent moves a chart's color with no
rebuild.

## Phase 6 — The Audience dashboard (L)

`AudienceDashboard` at `/admin/audience`:

5. `LifecycleFunnel` (Apex funnel, span 6) — registered (from `ux_events`) →
   verified → onboarded → reached Picks → entered → installed, over the
   range, counts printed on the bars.
6. `CohortRetentionHeatmap` (Apex heatmap, span 12, deferred) — rows are
   eight cohort weeks, columns weeks since (0–7), cells are percentages;
   cells with fewer than 10 people render blank and the row label carries
   `n`.
7. `ActivesByCohortArea` (Apex stacked area, span 8) — weekly actives split
   by cohort age: new, one to four weeks, older.
8. `AdoptionRadial` (Apex radial bar, span 4) — share of weekly actives per
   `ActivityFeature`.
9. `DeviceMix` (Apex donuts, span 6) — viewport buckets and installed share,
   with an explicit "not reported" slice.
10. `WeekHeat` (Apex heatmap, span 6, deferred) — hour × weekday from the
    raw table, league time.
11. `TopTeamsBar`, `TopGroupsBar` (span 6 each) — the two existing charts,
    converted.
12. `QuietScreens` (`TableWidget`, span 12, `->records()`) — routes under 5
    views in 28 days, with `since` in the heading.

**Tests** — `AudienceDashboardTest.php`: the page renders; filter defaults
(`range` 28d) through `assertSet('filters.range', '28d')`;
`CohortRetentionHeatmap` returns null cells for a cohort of 3 and a
percentage for a cohort of 12; `LifecycleFunnel` reads registrations from
`ux_events`, not `users` (seed a cohort whose accounts were pruned and assert
the count holds); `WeekHeat` groups by league hour; `QuietScreens` lists a
route with zero rows and prints `since`. `AnalyticsCatalogTest.php`: each
catalog query's denominator and floor, cohort math on the frozen clock.

## Phase 7 — The Pick'em dashboard (M)

`PickemDashboard` at `/admin/pickem`:

13. `PickemStats` (span 12) — today's `EngagementStats` content.
14. `ParticipationBySlate` (Apex grouped bar, span 8) — members, entered,
    complete per slate on the chosen Saturday.
15. `PickTiming` (Apex range bar, span 12, deferred) — for each slate on the
    Saturday, the distribution of `picks.created_at` from publish to first
    kickoff, with the deadline and reminder stamps as `xaxis.annotations`
    through `extraJsOptions()`.
16. `LatePickShare` (Apex column, span 4) — late share per Saturday over the
    season.
17. `PicksBySaturday` (span 12) — the converted trend.
18. `ReminderLift` (StatsOverview, span 12) — entries created after the
    first wave / members without an entry at the wave, per Saturday; "no
    reminder sent" when the stamp is null.

`LiveState::build()` gains `late_share` and `reminder_lift` per slate; the
snapshot's `pickem_health` (Phase 4) reads them, so either land this phase's
`LiveState` change first or have Phase 4 tolerate their absence with null.

**Tests** — `PickemDashboardTest.php`: `ParticipationBySlate` denominators
are members at first kickoff (a member who joined after kickoff is excluded);
`LatePickShare` uses `Cadence::LAST_CALL_MINUTES`; `ReminderLift` prints "no
reminder sent" when `picks_reminded_at` is null. `LiveStateTest`: late share
and lift, and names still off in the machine skin. `pickem:preflight` still
exits 0.

## Phase 8 — The User resource (S)

`UserResource`'s list gains a sortable `last_seen_at` column ("never" when
null); `ViewUser` gains an Activity tab: a 14-day `user_days` strip and the
features seen — never raw routes. `UserAdminTest`: column present and
sortable, the placeholder when null.

## Phase 9 — Privacy copy (S)

There is no policy to amend, so this is new product copy and the wording is
the founder's. A `privacy` `Route::view` (`resources/views/privacy.blade.php`,
app layout, outside every nav area) linked from Account and from the register
screen, stating plainly: screens viewed are recorded by route name, not URL;
device width and whether the app is installed; the raw log is deleted after
30 days; per-day aggregates are kept; everything is deleted with the account.
A `HelpTopics` entry `account.data` points at it.
`tests/Feature/Screens/PrivacyTest.php` renders it for a guest and asserts
the copy names `ActivityEvent::KEEP_DAYS`, so the number in the copy and the
number in the code cannot drift. The register-form link is checked at 390.

## Phase 10 — The cold tier: Cloudflare Pipelines and R2 (L, optional, last)

Why here and not earlier: a different store buys retention, isolation and a
home for logs, not request-path speed, and none of the dashboards need it.
Cloudflare's data platform is the fit — Pipelines accepts events on an HTTP
endpoint (no Worker required), writes them as Apache Iceberg tables into R2
Data Catalog in the account that already holds the app's uploads, and R2 SQL
or DuckDB query them. Workers Analytics Engine was considered and rejected
for this role: it writes only from a Worker, keeps three months, and samples
at volume — right for edge traffic counts, wrong for an archive.

- **Shipping events.** `App\Jobs\ShipActivityBatch`, queued on `default`,
  dispatched from `RecordActivity::drain()` after `insertOrIgnore` succeeds
  and only when `config('services.cloudflare.pipelines.events_url')` is set
  (unset means off — the `OPS_TOKEN` convention). It POSTs the batch as a
  JSON array with a bearer token. Never inline in the drain: a slow HTTP call
  must not stretch the five-minute cadence. A failure is a `feed_runs`
  `job:ShipActivityBatch` row through the existing `Queue::failing` hook and
  nothing else.
- **Shipping logs.** `App\Support\PipelinesLogHandler extends
  Monolog\Handler\AbstractProcessingHandler` (Support, not a new folder)
  whose `write()` does one `XADD cfb:logs MAXLEN ~ 50000` on the `pulse`
  connection with level, channel, message, context as JSON, datetime and
  release — swallow-all, exactly like `RecordUxEvent`. Registered as a
  `monolog`-driver channel in `config/logging.php` and added to `LOG_STACK`
  by a human. The drain ships `cfb:logs` to the logs endpoint the same way.
- **Config.** `config/services.php` → `cloudflare.pipelines.{events_url,
  logs_url, token}` from env, documented in `.env.example` beside the
  existing `CLOUDFLARE_ACCOUNT_ID`; values set by a human in Laravel Cloud,
  never by a session.
- **Tables.** R2 Data Catalog `activity_events` (the raw columns plus `env`)
  and `app_logs`, partitioned by day.
- **Verify before depending on it**: the Iceberg sink is available on the
  account's plan; R2 SQL supports the query shapes wanted (`GROUP BY day,
  route`, `COUNT(DISTINCT …)`; window functions and joins may be absent in
  its current release); per-scan pricing at a season's volume; catalog
  compaction and retention settings; DuckDB's `iceberg` extension reading
  the catalog from a laptop with an R2 token — the real analyst path.
- **Explicitly**: no dashboard, no snapshot section, no test and no
  `OpsReport` row reads R2. Deleting this phase changes nothing above it.
  The 30-day MySQL prune stands whether or not the archive exists.

Tests: `ShipActivityBatchTest` (`Http::fake`, the token header, no dispatch
when the URL is unset); `PipelinesLogHandlerTest` (`XADD` swallow-all; Redis
down throws nothing).

## Phase 11 — Ask the data (M, optional)

The house alternative to an AI data plugin, and already ranked in
`docs/plans/ai-layer.md` ("natural-language ops questions on Sync Health…
admin-only, so volume is trivial"). `App\Ai\Agents\OpsQuestion` follows
`StatQuestion` and `HelpQuestion` to the letter — `#[Provider(Lab::Anthropic)]`,
`claude-haiku-4-5`, structured output — and maps an admin's sentence to ONE
key from `AnalyticsCatalog::keys()` plus a window token. **The model never
emits a number or SQL**; the app runs the named query and renders the answer
with its `since` and denominator. Surface: a header action on
`HealthDashboard` with a text-input modal, visible only when
`config('cfb.ai_enabled')` is true, gated by `AiBudget::allows()` at the
call, spend through `RecordAiSpend::later()`, every failure returning null
and the modal saying "not a question we track". No new route, no external
surface. Tests: the agent fake with `preventStrayPrompts`, budget refused →
null → copy, unknown key → refused.

## The Filament marketplace, surveyed

Searched filamentphp.com/plugins for chart, dashboard and analytics on
2026-09-05/06, then read each plugin's page and, where it mattered, Packagist
and the README.

| Plugin | What it is | Verdict |
| --- | --- | --- |
| **Apex Charts** (Leandro Ferreira, `leandrocfe/filament-apex-charts`) | `ApexChartWidget` with `getOptions()`, `extraJsOptions()` `RawJs`, per-widget filter schemas, polling, deferred loading, dark mode on by default, heading/subheading/footer, content height. 500 stars, 1,873,673 installs, 5.1.4 released 2026-08-30 requiring `filament/widgets ^4.0\|^5.0`. Twenty-plus chart types including funnel, heatmap, treemap, radial bar, range bar, box plot, sparklines and annotations. MIT. | **Chosen.** The mature option, and every chart the story needs is in it. |
| **Apache ECharts** (elemind, `elemind/filament-echarts`) | The same widget shape (`EChartWidget`, `getOptions()`, filters, polling, deferred loading, dark mode). Adds sankey, sunburst, gauge, box plot, calendar and parallel coordinates. Beta, 28 stars, actively committed. MIT. | Runner-up. Revisit only if a chart type is missing; a younger package and a heavier bundle. |
| **Chart Palette** (JCCoca) | `HasChartPalette` trait converting Tailwind 4's OKLCH colors to the RGBA strings Chart.js needs. 2 stars. | Only relevant if staying on Chart.js. Unnecessary with Apex. |
| **Google Charts**, **Google Charts Widgets** (two authors) | Google Charts widgets. | Load Google's loader from a CDN into the admin. Skip. |
| **Custom Dashboards** (official Filament, $89 one-time) | Users build drag-and-drop dashboards over developer-declared `EloquentWidgetDataSource`s, with the query builder for filters; stats, line, bar, pie, doughnut, polar, scatter, tables; sharing and roles; its own migrations. Filament 4 and 5. | A real option for self-serve slicing LATER. It does not design the story; it lets somebody assemble one. Not now. |
| **Data Lens** (Padmission, $99) | Report builder: dynamic columns across relationships, nested filters, aggregations, CSV/XLSX export, scheduled email. Filament 3–5. | Tables-first, not charts-first. Not now. |
| **ClearAnalytics**, **SimpleStats**, **Matomo Analytics**, **Analyze Website**, **Adment**, **TallCMS Pro** | Display panels for an external analytics service (ClearAnalytics.eu, SimpleStats, Matomo, GA4). | Each puts a third-party tracker in the PWA and holds the data elsewhere. Skip. (A zero-code complement, if ever wanted: Cloudflare Web Analytics for traffic and Web Vitals on a proxied zone.) |
| **Vacuum** | PostgreSQL statistics. | Postgres only. |

## Data Copilot (`matondojk/filament-data-copilot`) — assessment

The plugin turns a natural-language prompt into SQL against allowlisted
Eloquent models, runs it, and renders charts, tables, "insights" and PDF
reports inside the panel; providers through the Laravel AI SDK (OpenAI,
Gemini, DeepSeek, Azure, Anthropic). Free, MIT. Recommendation: **do not
adopt it**, for three independent reasons.

1. **It cannot be installed here.** Packagist lists v1.0.32 (2026-08-14)
   requiring `filament/filament ^5.0`, `laravel/ai ^0.10`,
   `barryvdh/laravel-dompdf ^2.0|^3.0`, PHP `^8.1`. This app runs
   `laravel/ai ^0.11.0`. `composer require matondojk/filament-data-copilot
   --dry-run` on 2026-09-05 fails to resolve: every release from v1.0.4 to
   v1.0.32 conflicts on `laravel/ai`, v1.0.1 targeted Filament 3, v1.0.2
   required Illuminate 10/11, and v1.0.3 named a dependency that does not
   exist on Packagist. Adopting it means downgrading the SDK the recap,
   stat-answer, help and GameDay agents run on.
2. **Its architecture is the inverse of the house AI rules.** It sends the
   schema of the allowlisted models to a model that writes SQL, executes
   that SQL, and sends the results back to the model for narrative — so user
   rows leave the database into a third-party model context, and the
   README documents no SELECT-only guard, no row limit and no timeout. The
   standing rule in `.ai/rules/ai-layer.md` is that the model never emits a
   number: it names one entry from a closed catalog and the app runs it
   (`StatQuestion`, `HelpQuestion`). Phase 11 above is that pattern applied
   to analytics, admin-only, gated by the same budget.
3. **Maturity.** 4 GitHub stars, 58 installs, 32 patch releases in five
   weeks that swung from Filament 3 to Filament 5, beta status, a PDF
   dependency (dompdf) the panel has no other use for.

What it gets right is the wish — "ask the data a question inside the admin"
— and that wish is cheaper to grant on the catalog than through generated
SQL.

## Verification (every phase lands green before the next)

In CLAUDE.md's order, per PR:

1. `php artisan test --compact --filter=<the phase's suite>`, then the
   whole suite. Every phase above names the tests it adds; a phase without
   a new or updated test is not done.
2. `vendor/bin/pint --dirty --format agent` when PHP changed. Never
   `--test`.
3. `npm run build` when Blade or the panel theme changed — the panel 500s
   on an unbuilt manifest.
4. The device harness is not needed for admin pages; Phase 9's
   register-form link is checked at 390.
5. Break the fix back on every "no data, never 0" assertion and every
   wrong-default test.

Sequencing: 1 → 2 → 3 must land before any Saturday the founder wants data
for — the rollups have no backfill; a zero day before the sensor shipped is a
`since`, never a row. 4 can land with 3. 5 before 6 and 7. 8 and 9 any time
after 1. 10 and 11 only if wanted.

## Risks / open items

- **Pilot-scale denominators keep most rates null for weeks.** The
  dashboards must read as "not yet", with the `since` date visible, or the
  founder reads them as broken. This is a copy requirement on every widget,
  not a data problem.
- **The Apex plugin's Filament 5 release is a week old at planning time.**
  Confirm on Packagist before Phase 5 that `^5.0` declares Filament `^5.0`
  and Livewire `^4.0`; pin the version in the PR; verify the plugin class
  name, deferred-loading mechanism, dark-mode handling and heatmap `null`
  handling after install (all marked above).
- **`terminate()` timing.** Under FPM with `fastcgi_finish_request` the
  `XADD` runs after the response is flushed; otherwise it rides the response
  tail. Sub-millisecond either way — acceptable, but confirm the SAPI on
  Laravel Cloud so the answer is known rather than assumed.
- **A vendor `@source` line** (if needed) ties the panel theme to the
  plugin's view path; pin it in `PanelThemeTest` so a plugin upgrade that
  moves the views fails loudly.
- **`HasFilters` persists filters in session under an md5 of the class
  name**, so renaming a dashboard class forgets its filters. Harmless; noted
  so nobody files it.
- **The `admin-panel.md` "no new composer dependencies" decision** is
  superseded for exactly one package by the founder's chart decision; every
  other line of that plan stands.
- **The migrations that touch `users`** (`last_seen_at`) must be reversible
  and must not backfill.
- **Privacy wording** (Phase 9) is the founder's decision; the phase ships
  the page and the test, not the final sentence.

## The cards to file

| # | Card | Effort | Depends on |
| --- | --- | --- | --- |
| 0 | This plan document (CFB-66) | S | — |
| 1 | Analytics schema: activity tables, enums, models, factories, prune | S | — |
| 2 | Page-view sensor, `cfb_client` cookie, `RecordActivity`, the six emitters | M | 1 |
| 3 | Activity drain and rollup: commands, `ActivityRollup`, `AnalyticsWindow`, schedule, ledger keys, two `OpsReport` rows | M | 2 |
| 4 | Snapshot sections, `AnalyticsCatalog`, `PerformanceReport`, advisor skill rules, `AdvisorSkillTest` | M | 3 |
| 5 | ApexCharts, Analytics nav group, Overview rebuild, Health dashboard | L | 3 |
| 6 | Audience dashboard | L | 4, 5 |
| 7 | Pick'em dashboard and `LiveState` late share / reminder lift | M | 5 |
| 8 | User resource: `last_seen_at`, Activity tab | S | 3 |
| 9 | Privacy page and copy | S | 1 |
| 10 | Cold tier: Cloudflare Pipelines and R2 | L | 3 |
| 11 | Ask the data: `OpsQuestion` | M | 4, 5 |
