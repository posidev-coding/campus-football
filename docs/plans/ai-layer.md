# AI layer — stat answers, personalized recaps, and a maintenance advisor

> **Handoff note for a fresh session.** This plan is self-contained. The **only**
> prerequisite is that the launch-hardening worktree is committed and merged —
> it does **not** wait for the Sep 1 flip or the Sep 5 public Saturday. Build it
> immediately. Every product decision recorded here is user-approved; implement
> phase by phase and do not re-ask anything written down. Findings carry
> `file:line` anchors verified 2026-08-24 — trust them, but re-read each file
> before editing it, since launch hardening touched many of them after this plan
> was written.
>
> **Why the urgency is real:** the user and a second admin are exercising the UI
> heavily this week. Telemetry cannot be captured retroactively — every hour
> Phase 1 is not installed is an hour of bugs, slow queries, and friction that
> is gone for good. Phases 0–3 are the ones to land first, and they touch no
> product surface.
>
> Read `@.ai/rules/index.md` and every rule file whose globs cover the paths in
> scope before writing code. The rules most likely to bite here are
> `services.md` (cache key versioning), `support.md` (never cache a non-scalar),
> `commands.md`, `copy-and-voice.md`, and `tests.md`.

## Context

Campus Football is feature-complete for launch (Phases 1–6 shipped; flip Sep 1,
first public Saturday Sep 5). A second session is landing the launch-hardening
plan. This plan adds a deliberately small AI layer on top of the finished
product, in three parts the user asked for:

1. **Stat answers** — "How many passing yards did Brandon Faizon throw for last
   week?" answered inside Search.
2. **Personalized weekly recaps** — the Tuesday newsletter written in the
   reader's own `ContentRating` register instead of assembled from a template.
3. **A maintenance advisor** — a scheduled routine that reads real telemetry,
   proposes categorized work, scaffolds the Claude Code prompt for each item,
   and tracks it on a Kanban board.

Budget target: **$20–25/month**. The plan comes in at roughly **$6–10/month at
pilot scale**, with the ceiling enforced in code rather than hoped for.

### The finding that reorders everything

The user's stated priority for the advisor is *"finding bugs and constantly
optimizing the user experience based on real logs, usage."* **That data does not
exist yet.** There is no APM, no Pulse, no Telescope, no Sentry — zero hits
across `composer.lock`. `config/logging.php` is stock; the whole app makes 16
`Log::` calls. The only durable structured error store is `feed_runs`, which
covers scheduled sync commands and is pruned at 14 days. On Laravel Cloud,
failed *queue jobs* are not readable from app code at all — `RecentSyncFailures`
says so in its own description string. There is no page timing, no client-side
error capture, and no funnel instrumentation anywhere.

So the advisor's recommendation quality is bounded by its sensors, not by its
model. **Phase 1 builds the sensors**, and every piece of it is worth having
even if the AI half were deleted.

### Should you buy an APM? — yes to a tool, no to a bill, for now

Researched properly, because it changes Phase 1.

