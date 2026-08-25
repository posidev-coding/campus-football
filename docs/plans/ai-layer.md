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
| College GameDay (**fallback only** — feed is primary) | Sonnet 5 | ~1 | 8,000 | 300 | **$0.03** |
| Maintenance advisor | Claude Code | 4–8 | — | — | **$0.00** |
| Application monitoring | Pulse (self-hosted) | — | — | — | **$0.00** |
| | | | | **Total** | **~$9.14** |

Rates: Haiku 4.5 $1/$5 per MTok · Sonnet 5 $2/$10 · Batch API halves both ·
cache read 0.1× · **web search $10/1,000 searches — used only on Phase 7's
fallback path**, a handful of searches a season.

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

## Phase 1 — Sensors (build first; valuable with or without AI) ✅ **Complete 2026-08-24**

Every piece mirrors an existing shape in the codebase.

**1.1 Install Laravel Pulse, ingesting through Redis.** ✅ **Landed 2026-08-24.**
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

> **As built.** `laravel/pulse` v1.8.0 (it pulls `laravel/sentinel` and
> `doctrine/sql-formatter` with it). Ingest defaults to `redis` on connection
> `pulse` (Redis DB 2) in `config/pulse.php` itself, not only in the
> environment, so a machine with no `PULSE_*` vars still agrees with the
> directive. `pulse:work` rides `composer dev` locally — **it is still owed a
> Cloud daemon in production**, and until it has one nothing reaches MySQL
> there. `CacheInteractions` and `Queues` are off by default (volume; reasoning
> in `config/pulse.php` and `docs/operations.md`); `Servers` is registered but
> silent without `pulse:check`. The `viewPulse` gate is defined in
> `AppServiceProvider` — Pulse's own default answers `environment('local')`,
> which is open to every developer locally and closed to everybody in
> production. Verified end to end: a slow query reached Redis DB 2, `pulse:work
> --stop-when-empty` drained it to `pulse_entries` and `pulse_aggregates`.
> Tests: `tests/Feature/Admin/PulseTest.php`.
>
> ⚠️ **`PULSE_CACHE_DRIVER=array` is required, and the dashboard is unusable
> without it.** Pulse caches each card's result as an object; Laravel 13's
> `cache.serializable_classes => false` (the gadget-chain default, and the
> mechanism behind our own "never cache a non-scalar" rule) returns every object
> from a serializing store as `__PHP_Incomplete_Class`. The setting is GLOBAL,
> not per-store, so a dedicated Redis store does not escape it. Cost: card
> queries re-run on each 5s poll, and `pulse:restart` stops reaching a running
> `pulse:work` (restart the daemon directly). Found in a browser, not by the
> suite — see the testing note below.
>
> ⚠️ **A test that renders `/pulse` proves nothing about the cards.** They are
> all `#[Lazy]`, so the page returns 200 with skeletons and the cards run on a
> later round trip. `Livewire::withoutLazyLoading()` fixes that — but it applies
> to the NEXT component only, so calling it once in a `beforeEach` leaves the
> second render returning the placeholder again, which is exactly the render
> that would have caught this. Call it before EVERY render, and render twice.

**1.2 Client error capture.** ✅ **Landed 2026-08-24.** `window.onerror` + `unhandledrejection` POST to a
Redis-rate-limited endpoint that dedupes by fingerprint in Redis before writing
`client_errors`. **No APM covers this** — it is the class of bug a 390px PWA
ships silently, and it is currently invisible.

**1.3 Queue-failure capture.** ✅ **Landed 2026-08-24.** A `Queue::failing()` listener writing a
`feed_runs`-shaped row. Pulse's Exceptions recorder catches thrown exceptions,
but Cloud's managed queues hide the *failed job record* from the app entirely
(`RecentSyncFailures` says so in its own description), so this stays hand-built.

**1.4 UX funnel events.** ✅ **Landed 2026-08-24.** `ux_events` with a
**bounded, named** event vocabulary (~8: onboarding step reached, team picker
completed, invite link opened, registration completed, slate entered, first pick
made, slate abandoned with zero picks, tour dismissed). **Redis hash counters on
the request path, nightly job persists the rollup** — no row per event, no MySQL
write in the pick or onboarding flows. Aggregate only, no free-text. This is the
"UX friction" signal, and no off-the-shelf APM can produce it because the events
are specific to this product.

> **As built.** Vocabulary is `App\Enums\UxSignal`, eight cases; counters ride
> `App\Actions\RecordUxEvent` into Redis DB 2 (the telemetry database, beside
> Pulse's stream, out of `cache:clear`'s reach), and `cfb:ux-rollup` persists
> **finished days only** at 04:55 on the existing wake. **"Slate abandoned with
> zero picks" is derived, not counted** — it is `slate_entered` minus
> `first_pick_made`, and a third counter for a difference is a third counter
> that can disagree with the other two. `slate_entered` is deduped per member
> per slate per day, because it fires on MOUNT and a `wire:navigate` hop
> re-mounts; that dedupe key is the only place a user id appears, it is TTL'd in
> Redis and never persisted. Every failure is swallowed — a counter is never
> worth a 500 on a pick. Tests speak to a real Redis on **DB 15**, pinned in
> `phpunit.xml` so the suite cannot write into a developer's telemetry.
>
> ⚠️ **phpredis stringifies an array argument to the literal `"Array"`.**
> `sadd($key, [$member])` silently adds one member named `Array` and still
> returns 1 the first time, so every subject deduped to the same subject and the
> rollup found no days to roll up. Pass set members as SCALARS. Caught by a
> test, not by review.

**1.5 `App\Support\OpsReport`.** ✅ **Landed 2026-08-24.** A third report class in the established shape.
`CoverageReport` and `PickemPreflight` already agree on
`{key, label, status: ok|warn|fail, detail, remedy}` — `PickemPreflight`'s
docblock says it is *"shaped like CoverageReport on purpose."* `OpsReport` makes
it three, aggregating Pulse's aggregates plus 1.2–1.3.

**1.6 `cfb:telemetry --json`.** ✅ **Landed 2026-08-24.** One command emitting
the snapshot: `OpsReport`, `CoverageReport::checks()`, `PickemPreflight::checks()`,
`SyncSchedule::tasks()`, recent `feed_runs` errors split into commands and jobs,
Pulse's slow-request / slow-query / slow-job / outgoing / exception entries
grouped by key, client errors, funnel rollups. Aggregate only, no user
identifiers.

> **As built (1.5 + 1.6).** `OpsReport` carries seven rows in the shared
> `{key, label, status, detail, remedy}` shape — a test asserts it matches
> `PickemPreflight` key-for-key. One of them watches the MONITOR rather than the
> app: a stalled `pulse:work` looks exactly like no traffic, so the ingest row
> reads the Redis stream length and names `pulse:work` as the remedy. The
> pick-through row DERIVES abandonment from the two funnel counters. Its 50%
> warn threshold is a first calibration and has never seen a real Saturday.
>
> `cfb:telemetry` defaults to a terminal read and takes `--json` for the
> `/ops/telemetry` route Phase 3 will add. It **always exits zero** — `cfb:doctor`
> is the deploy gate, and a snapshot command that fails a pipeline because a
> request was slow is one somebody turns off. Pulse entries are grouped by key
> so a route that was slow two hundred times is one line with a count, which is
> what keeps the payload prompt-sized.
>
> The no-identity rule is asserted, not trusted: a test fires every sensor with
> a distinctively-identified user and asserts the payload contains no email, no
> handle, no id and no `user_id`. `SyncSchedule::tasks()` hands back Eloquent
> `FeedRun` instances for the admin table, so the command projects six fields
> off each rather than serializing the model.
>
> ⚠️ **`signal` is a reserved word in MySQL 8**, like `STORED`. An unbackticked
> one in a `selectRaw` is a 1064, not a wrong answer.