**[Laravel Nightwatch](https://nightwatch.laravel.com/pricing)** is the
first-party APM and it is a genuinely better *product* — hosted, retained,
alerting, and it monitors requests, queries, exceptions, jobs, scheduled tasks,
outgoing calls, mail, and cache. Two things argue against it *right now*:

- **Price vs. this budget.** Free is 300k events/month; Pro is **$20/month** for
  7.5M. An "event" is each request *and* each query, job, cache hit, and
  outgoing call it emits. This app is unusually event-dense for its user count —
  `cfb:games --tier=live` runs **every minute from 11:00 to 03:00**,
  `cfb:summaries:live` every two, kickoff alerts every five, reminders every
  fifteen — so scheduled commands alone plausibly clear the free tier before a
  single user loads a page, and Saturday `wire:poll.30s` traffic on live games
  is the same story again. Realistically that means Pro, and **$20/month is the
  entire AI budget**.
- **Its MCP server is narrower than it first appears.** Nightwatch does ship an
  [MCP server](https://nightwatch.laravel.com/docs/mcp-server) that Claude Code
  connects to with one command — which sounded like the perfect advisor feed.
  But it exposes **issues and exceptions only**: list applications, browse
  issues, view stack traces, update status, add comments. No slow requests, no
  queries, no jobs, no scheduled tasks. So it would deliver the advisor's
  bug signal and none of its performance or UX signal.

**Recommendation: [Laravel Pulse](https://github.com/laravel/pulse) (free, MIT,
first-party) now; revisit Nightwatch after launch.** v1.8.0 supports Laravel 13
and Livewire 4, so it drops straight in. It records slow requests, slow queries,
slow jobs, slow outgoing requests, exceptions, cache stats, and usage by user —
and the decisive advantage for *this* architecture is that **Pulse's data lives
in your own database**, so `cfb:telemetry` reads it directly. No API, no MCP, no
rate limit, no bill, and it preserves the full $20–25 for the AI itself.

Pulse replaces hand-built sensors 1.1 and 1.3 below and most of 1.5. It does not
cover client-side JS errors or product funnel events — those stay hand-built,
because they are app-specific and no APM would capture them anyway.

Reassess Nightwatch once there is real traffic to size events against. If the
pilot grows and exception triage becomes the bottleneck, $20/month plus its MCP
server is defensible — but buying it *before* there are 75 users, at the cost of
the entire AI budget, is the wrong order.

Do **not** put Telescope in production; it is a development debugger and writes
enormous volume.

> ⚠️ **Verify during implementation.** Pulse writes to MySQL
> (`pulse_entries`, `pulse_aggregates`, `pulse_values`). Set
> `PULSE_INGEST_DRIVER=redis` so writes buffer through Redis, and tune the
> per-recorder `sample_rate` down before enabling on production. Confirm the
> `pulse:work` drain daemon is viable on Laravel Cloud alongside the existing
> three managed queues (**max 2 workers each**) — if not, fall back to the
> `storage` driver with aggressive sampling.

### Answering "which approach yields better recommendations?"

Two options were on the table: a scheduled **Claude Code** routine, or an
in-app **Laravel AI SDK** agent. They cost about the same (~$0 on the Claude
subscription vs ~$1.50/month on the API for telemetry-only). Cost is not the
deciding factor — **repository context is**.

A telemetry-only agent can say *"the picks screen is slow and three people
abandoned it."* Only an agent that can read the code can say *"`/picks` N+1s on
`slate.games.team` at `pickem-home.blade.php:212`; here is the eager load, here
is the prompt to hand a Claude Code session."* Since the ask is explicitly bugs,
root causes, and scaffolded prompts, the repo-aware routine wins decisively —
and stuffing enough of the repo into an API call to close that gap costs more
*and* performs worse than an agent that greps.

**Recommendation: Claude Code routine as the brain, Laravel as the sensor and
the workbook.** The app owns telemetry, the `workbook_items` table, and the
Kanban; the routine reads a telemetry snapshot, reads the repo, and files items.

Note the two billing surfaces are different: the Claude Console account being
set up provides the **API key** for parts 1 and 2. The advisor routine runs on
the **Claude subscription** and does not touch that budget.

---

## The load-bearing design decision: the model never emits a number

The app's first non-negotiable is *"never write a default when data is missing"* —
the mistake that broke three previous versions. An LLM stating a stat is that
mistake with better grammar. So for stat answers:

- The model's **only** job is to turn a question into a structured **intent**
  (`{intent, subject, category, stat, scope, year, week}`) via structured output.
- The **app** resolves the subject (`Search::players()`), resolves the year
  (`CfbCalendar::resultsYear()`), runs the query, and **renders the number in
  Blade**.
- Any unresolved piece → an honest "I don't have that", never a substitute.
  `intent: 'unsupported'` → normal search results and nothing else.

This makes fabrication structurally impossible rather than merely discouraged,
and it collapses cost: one small call instead of a multi-turn tool loop, which
is why the Q&A line comes in under $1/month. It also means Haiku 4.5 is safe
here — the model is classifying, not asserting.

Search is a **PURE** surface (`.ai/rules/copy-and-voice.md`), so the answer
itself stays factual and un-voiced. Only the chrome around it — the "couldn't
find that" line — carries voice.

---

## Dependencies

Two additions, both needing explicit approval.

**`laravel/pulse` ^1.8** — free, MIT, first-party. v1.8.0 requires
`livewire/livewire ^3.6.4|^4.0` and `illuminate/* ^13.0`, so it fits this stack
as-is. Rationale in the APM section above.

**`laravel/ai` 0.11.*** — v0.11.0 is the latest, released 2026-08-19. Pre-1.0:
47 releases in 6.5 months, with breaking changes on minors. **Pin the minor.**

Risk is contained because this plan uses a **small, stable subset**: agents,
structured output, the testing fakes, and the usage events. It deliberately does
**not** use conversations (skipping the `agent_conversations` migration and its
`TEXT`/64KB and `unsignedBigInteger` participant constraints), streaming (there
is no Livewire integration in the SDK — zero matches for "livewire" in 3,009
lines of docs), SDK tool-calling, MCP, or embeddings.

Two verified doc bugs to route around:

- **Anthropic prompt caching does not work as documented.** `providerOptions` is
  merged flat into the request body (`Gateway/Anthropic/Concerns/BuildsTextRequests.php`),
  but Anthropic requires `cache_control` on individual content blocks. The
  documented top-level recipe is a no-op. Unmerged branches `prompt-cache` and
  `prompt-cache-breakpoints` exist. **Do not build cost projections on caching.**
  The estimates below assume none. (`Usage` *does* read `cacheReadInputTokens`
  back, so measurement is already wired for when it lands.)
- The docs' `thinking` example omits `type`. Use the package's own test fixture
  form if thinking is ever needed. It is not needed here.

Always use `#[Model('...')]` explicitly — `UseCheapestModel` / `UseSmartestModel`
are documented as unstable across releases.

---

## Performance posture — Redis first, in every environment

Standing directive: **prioritize performance, and use Redis wherever a service
benefits — locally as well as in production.** Local and production must agree,
because a driver that only differs locally is a bug you cannot reproduce.

### Where things stand today

Already correct, leave alone:

| Concern | Driver | Connection |
| --- | --- | --- |
| Cache + locks | redis | `cache`, **DB 1** (`CACHE_STORE=redis`) |
| Sessions | redis | `default`, **DB 0** (`.env.example` already ships this — `docs/operations.md`'s table saying MySQL is **stale** and should be corrected) |
| Queue | redis locally, `cloud` in prod | `default`, DB 0 |

Must stay MySQL — Laravel requires a database for both, and moving them is not
an option: `job_batches` (batching) and `failed_jobs`.

### What this plan adds

**A third Redis connection, `pulse`, on DB 2.** Add it to `config/database.php`
beside `default` and `cache`, inheriting the same phpredis client, `max_retries`,
and `decorrelated_jitter` backoff. Its own database rather than sharing `cache`
DB 1, because the codebase deliberately runs `cache:clear` at times — the
services rule warns it *"also re-arms the mail/SMS budgets and the ESPN
limiter"* — and buffered telemetry must never be collateral damage.

**Pulse ingests through Redis in every environment.**

```
PULSE_INGEST_DRIVER=redis
PULSE_REDIS_CONNECTION=pulse
```

This keeps Pulse off the request path entirely: recorders push to a Redis stream
and `pulse:work` drains to MySQL out of band. On an app whose live sync runs
every minute from 11:00 to 03:00 and whose Saturday traffic is `wire:poll.30s`,
synchronous `storage`-driver writes would be a self-inflicted wound.

- **Locally:** add `php artisan pulse:work` to the `concurrently` list in
  `composer.json`'s `dev` script, alongside server / queue / pail / vite.
- **Production:** a Cloud daemon. Verify it fits beside the three existing
  managed queues (**max 2 workers each**); if a fourth process is a problem,
  the fallback is the `storage` driver with `sample_rate` turned well down.
- Tune `sample_rate` per recorder before the first live Saturday.

**Funnel events never touch MySQL on the request path.** `ux_events` (1.4) is a
bounded vocabulary of counters, so it wants Redis hash counters and a nightly
job that persists rollups — not a row per event. Cheaper, and it removes the
write entirely from the pick and onboarding paths, which are the two most
latency-sensitive flows in the product.

**Client errors dedupe in Redis before they reach MySQL.** One bad deploy is
thousands of identical `window.onerror` posts. Fingerprint → Redis counter with
a TTL → write one row per fingerprint per window. Redis is also the rate limiter
on that endpoint.

**Stat-answer intent caching and per-user question caps ride Redis** through the
existing cache store — `RateLimiter` already does, at no extra wiring.

**Fire-and-forget the AI spend ledger write** with `Illuminate\Support\defer()`
so recording usage costs the response nothing.

> ⚠️ **The trap that makes all of this fail on the second request, not the
> first.** `.ai/rules/support.md`: never put anything but a plain scalar or array
> into the cache. Eloquent models and Carbon instances round-trip out of Redis as
> `__PHP_Incomplete_Class`. A test for this class of bug **must call twice** —
> the first call is served from memory and passes regardless. Every buffer added
> above stores plain arrays. Relatedly, `weekReleases()` already returns
> `starts_at` as a **unix int, not Carbon**, for exactly this reason.
>
> And never `Cache::remember` an empty list that gates a screen — use
> `App\Support\Remember::filled()`. Production once served an empty season menu
> for an hour because `[]` was cached as authoritative during a backfill.

### Deliberately not doing now

- **Laravel Octane.** The largest single throughput win available, but Livewire's
  `wire:stream` is incompatible with it, and it changes the runtime model
  wholesale. Wrong thing to introduce in the weeks around a launch. Revisit once
  the season is under way.
- **Moving Scout off the `database` driver** to Meilisearch or Typesense. A real
  search-latency win and it would help the stat-answer path, but it is another
  hosted service and another bill. Out of scope; worth reconsidering if Search
  becomes a hot path once the Q&A ships.
- **Reverb Redis scaling** (`REVERB_SCALING_ENABLED`). Only matters above one
  Reverb server, and only the default private user channel is registered today.

---

## Cost model

Live pricing, verified 2026-08-24. Note Sonnet 5's $2/$10 is now **permanent**,
not introductory. Sonnet 5 uses the newer tokenizer (~30% more tokens per unit
of text); Haiku 4.5 uses the older one. Both are accounted for below.

At 75 users (top of the pilot range):

| Line | Model | Calls/mo | ~in | ~out | $/mo |
| --- | --- | ---: | ---: | ---: | ---: |
| Stat answers (**5/user/week**) | Haiku 4.5 | 1,629 | 1,800 | 150 | **$4.15** |
| Weekly recaps | Sonnet 5 | 323 | 3,900 | 520 | **$4.20** |
| Notification copy pool (build-time) | Sonnet 5 | 12 | 4,000 | 3,000 | **$0.46** |
| Bear taunts | Haiku 4.5 | 60 | 2,000 | 600 | **$0.30** |
| College GameDay (incl. web search) | Sonnet 5 | 17 | 8,000 | 300 | **$0.49** |
| Maintenance advisor | Claude Code | 4–8 | — | — | **$0.00** |
| Application monitoring | Pulse (self-hosted) | — | — | — | **$0.00** |
| | | | | **Total** | **~$9.60** |

Rates: Haiku 4.5 $1/$5 per MTok · Sonnet 5 $2/$10 · Batch API halves both ·
cache read 0.1× · **web search $10/1,000 searches — used only by Phase 7**,
where it is both mandatory (it is the anti-hallucination guard) and negligible
at ~17 searches a month.

Stat answers are budgeted at **5 per user per week** on the Tuesday-to-Tuesday
pick'em cadence — 1,629 questions a month across the pilot. The real figure will
land lower: the intent cache collapses identical questions, and during a pilot
where everyone is asking about the same Saturday, that overlap is large.

**Headline: the pilot fits in under $10/month; $20–25 is 2–3× headroom.**
Running the stat answers on Sonnet 5 instead of Haiku puts it near $16 — still
inside budget. A 5× usage spike on Haiku lands near $25, which is exactly where
the enforced ceiling in Phase 6 earns its keep.

The real budget risk is not steady state — it is a retry storm or a runaway
loop. Hence the enforced ceiling below, which follows the house pattern already
set by `mail_daily_budget`, `sms_daily_budget`, and `ESPN_RATE_LIMIT`
(*"the budget is ours, not theirs"*).

---

## Phase 0 — Claude Console setup (do this first, ~10 minutes)

Yes — the app only needs an API key. But **do three things before generating
one**, because the second is what actually enforces the $20–25 budget.

**1. Buy credits.** [Settings → Billing](https://platform.claude.com/settings/billing).
The Console is prepaid — requests fail until there is a balance. Start small;
the whole pilot is projected at ~$6/month. Leave auto-reload off at first so a
mistake cannot quietly re-buy.

**2. Set your own spend limit — this is the important one.** Same Billing page,
**Spend limits → Set limit**. Enter `25`.

The Start tier's *own* cap is **$500/month**, which is 20× the budget and no
protection at all. A self-set limit is the real ceiling. The two limits fail
differently, and the app must tell them apart:

| Limit hit | HTTP | Signature |
| --- | --- | --- |
| Your own limit | **400** | `invalid_request_error`, message opens *"You have reached your specified API usage limits"* |
| Tier cap ($500) | **429** | `error.details.error_code = enforced_spend_limit_reached`, **no `retry-after`** — SDK auto-retries will all fail |

Neither is a bug. Both must route to the deterministic fallback, not an error
page. Add this to the Phase 4 and Phase 5 error handling.

**3. Create a workspace.** [Settings → Workspaces](https://platform.claude.com/settings/workspaces)
→ `campus-football`. Worth the extra minute because **you cannot set limits on
the default workspace** — a named workspace is what unlocks a per-project spend
and rate cap, so the app can never eat a budget meant for something else. The
`anthropic-workspace-id` response header confirms which workspace a request
billed to.

**4. Then the keys.** [Settings → Keys](https://platform.claude.com/settings/keys),
scoped to that workspace, with an expiration. Make **two** — one for local dev,
one for production — so either can be rotated alone:

```
ANTHROPIC_API_KEY=sk-ant-...   # .env locally; Laravel Cloud env var in prod
```

Never commit it; `laravel/ai` reads it from `config/ai.php` →
`env('ANTHROPIC_API_KEY')`.

**Worth knowing:** [Playground](https://platform.claude.com/playground) lets you
try prompts in-browser before writing code — useful for tuning the recap voice.
The [Usage page](https://platform.claude.com/usage) shows cost by model and
cache hit rate. New organizations may start in the **Evaluation tier** with
limits below Start while history builds; they rise automatically. Either way,
Start tier is 1,000 RPM and 2M input tokens/minute on Haiku 4.5 and Sonnet 5 —
orders of magnitude beyond what 75 users generate, so rate limits will not be a
constraint here.

---

## Phase 1 — Sensors (build first; valuable with or without AI)

Every piece mirrors an existing shape in the codebase.

**1.1 Install Laravel Pulse, ingesting through Redis.**
`composer require laravel/pulse`, publish, migrate, add the `pulse` Redis
connection on DB 2, set `PULSE_INGEST_DRIVER=redis` **locally and in
production**, and run `pulse:work` in both — full detail in the performance
section above. Enable the Slow Requests, Slow Queries, Slow Jobs, Slow Outgoing
Requests, Exceptions, and Usage recorders. This covers server-side performance
and exception capture with no code of ours, and — because it lands in our own
MySQL — the advisor reads it directly.

Pulse is also what catches an N+1 that `Model::preventLazyLoading()` cannot: per
`.ai/rules/tests.md`, the per-instance flag is false in tests, so **no feature
test ever catches a missing eager load** — a `<x-game-card>` in a rail panel
shipped a 500 on `/rankings` through a fully green suite. Slow Queries in
production is the only detector that class of bug has.

Gate the `/pulse` dashboard behind the existing `User::isAdmin()`.

**1.2 Client error capture.** `window.onerror` + `unhandledrejection` POST to a
Redis-rate-limited endpoint that dedupes by fingerprint in Redis before writing
`client_errors`. **No APM covers this** — it is the class of bug a 390px PWA
ships silently, and it is currently invisible.

**1.3 Queue-failure capture.** A `Queue::failing()` listener writing a
`feed_runs`-shaped row. Pulse's Exceptions recorder catches thrown exceptions,
but Cloud's managed queues hide the *failed job record* from the app entirely
(`RecentSyncFailures` says so in its own description), so this stays hand-built.

**1.4 UX funnel events.** `ux_events` with a **bounded, named** event vocabulary
(~8: onboarding step reached, team picker completed, invite link opened,
registration completed, slate entered, first pick made, slate abandoned with
zero picks, tour dismissed). **Redis hash counters on the request path, nightly
job persists the rollup** — no row per event, no MySQL write in the pick or
onboarding flows. Aggregate only, no free-text. This is the "UX friction" signal,
and no off-the-shelf APM can produce it because the events are specific to this
product.

**1.5 `App\Support\OpsReport`.** A third report class in the established shape.
`CoverageReport` and `PickemPreflight` already agree on
`{key, label, status: ok|warn|fail, detail, remedy}` — `PickemPreflight`'s
docblock says it is *"shaped like CoverageReport on purpose."* `OpsReport` makes
it three, aggregating Pulse's aggregates plus 1.2–1.3.

**1.6 `cfb:telemetry --json`.** One command emitting the snapshot: `OpsReport`,
`CoverageReport::checks()`, `SyncSchedule::tasks()`, recent `feed_runs` errors,
Pulse's slow-request / slow-query / exception aggregates, client errors, funnel
rollups. Aggregate only, no user identifiers.

Files: `app/Support/OpsReport.php`, `app/Console/Commands/TelemetryCommand.php`,
`app/Listeners/RecordJobFailure.php`, `app/Actions/RecordUxEvent.php`,
`config/pulse.php`, new migrations, `routes/console.php` (nightly rollup —
ungated, `withoutOverlapping()`, `->timezone($tz)`, riding the existing
04:00–07:00 wake rather than adding one; a scheduled task holds a scale-to-zero
cluster up for the whole sleep timeout).

---

## Phase 2 — The workbook and the Kanban

**2.1 `workbook_items`** — `key` (unique, stable slug), `title`, `body`,
`category` (bug · feature · performance · ux · data · ops · tech-debt),
`severity`, `status` (inbox · planned · in_progress · done · dismissed),
`evidence` (json), `prompt` (the scaffolded Claude Code prompt), `source`,
`first_seen_at`, `last_seen_at`, `position`.

The advisor re-proposes the same thing every week, so **the unique `key` is the
idempotency**, exactly as `GrantWalletEntry`'s keyed wallet entries work:
`updateOrCreate` bumps `last_seen_at` and refreshes evidence, never duplicates,
and **never resurrects a `dismissed` item**. Current board state is fed back into
the prompt so the advisor stops re-proposing what was dismissed.

**2.2 The board lives in Filament** (per the user's direction). That is the right
home, and it removes a problem rather than creating one: `ChromeConsistencyTest`
excludes `filament/` from its sweeps on the stated reasoning that *"holding an
admin table to the phone-first no-horizontal-scroll rule would be enforcing the
right rule on the wrong product."* A Kanban needs horizontal scroll, so putting
it in the panel means **no test allowlist edit and no weakened sweep**.

**The prerequisite: register a Filament theme.** The panel deliberately does not
load `resources/css/app.css` — a constraint documented in three places — so
Tailwind utilities written in an admin view today have **no definitions behind
them**, which is why Sync Health is built entirely from Filament's own widgets
and `->records(array)` tables. The codebase's own note says it outright:
*"Anything genuinely custom needs a Filament theme registered first."*

So: register a custom theme on `AdminPanelProvider`, giving the panel its own
compiled Tailwind. One-time infrastructure, and it is the thing that unblocks
**all** future custom admin UI.

> **This connects to the unfinished admin console.** The user flagged that the
> Filament panel was never finished and deserves its own planning session — that
> is right, and this plan does not attempt it. But the theme registration above
> is the shared prerequisite for both, so it is worth doing here and inheriting
> there. Everything else stays in its own lane.

**Two surfaces over one model**, mirroring the codebase's existing "one report
object, two surfaces" pattern (`CoverageReport` → `cfb:doctor` + the
`DataCoverage` widget):

- **`WorkbookResource`** — a standard Filament Resource: table, filters by
  category / severity / status, search, bulk actions, and a detail view showing
  the evidence and the scaffolded Claude Code prompt with copy-to-clipboard.
  This is where real triage happens; a Kanban is bad at search and bulk edits.
- **`Workbook` custom Page** — the board itself, columns by `status`, drag via
  Livewire 4's `wire:sort`, following the pattern already working in
  [account.blade.php:988](resources/views/livewire/account.blade.php#L988) and
  `app/Actions/ReorderFollowedTeams.php`. Note `wire:sort` reports **one** item
  and its new index, not the whole list.

Flux is **not** available inside the panel even with a theme — `<flux:kanban>`
needs Flux's own CSS and JS bundles. Little is lost: it is purely presentational
anyway (`flex gap-4` on the wrapper, `rounded-lg w-80` on the column), so plain
Tailwind plus `wire:sort` reproduces it.

While in here, give the panel **navigation groups** — there are none today, the
sidebar is flat, and Workbook / Sync Health / Branding / Pick'em Settings should
not compete at one level.

**2.3 Advisor run ledger.** Reuse `feed_runs` with `command = 'advisor:review'`
via `App\Console\Concerns\TracksFeedRun::trackRun()`, plus a `ledgerKey()` case
in `app/Support/SyncSchedule.php:118` — that buys Sync Health visibility for free.

---

## Phase 3 — The maintenance advisor

A scheduled Claude Code cloud routine, weekly (Monday, so the board is fresh
before the week's work) plus a light daily pass during the season.

Its loop: fetch the telemetry snapshot → read the repo, `git log`, and `.ai/rules/`
→ read current board state → propose or update items → scaffold a Claude Code
prompt per item → file them.

Two small HTTP surfaces are required, since a cloud routine has no database
access:

- `GET /ops/telemetry` — signed route **plus** an `OPS_TOKEN` header. Returns the
  `cfb:telemetry --json` payload. Rate-limited, aggregate-only.
- `POST /ops/workbook` — token-guarded, idempotent by `key`, rate-limited,
  writes only to `workbook_items`.

> ⚠️ **Needs review.** These are the only new externally-reachable surfaces in
> the plan. Both need tests covering rejection of unsigned/untokened requests,
> and the telemetry payload needs an explicit assertion that it carries no user
> identifiers.

An alternative that avoids the write endpoint entirely: the routine commits
`.workbook/proposals.json` and opens a PR, and a `workbook:sync` command imports
it on deploy. Safer, more auditable, but every item waits for a deploy. The POST
endpoint is recommended for a solo operator; the PR path is the fallback if the
write surface is unwanted.

---

## Phase 4 — Weekly recaps

Host: the existing Tuesday newsletter. `App\Support\WeeklyDigest::for(User, ?Carbon)`
already returns `{teams, since, has_results}` and is built **per user, not per
team** so one bad row cannot cost everyone else their email. Pipeline is
`cfb:newsletter` (Tue 08:00, ungated) → `SendWeeklyNewsletter` (batched, carries
the daily-mail-budget middleware) → `WeeklyNewsletter` (deliberately not
`ShouldQueue`).

The AI call goes **inside the job**, before the notification is built. Structured
output `{headline, body: string[]}` so the mail template keeps control of layout.

Guards, in order of importance:

1. **`Voice::line()` must be passed `for: $user`.** It falls back to
   `auth()->user()`, which is null in a queued job — silently rendering PG-13 to
   every reader including PG ones. `app/Notifications/WeeklyNewsletter.php` calls
   this out explicitly and is the reference implementation.
2. **Generate in the reader's own register**, with 6–10 exemplar lines from
   `Voice`'s existing map for that register as few-shot. This is what makes it
   sound like the app, and it costs almost nothing.
3. **Roast the pick, the team, the record — never the person.** The App Store age
   rating depends on this. A deterministic post-generation sweep checks for
   second-person attacks, banned terms, length, American spelling, and the word
   "Georgia" outside live data (`GuidedTourTest` already sweeps tour copy for it;
   the pilot audience is Tennessee alumni).
4. **On sweep failure or API error, fall back to the existing deterministic
   `WeeklyDigest` copy** — real content, never an invented substitute.
5. Skip entirely when `has_results` is false.

Timing is already correct: Tuesday 08:00 is after `Cadence::officialFinal()`
(Sunday noon ET), the stat-settling window that exists because ESPN corrects
totals hours after a game. A recap generated before that can state a number that
later changes.

---

## Phase 5 — Stat answers in Search

`App\Support\Search` is Scout on the **database** driver with domain-relevance
ordering baked into each callback — `Search::players()` is exactly the right
name-resolution tool for "Brandon Faizon".

Flow, additive to today's behavior:

1. Query looks like a question (contains `?`, opens with an interrogative, or
   exceeds N words) **and** direct search found no strong match → fire the
   classifier. Otherwise, today's Search runs unchanged.
2. `StatQuestion` agent (Haiku 4.5, structured output) returns the intent object.
3. App resolves and queries. Rendered by Blade, above the normal results, which
   still render.

Traps that must be respected in the query layer:

- **`CfbCalendar::resultsYear()`, not `currentYear()`.** Today (2026-08-24)
  those are 2025 and 2026 — `currentYear()` has no games played.
- **Stats live in JSON columns keyed by ESPN's names** (`passingYards`,
  `netPassingYards`), not columns. `display_stats` holds presentation order
  because MySQL reorders JSON keys on write.
- **`interceptions` exists in both the `passing` category (thrown — bad) and the
  `interceptions` category (caught — good).** Same key, opposite meaning. Always
  carry category + stat as a pair, or the feature ranks quarterbacks by how often
  they were picked off and calls them leaders.
- **`athlete_season_stats` reads `season_type = FULL_SEASON` (0)** for
  leaderboards — regular-season-only looks "wrong" beside ESPN.
- "Last week" has no method. Compose it — `Cadence::currentSaturday()->subWeek()`
  is the pick'em-shaped answer; `CfbCalendar::weekReleases()` is the scroller's.
  `week()` returning null is a legitimate answer, not an error.
- Precedent for this exact lookup class already exists at team level:
  `TiebreakerMetric::teamStat()`, including its `first()`-not-`value()` note.

> ⚠️ **Needs a decision — "game quality" is two different things.**
> `game_predictors.game_quality` is ESPN's *retrospective* score (absent on
> unplayed games); `matchup_quality` is forward-looking; and
> `App\Services\Contests\GameQualityScore::for()` is the app's own 0–100
> slate-suggestion signal that returns **null, not 0.0**, when there is no usable
> line. No per-team season average exists for any of them.
> Recommendation: a user asking "Ohio State's average game quality this season"
> means the retrospective ESPN metric averaged over completed games. Name them
> distinctly in the intent schema so they can never be conflated.

> ⚠️ **Needs a decision — guests.** Search is open to guests, and
> *"reading is never gated"* is a project value. But an answer is a computation,
> not a reading, and it costs money. Recommendation: signed-in users get the
> answer path; guests get today's Search, unchanged.

**Cost control:** normalize the question text → hash → cache the resolved intent
for 24h using `App\Support\Remember::filled()` (never `Cache::remember` an empty
result — production once served an empty season menu for an hour that way). A
75-person pilot all asking about the same Saturday collapses to one API call.
Plus a per-user daily cap via `RateLimiter`.

---

---

## Phase 6 — The enforced budget

- `config/cfb.php`: `ai_monthly_budget` (USD), `ai_enabled`. Config, not `env()`
  directly, so it is an environment change with instant rollback.
- `ai_spend` table fed by the SDK's usage events — model, feature tag, input /
  output / cache tokens, computed cost.
- `App\Jobs\Middleware\AiBudget`, mirroring the existing mail/SMS budget
  middleware.
- A Filament widget on Sync Health showing month-to-date spend against budget,
  built as a `StatsOverviewWidget` (Filament's own components only — no Tailwind
  in the panel), with `$isDiscovered = false` so it stays off the Dashboard.
- Pennant flags `ai-answers` and `ai-recaps`, **defined as closures reading
  config** the way `pickem` does. Never resolve Pennant inside a sweep — the
  database driver persists a row per resolve; mirror the config value instead.
  Flipping a flag requires `pennant:purge <flag>`.

---

## Phase 7 — College GameDay

**The premise is correct: ESPN's feeds do not carry this.** Nothing in the four
synced hosts exposes where GameDay is broadcasting from. It is the one weekly
fact in the product that no feed provides — which makes it the single best
justification for the AI SDK in the whole plan, because here the model is
*legitimately* the data source rather than a narrator over one.

That also makes it the **riskiest** feature here, and it inverts the Phase 5
rule. Everywhere else the model never emits a fact. Here it must. So the guards
carry the weight instead.

### The routine

An in-app `laravel/ai` agent (**not** a Claude Code routine — this is production
data that must land in the database and render on a user-facing screen), using
Anthropic's **`WebSearch` provider tool** with structured output:

```
{ site, city, state, host_team_name, game_hint,
  announced: bool, confidence, source_url }
```

Scheduled daily around 09:00 ET, **Sunday through Thursday, in-season only**,
and it **stops for the week the moment a Saturday is confirmed** — so a normal
week costs one or two runs, not five. Idempotent on `(season_year, saturday)`,
the same keyed-idempotency the wallet entries and the workbook use.
`Cadence::currentSaturday()` names the target. Gate on
`CfbCalendar::phase()->isLive()`.

### The guards, which are the actual feature

1. **Search is mandatory; parametric memory is not a source.** A response with no
   `source_url`, or one the search did not return, is discarded as unknown. The
   model may not answer from what it remembers.
2. **The site must resolve to a `Team` we already hold**, via `Search::teams()`.
   An unresolvable campus is unknown, never displayed as fact.
3. **The contradiction check — the strongest guard, and free.** GameDay
   broadcasts from a campus hosting a game. If the named host team has **no home
   game that Saturday** in our own `games` data, the answer contradicts the
   database and is rejected. This is deterministic, costs nothing, and catches
   the most likely hallucination outright.
4. **Scope the search to football and to the specific date.** There is a
   basketball College GameDay; an unscoped query will happily return it.
5. **Unknown is a first-class state, never a guess.** Failing every check writes
   `status: unknown`, and the card says so. This is the *"never write a default
   when data is missing"* rule applied to a feature whose whole job is producing
   a value — which is exactly why it needs saying here.
6. **Admin override in Filament.** When the routine fails, a human types the
   campus in ten seconds. Same philosophy as the commissioner owning the line: an
   automated suggestion, a human with the final word. Confirmed rows are never
   overwritten by a later run.

### Storage

`gameday_weeks` — one row per Saturday: `season_year`, `saturday`, `site`,
`city`, `state`, `team_id` (nullable), `game_id` (nullable), `status`
(`unknown` · `proposed` · `confirmed`), `confidence`, `source_url`,
`announced_at`, `checked_at`. Unique on `(season_year, saturday)`.

Historical GameDay sites are a genuinely nice dataset and a one-time backfill
run could seed prior seasons — worth doing once the live path is proven, not
before.

### The card

`<x-gameday-card>` on Home, in **one fixed slot** below the team-news block and
above `<x-pickem-teaser />` — with "your teams" above it and the league's
Saturday below, which is where the week's headline belongs. Same slot in every
state, so nothing reflows.

Four states:

- **Confirmed** — site, host team, the game and its kickoff, linked to the game
  screen.
- **Proposed** (passed the checks, awaiting admin confirm) — renders, since it
  survived the contradiction check.
- **Not yet announced** — honest. GameDay's next stop is typically announced
  Sunday or Monday, so early-week emptiness is normal and worth saying rather
  than hiding.
- **Off-season** — the card does not render at all. A dead card for seven months
  is clutter, not presence.

**Voice:** the location is a fact and stays factual; the framing around it is
LOUD, and gets louder when GameDay is at a followed team's campus — that is a
personal event, and the card takes that team's `TeamPalette` treatment. Write all
three registers. Georgia may appear here freely: the copy-and-voice rule bars it
from *examples and jokes*, and explicitly permits it "as live data," which is
exactly what this is.

**Cost:** ~17 calls/month worst case at ~$0.32, plus web search at $10 per 1,000
searches (~$0.17). **Under $0.50/month.** Use Sonnet 5 rather than Haiku here —
the volume is trivial and a wrong campus on the home page is the expensive kind
of error.

---

## Future work — calibrating the Game Quality Score

Not a phase. Recorded because the investigation that produced it should not have
to be repeated, and because **one piece of it expires**.

### The finding

`App\Services\Contests\GameQualityScore` scores 0–100 from five additive
components. Three of them — matchup quality (60 of the 100 points), spread
tightness (20), line movement (5) — depend on ESPN feeds that are
**current-window only**:

| Season | Games | `matchup_quality` | Any spread |
| --- | ---: | ---: | ---: |
| 2021–2025 | 4,847 | **0** | **0** |
| 2026 | 946 | 99 | 121 |

Measured 2026-08-24. Nearly 4,800 completed games and **zero historical odds or
predictors**; `matchup_quality` is a rolling ~2-week window.

**Consequence: the weights cannot be back-tested, and no replacement — LLM,
regression, or otherwise — can be trained or validated against history.** The
class docblock already anticipated this: *"Weights are a first calibration,
expected to be tuned against a real season's slates."* That tuning has never
happened because the data does not exist.

### Why not just have an LLM score it

Asked and answered: no. The score sets tier point values (9/7/4 and 8/6/4) in a
competition where standings depend on them. An LLM is non-deterministic across
identical inputs, cannot be audited when someone asks why a game was a Dive, and
**always returns a number** — where the current code correctly returns `null`,
not `0.0`, for a game that cannot be scored. It would be a differently-shaped
guess over the same sparse inputs, with strictly worse properties.

### ⏰ The part that expires — do this before the first public Saturday

**Snapshot the score and all five of its components onto `slate_games` at
publish time.** Every published slate then becomes a labeled row. It costs
nothing, needs no AI, and is a few lines in `PublishSlate`.

**Slates published before this exists are gone as calibration data forever.**
Same argument as Phase 1's telemetry, same deadline.

### Then, once a season of labels exists

1. **Define the outcome.** The best label is **pick split** — a game the room
   split near 50/50 was genuinely uncertain, which is what a good pick'em game
   *is*, regardless of what ESPN's model thought. Secondary: final margin against
   the frozen line, and Conversation posts on that game.
2. **Re-fit the weights with plain regression.** Deterministic, auditable, and it
   ships as a deploy.
3. **Add features already synced and currently unused:** `home_win_prob` /
   `away_win_prob` on `games`; `home_opp_strength`, `away_opp_strength`,
   `home_pred_pt_diff` on `game_predictors`; the `injuries` table; ranking
   momentum across `rankings`. More features beat a smarter scorer.

### Where AI does help, once the above exists

- **News → structured flags as a score input.** Extract a bounded enum from the
  articles already synced — starting QB out, coach fired this week,
  rivalry/trophy game, conference-title implications — and feed it to the
  **deterministic** score as a small bonus. The model does language→structure;
  arithmetic stays arithmetic. Only games in the upcoming slate window, batched
  daily. This adds signal the numbers genuinely do not have.
- **The advisor proposing weight changes** as a workbook item you review — never
  a live adjustment.
- **Narration** ("why this game"), which is recommendation 3 below.

### A separate finding worth a workbook item

Sep 5 has 60 games but only **30 with a usable current line**.
`GameQualityScore::for()` returns `null` without one and `SuggestSlate` excludes
those games entirely, so a commissioner drafting a 15-game Triple Option slate is
choosing from half the board. That is *consistent* — the half-point law means a
game without a posted line can never publish — but it means **line coverage
bounds slate quality more tightly than the score does**. Worth knowing before the
first real slate, and worth checking whether more providers or an earlier capture
would widen it.

---

## Additional use cases worth considering

Ranked by value per dollar.

1. **Rotating notification copy, generated at build time.** Generate a pool of
   register-correct pick-reminder and results one-liners monthly, store them,
   serve deterministically at send time. **Zero runtime cost, zero runtime risk**,
   and it fixes the "same joke every week" fatigue that the current fixed `Voice`
   map guarantees. ~$0.46/month. Strongest recommendation on this list.
2. **The Bear's taunts.** The Woodshed's house contestant already makes themed
   picks that are public while you pick. AI-written taunts in register are
   maximally on-brand — and the Bear is a *fictional character*, which makes it
   the safest possible roast target under "never the person". ~$0.30/month.
3. **Slate suggestion narration.** `SuggestSlate` already computes
   `GameQualityScore`; one line of "why this game" per suggestion gives the
   commissioner a reason rather than a ranking. Once per slate build — pennies.
4. **Natural-language ops questions on Sync Health.** "Why did box scores fall
   behind last Saturday?" answered against the Phase 1 telemetry snapshot.
   Reuses everything; admin-only, so volume is trivial.
5. **Group conversation starters** for a quiet group's Conversation. A retention
   play, low volume — but it puts the app's voice into a human space, so it needs
   care and should wait for real usage.
6. **Semantic news search** via embeddings and Laravel 13's `whereVectorSimilarTo`.
   Deferred: Anthropic does not do embeddings in the SDK, so it means a second
   provider, and Scout is on the database driver today.

---

## Sequencing

Build all of it now. The single prerequisite is that **the launch-hardening
worktree is committed and merged**; nothing here waits for Sep 1 or Sep 5.

| Order | Work | Product surfaces touched |
| --- | --- | --- |
| 0 | Phase 0 — Console setup | None — no code at all |
| 1 | **Phase 1 — sensors** | None — net-new files |
| 1b | **⏰ Game Quality snapshot** (Future-work section) | `PublishSlate` — a few lines, **expires Sep 5** |
| 2 | **Phase 2 — Filament theme + workbook** | None — panel only, excluded from sweeps |
| 3 | **Phase 3 — advisor routine** | None — new routes only |
| 4 | Phase 6 — budget guard | None |
| 5 | Phase 7 — College GameDay | Home — new card, **ships flag-closed** |
| 6 | Phase 4 — recaps | Newsletter — **ships flag-closed** |
| 7 | Phase 5 — Search answers | Search — **ships flag-closed** |

**Items 0–3 are the priority and should land within days.** They touch no product
surface at all, so they carry no launch risk — and they are the ones that pay
off immediately, because this week's admin poking only becomes data if the
sensors are already recording. Phase 2's Filament theme also unblocks the admin
UI requests that poking will generate, and the workbook is the right intake for
them.

**Item 1b is the one thing here with a real deadline.** It is a few lines in
`PublishSlate` and needs no AI, but slates published before it exists are gone as
calibration data permanently. Do it alongside Phase 1.

**Phase 7 (GameDay) is ordered ahead of recaps and Search answers** because it is
the highest visible payoff per line of code, it touches only a new Home card
rather than an existing pipeline, and its whole value is weekly and in-season —
every week it is not shipped is a week of it missing.

**Items 5–7 are not gated on a date either — they are gated on a flag.**
They edit files launch hardening also changed (the newsletter pipeline and
Search), so re-read those first. Then land them the way every Phase 5 slice
landed: green and invisible behind `ai-recaps` and `ai-answers`, both defined as
closures reading `config/cfb.php` so flipping each is an environment change with
instant rollback (`ai-recaps`, `ai-answers`, `gameday`). Build now, flip when the
copy and the intent vocabulary have been tuned against real usage — and remember
`pennant:purge <flag>` is required on any flip, because the database driver's
stored rows shadow the new config for anyone who has already loaded a page.

---

## Verification

Per `CLAUDE.md`, in order:

1. **Tests.** `php artisan test --compact --filter=…` on each new suite, then the
   full suite before anything merges.
   - Add `preventStrayPrompts()` to `tests/Pest.php`'s `beforeEach` — alongside
     the existing `TeamGlance` static flush. This is the guardrail that stops a
     real API call from CI.
   - Use the SDK's per-agent fakes: `StatQuestion::fake([...])`,
     `assertPrompted`, `assertPromptedTimes`, structured-output fakes.
   - **Break the fix back** on every null-handling path — the recap fallback, the
     unresolved-player answer, `GameQualityScore` returning null. Per
     `.ai/rules/tests.md`, that class of test passes for the wrong reason more
     often than not.
   - Assert the telemetry payload contains **no** user identifiers.
   - Assert `/ops/*` rejects unsigned and untokened requests.
   - Assert the recap sweep rejects a person-attack, a "Georgia" example, and
     British spelling, and that rejection yields the deterministic copy.
   - Assert `Voice::line()` is called with `for: $user` in the recap job — the
     silent PG-13 bug has no other detector.
   - Pin fixtures: `GameFactory` randomizes `kickoff_at`, `TeamFactory`
     randomizes `alt_color` and `abbreviation`.
   - Target the Filament **widget class**, not the page — widget content is not
     in the page's HTML.
   - **GameDay, the highest-risk surface — test the guards, not the happy path:**
     a response with no `source_url` yields `unknown`; a host team with no home
     game that Saturday is **rejected** (the contradiction check, and this is the
     one test that matters most); an unresolvable campus yields `unknown`; a
     `confirmed` row is never overwritten by a later run; a second run in the
     same week is a no-op; the off-season phase renders no card at all. Fake the
     agent — `preventStrayPrompts()` means a real web search can never fire in
     CI, which would otherwise cost money on every run.
2. **`vendor/bin/pint --dirty --format agent`** after any PHP change.
3. **`npm run build`** after any Blade change, or new Tailwind utilities are
   missing at runtime and it reads as a design bug.
4. **Device widths** for the two new product surfaces — the GameDay card and the
   Search answer card: `/__device?path=/&w=390,768&h=800` and
   `?path=/search&w=390,768&h=800`. Chrome will not size below ~600px — use the
   harness, not a resized window. Check the GameDay card in **all four states**;
   the slot must not reflow between them.
5. **Cost verification before trusting the model above.** Run one real recap and
   one real answer, read `usage` off the response, and compare against the table.
   Do this before enabling anything for real users. Confirm
   `cacheReadInputTokens` is zero, which will verify the SDK caching finding
   first-hand rather than on my say-so.
6. **Filament regression pass.** Registering a theme changes the panel's CSS
   pipeline, so re-check Sync Health, Branding, Team Branding, and Pick'em
   Settings render unchanged — and re-run `SyncHealthTest`. This is the highest
   blast-radius change in the plan.
7. **Pulse volume check.** After a day on production, measure `pulse_entries`
   growth and tune `sample_rate` per recorder before the first live Saturday.
   The every-minute live sync plus `wire:poll` traffic is what will drive it.
   Confirm `pulse:work` is actually draining — a stalled drain looks exactly
   like "no traffic."
8. **Redis round-trip check.** For every new buffer, assert **twice** — the first
   read is served from memory and passes even when the value is unserializable.
   Confirm nothing but plain scalars and arrays goes into Redis.
9. **`php artisan cfb:doctor`** and **`pickem:preflight`** still exit zero.

---

## Open decisions

1. **Two dependencies** — `laravel/pulse` and `laravel/ai`. Approval required.
2. **Nightwatch deferred, not rejected.** Revisit after launch when there is real
   traffic to size events against.
3. **Registering a Filament theme** — one-time, unblocks the workbook and the
   unfinished admin console alike.
4. **The two `/ops/*` HTTP surfaces**, or the PR-based fallback for writes.
5. **"Game quality"** → ESPN retrospective, as recommended.
6. **Guests and the answer path** → signed-in only, as recommended. Note the
   GameDay card is the opposite: it renders for everyone including guests, since
   it is one cached row per week and costs nothing per view.
8. **GameDay auto-publish vs. admin confirm.** Recommendation: auto-publish when
   every guard passes (the contradiction check is strong), hold as `proposed`
   otherwise. Flip to always-confirm if the first few weeks prove noisy.
7. ~~Whether Phases 4–5 wait for Sep 1.~~ **Settled:** nothing waits for a date.
   Phases 4–5 ship flag-closed and flip when tuned.

---

## Where this plan lives

This file — `docs/plans/ai-layer.md` — **is** the durable copy, written
2026-08-24 and deliberately left **uncommitted** so it does not disturb the
launch-hardening branch. Commit it on its own branch off `main` once that work
merges.

`docs/` is the established home for long-form project knowledge; the `plans/`
subdirectory keeps a working plan distinct from the reference documents around
it, which describe what is already true rather than what is intended.

**To resume in a fresh session:** read this file top to bottom, then start at
**Phase 0**. Nothing in it needs re-deciding.