Files, as built: `app/Support/OpsReport.php`,
`app/Console/Commands/TelemetryCommand.php`,
`app/Console/Commands/RollUpUxEventsCommand.php`,
`app/Actions/RecordUxEvent.php`, `app/Actions/RecordClientError.php`,
`app/Http/Controllers/ClientErrorController.php`, `app/Enums/UxSignal.php`,
`app/Models/ClientError.php`, `app/Models/UxEvent.php`, `config/pulse.php`,
four migrations, `routes/console.php` (nightly rollup at 04:55 — ungated,
`withoutOverlapping()`, `->timezone($tz)`, riding the existing 04:00–07:00 wake
rather than adding one; a scheduled task holds a scale-to-zero cluster up for
the whole sleep timeout).

> **One deviation.** The plan named `app/Listeners/RecordJobFailure.php`.
> `app/Listeners` is a NEW BASE FOLDER, which `CLAUDE.md` requires approval for,
> and `AppServiceProvider` already states the standing choice in a comment —
> *"Closures here rather than a Listeners folder (no new base folder)"* — for the
> event-driven scoring listeners. So the `Queue::failing` hook is a closure in
> `AppServiceProvider` and the write is `FeedRun::jobFailed()`, beside
> `begin`/`complete`/`fail`. Same behavior, existing layout.

---

## Phase 1b — The Game Quality snapshot ✅ **Landed 2026-08-24**

**The one piece of this plan that had a real deadline**, and it was never
"future work" — rehearsal is Aug 29 and the first public Saturday is Sep 5.
Every slate published before this existed is gone as calibration data
permanently, because the data it captures cannot be reconstructed afterwards.
See the Future-work section below for the measurement: **4,847 completed games
across 2021–2025 carry zero `matchup_quality` and zero odds of any kind**, so
three of the score's five components — 85 of its 100 points — have no history
to be tuned against and never will.

**It was not "a few lines in `PublishSlate`."** It is four pieces, because the
extraction has to come first:

**1. `slate_games.quality` + `slate_games.quality_parts`.** `decimal(5,2)`
nullable and `json` nullable, both after `tier`; cast `float` and `array`.
Nullable means "could not be scored" and never 0.

**2. `GameQualityScore::components()` and `::total()`.** `for()` becomes the two
composed, so both `SuggestSlate` callers are untouched. `components()` returns
the RAW inputs (`matchup_quality`, `spread`, `open_spread`, `home_rank`,
`away_rank`, `conference_game`) beside the weighted parts, under a `'v' => 1`
token — a re-fit is solving for the weights, so it needs the feature, not the
product.

> ⚠️ **The bug this fixed on the way past.** The original had no `else` branch,
> so a missing `matchup_quality` silently contributed 0 and a missing open
> silently contributed 0. Persisting that would teach a future re-fit that
> unrated games are *bad* games. They are **unmeasured**, which is not the same
> thing. A part is now `null` when its signal is ABSENT and `0.0` only when it
> is present-and-zero — `total()` skips the nulls, so the live score is
> unchanged.

**3. The write in `PublishSlate::force()`** — inside the existing transaction,
after validation passes and before the status flip, with
`games.game.odds` + `games.game.predictor` eager-loaded first. **Not**
`SuggestSlate::AFFINITY_BONUS`: the base score is a per-game fact, affinity is a
per-group opinion, and folding it in would make one matchup score differently on
two rooms' slates.

**4. Tests, broken back.** No predictor yields `weighted.matchup === null` (not
`0.0`) with the score still summing the rest; no usable current odd yields BOTH
columns null; re-publishing never rewrites the snapshot.

Two traps, both verified:

- **`PublishSlate` only did `loadMissing('contest')`.** Measured without the
  eager load, a 6-game slate costs **25 queries against 13 for 2** — three extra
  reads per row, inside a transaction holding a write lock; a 15-game slate would
  be ~45. **No feature test can see this**: `preventLazyLoading`'s per-instance
  flag is false under test, so it resolves silently and N+1s only in production.
  Guarded by a query-count comparison plus a source sweep.
- **`components()` can legitimately return null at publish.** It reads the LIVE
  current-phase odd, while the slate's line was frozen into `slate_games.spread`
  earlier — possibly days earlier. The null is recorded honestly. Tightness and
  movement stay recomputable later from `spread` / `market_spread` /
  `odds_provider` / `odds_captured_at`, which the row already stores.

## Phase 2 — The workbook and the Kanban ✅ **Complete 2026-08-24**

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

**The prerequisite: register a Filament theme.** ✅ **Done.** The panel deliberately does not
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

> **As built (2.1 + 2.2).** `workbook_items` with three bounded enums
> (`WorkbookCategory`, `WorkbookSeverity`, `WorkbookStatus`) and
> `WorkbookItem::propose()` as the single doorway — the `GrantWalletEntry`
> shape. `first_seen_at` is never refreshed by a re-propose, because "how long
> has this been true" is the most useful number on the card. `Dismissed` is not
> a board column: it is an answer, not a stage.
>
> Two surfaces: `WorkbookResource` at `admin/workbook/items` (search, three
> multi-select filters, bulk moves, and a detail view with the evidence and a
> copyable prompt) and the `Workbook` page at `admin/workbook`. Sidebar groups
> are Work / Operations / Configuration.
>
> ⚠️ **The board's drag rests on three attributes, and two are traps.**
> `wire:sort` takes a bare method name. `wire:sort:group-id` per column is what
> makes it a Kanban — Livewire appends it to the handler's arguments and
> Sortable fires on the DESTINATION list. And the Sortable group must be bound
> with Alpine's **`x-sort:group`**, not `wire:sort:group`: Livewire's attribute
> loop `return`s on the latter, so with it before `wire:sort` in the source,
> `wire:sort` never binds and the drag silently does nothing. Read out of
> `livewire.esm.js`. Sortable's index is ZERO-based; stored positions are
> one-based; and the column an item LEAVES must be renumbered, because
> positions are what the next drop's index is measured against.
>
> The table sorts worst-first through `FIELD()`. `severity` holds the enum's
> string value, so alphabetical order is critical-high-low-medium, which puts
> Low above Medium.

**2.3 Advisor run ledger.** Reuse `feed_runs` with `command = 'advisor:review'`
via `App\Console\Concerns\TracksFeedRun::trackRun()`, plus a `ledgerKey()` case
in `app/Support/SyncSchedule.php:118` — that buys Sync Health visibility for free.

> **As built.** `FeedRun::ADVISOR` plus `latestAdvisorRun()`, and an Advisor stat
> on Sync Health showing the last pass and how much is open. A failed pass shows
> in the Recent failures table for free, which is the whole reason it reuses
> `feed_runs`. The advisor writes through `FeedRun::begin()`/`complete()`/`fail()`
> — the existing public API — because it is a Claude Code routine with no
> database access and reaches us over the Phase 3 `/ops` surface, not through
> `TracksFeedRun`, which is a console concern.
>
> **No `ledgerKey()` case for it, deliberately.** `SyncSchedule` introspects OUR
> scheduler to compute an overdue flag from each event's cron expression; the
> advisor's cron lives in Claude Code's cloud. A row there would be a task whose
> schedule we cannot see, reporting an overdue flag nothing can compute.
>
> ⚠️ **Two real gaps closed on the way past.** `cfb:kickoff-alerts` and
> `cfb:ux-rollup` both write `feed_runs` rows and had no `ledgerKey()` case, so
> Sync Health rendered them as permanently grey "untracked" — the state that
> method exists to distinguish from "ran and found nothing". A test now asserts
> that the only untracked scheduled tasks are the two news fan-outs, which
> genuinely write no ledger row.

---

## Phase 3 — The maintenance advisor ✅ **Complete 2026-08-24**

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
>
> **As built.** `OpsEndpointTest` covers all of it: no token, wrong token, empty
> token, unconfigured token, weak token, unsigned URL, tampered URL, throttle
> exhaustion, and an explicit assertion that the payload carries no email, no
> handle, no id and no `user_id`.
>
> - **Unset means 404, not 403** — and that is the fail-closed case, since the
>   naive middleware compares a null header against a null config and admits
>   everybody. A token under 32 chars counts as unset.
> - **Registered outside the `web` group** from `bootstrap/app.php`: no session,
>   no cookies, and no CSRF exemption to be widened later. `ops/*` renders JSON
>   on error, because a 302 tells a machine nothing.
> - **The write reaches only `workbook_items` and one `feed_runs` row.**
>   `status`, `position` and `source` in a payload are ignored; the enums bound
>   the vocabulary; `propose()` refuses to reopen a dismissal and the response
>   reports which keys were already answered.
> - **One request per pass**, which is what lets one `advisor:review` row
>   describe the run. An `error` instead of `items` records a failed pass.
> - The snapshot gained a **`workbook` section** — open items and answered keys
>   — which is what closes the loop the plan describes.
> - `cfb:advisor-setup` prints the signed URL, which cannot be typed.
> - The routine's own instructions are committed at
>   `.claude/skills/maintenance-advisor/SKILL.md`, so what it is told to do is
>   reviewable in git rather than living only in a cloud console.
>
> **Still owed by a human: scheduling the routine.** The app half is done; the
> cloud routine that calls it is configured outside this repository.

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

## Phase 6 — The enforced budget ✅ **Complete 2026-08-24**

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

> **As built.** `App\Enums\AiModel` is the rate card AND the bounded list of
> models we may call — a model with no case cannot be costed, and what cannot be
> costed cannot be capped. `App\Support\AiBudget` is the single authority,
> asked from both the request path and the queue; `App\Actions\RecordAiSpend`
> is the single doorway for the write, with `handle()` (queued callers) and
> `later()` (request path, deferred) so the choice is visible at the call site.
>
> The middleware is `App\Jobs\Middleware\ThrottleAi`, named for the three
> siblings it mirrors — and it **fails rather than releasing**, which is the one
> place it departs from them. Their window is a day and tomorrow is a fine time
> to send a newsletter; this window is a MONTH, so a released job would park
> past any sane `retry_until` and the "recovery" would be a job that silently
> expired. It is only for jobs that are nothing but a model call — where AI is
> one optional step of something else, the caller asks `AiBudget::allows()` and
> falls back to deterministic content.
>
> The flags read config and the master switch, and deliberately **not** the
> budget: they say whether a FEATURE exists, the budget says whether there is
> money. Resolving Pennant against a number that moves would persist a row the
> moment spend crossed the line and answer from it afterwards.
>
> ⚠️ **Pricing was re-verified against the live pricing page, not taken from
> this plan.** The plan is right and a widely-cached secondary table is stale:
> Sonnet 5's $2/$10 launched as introductory "through August 31, 2026" and **is
> now the standard price** — the scheduled rise to $3/$15 on September 1 was
> cancelled. A stale rate would under-report every recap by a third.
>
> ⚠️ **`->utc()` on the month boundary is load-bearing.** `created_at` is UTC
> and a Carbon carrying the league timezone binds as its local wall time, so
> without the conversion every call made in the last four hours of a month is
> charged to the next month's ceiling — silently, with no exception.
>
> **`laravel/ai` is still not installed.** Nothing here needs it: the ledger
> takes plain token counts, so the SDK's usage event is a listener that maps
> `usage` onto `RecordAiSpend` when Phase 4/5/7 lands. A ledger coupled to one
> pre-1.0 client is a ledger that breaks on its next minor.

---

## Phase 7 — College GameDay

**The premise is correct: ESPN's four synced hosts do not carry this.** Nothing
in the JSON feeds exposes where GameDay is broadcasting from — which is why this
started as the best justification for the AI SDK in the whole plan.

**Then a real feed turned up.** The promo page hydrates from an `index.json`
that carries two weeks of locations, so the design flipped: **the feed is
primary, the model is the fallback**, and the model went from being the data
source to being the thing that covers the weeks the feed lags.

That is a better outcome and a cheaper one — but it does not make this safe by
default. The feed is **hand-maintained and demonstrably dirty**: it currently
ships last season's venue under this season's matchup, and alt text naming the
wrong school. So the guards below still carry the weight; they simply apply to
both paths now rather than to the model alone. The Phase 5 rule — the model
never emits a fact — is *restored* here rather than suspended: the model
proposes a location, and our own `venues`/`games` data decides whether it is
real.

### The source: a real JSON feed exists — and it is the primary source

Fetching `https://promo.espn.com/collegegameday/` as HTML returns **only ESPN's
boilerplate footer** — the page is JavaScript-hydrated, so a plain
`Http::get()` on the page gets nothing. But the network tab's XHR shows the page
hydrates from an **`index.json`** behind it, and that file carries exactly what
this feature needs.

**Capture the exact URL from the network tab and pin it in config** — it was read
from a browser session, not derived, so do not guess the path.

**This inverts the design.** The feed is the primary source and the resolution is
deterministic; **the model drops to a fallback**, used only when the feed is
missing, stale, or changes shape. That is the right shape — a feed we can parse
beats a model we have to guard, the same reasoning that has the rest of this app
reading ESPN's feeds rather than reasoning about football.

#### What to read — and only this

From `matchups[]`, take **`cutoffTime`, `location`, `date`, `prefix`**. Nothing
else in the payload is trustworthy. The live 2026-08-24 sample carried:

```
matchups[0]  cutoffTime 2026-09-05T09:00:00  location "Baton Rouge, LA"  prefix "Week 1 Live from"
matchups[1]  cutoffTime 2026-09-12T09:00:00  location "AUSTIN, TX"       prefix "Week 2 Live from"
```

Two weeks of lookahead, which means fewer fetches and a free "next week" line on
the card.

#### ⚠️ The payload is dirty — these are load-bearing traps

The page is clearly hand-maintained, and last season's content was left in place
rather than removed (the `sectionVisibleBeforeCutoff` / `AfterCutoff` booleans
hide it instead). Verified in the live sample:

- **`map.*` is unmaintained carryover from a previous season, and it will lie to
  you.** `matchups[0]` is Baton Rouge / LSU, but its `map` block reads
  `locationName: "South Oval"`, `address: "Norman Oklahoma"`, `imageSrc:
  ou-map.png` — **Oklahoma**. `matchups[1]` is Austin / Texas, but its `map`
  reads `"Aggie Park"`, `"College Station"`, `tam-map.png` — **Texas A&M**.
  Reading location from `map` puts *Norman, Oklahoma* on the home page during an
  LSU week. **Never read `map`.**
- **`homeTeamLogoAlt` is wrong in the live data.** `matchups[0]`'s alt text says
  `"Ohio State logo"` while its `homeTeamLogoSrc` is `lsu.png`. **Never derive
  team identity from alt text** — resolve from `location` + date instead.
- **`schedule.dates` (Dec 2025), `videos.playlist` (2025 Heisman/CFP),
  `announcement`, `schoolBannerOne`** are all last-season leftovers. Ignore them.
- **`id` formatting is inconsistent** — `"Clemson-vs-LSU"` versus
  `"Ohio State vs Texas"`. Do not parse it.
- **`location` casing is inconsistent** — `"Baton Rouge, LA"` versus
  `"AUSTIN, TX"`. Normalize before matching.
- **Asset paths are stamped `/2025/`** even for 2026 content — the scaffold is
  reused, so key nothing on the path year.
- `instagram.city` even carries a typo ("Baton Rougue"). Treat every field
  outside the four named above as decoration.

#### The resolver — validated end to end against our own data

Match on **`(city, state, saturday)`** into `venues` joined to `games`. Run
against the live payload on 2026-08-24, both weeks resolved uniquely and
correctly:

| Feed | Resolves to |
| --- | --- |
| Baton Rouge, LA · 2026-09-05 | **LSU vs Clemson**, Tiger Stadium ✓ |
| AUSTIN, TX · 2026-09-12 | **Texas vs Ohio State**, DKR-Texas Memorial ✓ |

**And the same query proves why the date is load-bearing:** Austin on **Sep 5**
is Texas–Texas State, and Baton Rouge on **Sep 12** is LSU–Louisiana Tech. A
resolver keyed on city alone lands on the wrong game in both directions. City +
state + the specific Saturday is the key — and the code must **assert that match
is unique** rather than take `first()`, since a city can host a neutral-site game
alongside a home team's.

This resolution also *is* the contradiction check from the guards below: a
`location` that matches no game that Saturday is rejected, not displayed.

#### Freshness guard — the trap this feed sets

Because `matchups[]` is hand-maintained, it will at some point lag. **If no
`cutoffTime` matches the upcoming Saturday, the answer is `unknown`** — never
render the most recent matchup as though it were this week's. That is the
"never write a default when data is missing" rule applied to a feed that
helpfully keeps stale rows around.

`faq` in the same payload confirms the cadence: *"The locations — usually
announced a week in advance — are chosen by ESPN based on competitive matchups,
rivalries and other factors."* That validates the Sun–Thu schedule below.

#### Discipline

One request per week, no polling, and it does **not** go through `EspnClient` —
that client exists for the JSON feeds and their cost tiers, and a promo-page
fetch does not belong inside its rate limiter or User-Agent allowlist. Cache the
parsed result; store the raw payload hash so a shape change is detectable rather
than silent.

Still worth evaluating as fallbacks: the show's social account (where the
location often lands first), and Wikipedia's per-season GameDay tables — well
maintained, historical, and the practical backfill source.

### The routine

`cfb:gameday`, scheduled daily around 09:00 ET, **Sunday through Thursday,
in-season only**, stopping for the week the moment a Saturday resolves — so a
normal week is one or two runs, not five. Idempotent on
`(season_year, saturday)`, the same keyed idempotency the wallet entries and the
workbook use. `Cadence::currentSaturday()` names the target; gate on
`CfbCalendar::phase()->isLive()`.

Two paths, in order:

1. **The feed.** Fetch `index.json`, read the four trusted fields, resolve
   `(city, state, saturday)` against `venues`/`games`. No AI, no cost. This will
   be the path essentially every week.
2. **The model, only on failure** — feed unreachable, shape changed, no
   `cutoffTime` matching the upcoming Saturday, or a `location` that resolves to
   more than one game. An in-app `laravel/ai` agent (**not** a Claude Code
   routine — this is production data that must land in the database and render on
   a user-facing screen) using Anthropic's **`WebSearch` provider tool** with
   structured output:

   ```
   { site, city, state, host_team_name, game_hint,
     announced: bool, confidence, source_url }
   ```

   Its output goes through the *same* resolver and the *same* guards as the feed.
   Expect this to fire a handful of times a season, not weekly.

### The guards, which are the actual feature

Guards 2–6 apply to **both** paths — the feed is not more trustworthy than the
model just because it is first-party, as the `map` block above proves. Guard 1
applies to the model path only.

1. **Search is mandatory; parametric memory is not a source.** A response with no
   `source_url`, or one the search did not return, is discarded as unknown. The
   model may not answer from what it remembers. Hinting the search toward the
   promo page is fine — pinning it to one domain via `allowed_domains` is not,
   since the fallback's whole value is working the week the primary source
   breaks.
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
before. Wikipedia's per-season tables are the practical backfill source, and the
same contradiction check validates every backfilled row against our own
`games` data for free.

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

**Cost:** now that the feed is the primary path, the model fires only on
fallback — call it a handful of times a season rather than weekly. **Well under
$0.10/month**, and most months exactly $0.00. Use Sonnet 5 rather than Haiku on
that path: the volume is trivial and a wrong campus on the home page is the
expensive kind of error.

---

## Future work — re-fitting the Game Quality Score

Not a phase, and no longer urgent: the piece that expired is **Phase 1b**, which
has landed. Recorded because the investigation that produced it should not have
to be repeated.

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

The finding above is long-term work with no deadline, with ONE exception, and
that exception is no longer filed here: it is **Phase 1b**, above. Everything
that remains in this section is the part that can wait.

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
| 1b | **Phase 1b — Game Quality snapshot** ✅ landed | `PublishSlate` + a `GameQualityScore` extraction — four pieces, not a few lines |
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

**Item 1b was the one thing here with a real deadline** — rehearsal Aug 29,
first public Saturday Sep 5 — and it has landed. It needed no AI, but it was
four pieces rather than a few lines: the components/total extraction had to come
first, and it turned up a live null-handling bug on the way. Slates published
before it existed are gone as calibration data permanently.

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
   - **GameDay, the highest-risk surface — test the guards, not the happy path.**
     Save the live 2026-08-24 `index.json` as a fixture, dirt included, and
     assert against it: `(city, state, saturday)` resolves Baton Rouge/Sep 5 to
     LSU–Clemson and Austin/Sep 12 to Texas–Ohio State; **Austin/Sep 5 does not
     resolve to the Sep 12 game** (the date is load-bearing); a `location`
     matching two games that Saturday is rejected rather than `first()`-ed;
     nothing is ever read from `map`, `homeTeamLogoAlt`, `schedule`, or
     `videos` — a source sweep is the honest way to hold that; a payload whose
     newest `cutoffTime` predates the upcoming Saturday yields `unknown`, **not**
     the stale matchup. Then the model path: no `source_url` yields `unknown`; a
     host team with no home game that Saturday is rejected; a `confirmed` row is
     never overwritten; a second run in the same week is a no-op; the off-season
     renders no card at all. Fake the agent — `preventStrayPrompts()` means a
     real web search can never fire in CI and cost money on every run.
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
7. **GameDay auto-publish vs. admin confirm.** Recommendation: auto-publish when
   every guard passes (the contradiction check is strong), hold as `proposed`
   otherwise. Flip to always-confirm if the first few weeks prove noisy.
8. ~~GameDay source.~~ **Settled 2026-08-24:** `promo.espn.com/collegegameday/`
   hydrates from an `index.json` that carries two weeks of locations. The feed is
   the primary source, the model is the fallback. Remaining task is to capture
   the exact endpoint URL from the network tab and pin it in config — and to read
   Phase 7's trap list before parsing a single field.
9. ~~Whether Phases 4–5 wait for Sep 1.~~ **Settled:** nothing waits for a date.
   Everything user-facing ships flag-closed and flips when tuned.

---

## Where this plan lives

This file — `docs/plans/ai-layer.md` — **is** the durable copy, written
2026-08-24 and living on the **`ai-layer`** branch, cut from the tip of
`launch-hardening` so the implementation starts against current code rather than
a stale `main`. Once hardening merges, this branch's diff against `main` collapses
to this one file.

It briefly rode on `launch-hardening` by accident: a concurrent session's
`git add -A` swept it into commits `ac2c194` and `3d18153`. It was removed there
in `6086242`, so it will not reach `main` under the hardening heading. It remains
in that branch's *history* — rewriting commits already pushed to origin was not
worth the tidiness.

`docs/` is the established home for long-form project knowledge; the `plans/`
subdirectory keeps a working plan distinct from the reference documents around
it, which describe what is already true rather than what is intended.

**Build the work on this branch**, phase by phase, and keep this file updated as
decisions land — it is a working document, not a record.

**To resume in a fresh session:** read this file top to bottom, then start at
**Phase 0**. Nothing in it needs re-deciding.
