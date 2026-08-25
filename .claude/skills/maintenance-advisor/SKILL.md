---
name: maintenance-advisor
description: "Run one pass of the Campus Football maintenance advisor: read the telemetry snapshot from /ops/telemetry, read the repository for root causes, and file categorized workbook items with a scaffolded prompt each through /ops/workbook. Use when asked to run the advisor, review telemetry, triage the workbook, or when invoked by the scheduled advisor routine. Do not use for writing product code — this skill only files findings."
---

# Maintenance advisor

One pass of the advisor. You read real telemetry, you read this repository, and
you file work into the workbook. **You do not fix anything in this pass.**

Your value over a telemetry-only agent is exactly one thing: you can read the
code. A monitor can say *"the picks screen is slow and three people abandoned
it."* Only you can say *"`/picks` N+1s on `slate.games.team` at
`pickem-home.blade.php:212`; here is the eager load; here is the prompt to hand
a Claude Code session."* **If a finding has no file, no line and no prompt, you
have not finished it.**

## Setup

`php artisan cfb:advisor-setup` prints the signed telemetry URL, the workbook
URL and the header. The telemetry URL **cannot be typed** — it carries an
`APP_KEY`-derived signature and differs per environment.

Both calls need `X-Ops-Token: <OPS_TOKEN>`. A 404 means no token is configured
in that environment; a 401 means yours is wrong. Neither is a bug in the app.

## The pass

### 1. Read the snapshot

`GET` the signed telemetry URL. It carries, all aggregate and with no user
identifiers anywhere:

| Section | What it answers |
| --- | --- |
| `ops` | Is the application behaving — exceptions, slow requests, slow queries, failed jobs, browser errors, pick-through |
| `coverage` | Is the DATA whole — the same rows `cfb:doctor` prints |
| `pickem` | Is the product ready to be shown to people |
| `schedule` | Did each scheduled command run, and is it overdue |
| `errors` | Recent failures, split into commands, jobs and browser |
| `performance` | Pulse's heaviest entries, grouped by key with a hit count |
| `funnel` | Seven days of the eight named UX signals |
| `workbook` | **What is already on the board, and what a human answered** |

### 2. Read the repository

For each signal worth chasing, find the CAUSE, not the symptom. `git log` for
what changed near it. `.ai/rules/` for whether the thing you are about to
propose is already a settled decision — **several will be**, and proposing
against a recorded rule is the fastest way to make the board ignorable.

### 3. Decide what is actually new

`workbook.open` is what already exists. `workbook.answered` is what a human has
already settled.

- **Recurring and already open** → re-file under the **same key**. That updates
  the row and moves `last_seen_at`; it never duplicates.
- **Already `dismissed`** → **do not file it.** A dismissal is a person saying
  "we know, and no". The endpoint will refuse it anyway, but a pass spent
  rediscovering refused work is a wasted pass.
- **Already `done` and back again** → file it, and say in the body that it
  recurred after being closed. That is a regression and it deserves the noise.

### 4. File one request

`POST` the workbook URL **once** for the whole pass, with every item:

```json
{
  "duration_ms": 42000,
  "items": [
    {
      "key": "picks-n-plus-one",
      "title": "The picks screen N+1s on slate.games.team",
      "body": "214 slow queries in 24h, worst 2.4s. The rail panel renders <x-game-card> without the team eager-loaded.",
      "category": "performance",
      "severity": "high",
      "evidence": { "hits": 214, "worst_ms": 2400, "source": "performance.slow_query" },
      "prompt": "In resources/views/livewire/pickem-home.blade.php the slate query does not eager-load games.team..."
    }
  ]
}
```

If the pass FAILED, say so instead — `{"error": "the telemetry endpoint timed
out"}`. A routine that dies silently is indistinguishable from one that never
ran, and that is the failure a ledger exists to prevent.

An empty `items` array is a perfectly good pass. **Do not invent work to look
busy** — a board that fills with padding is a board nobody opens.

## The fields

**`key`** — lowercase slug, `[a-z0-9-]`, stable **forever**. This is the whole
idempotency: the same finding must arrive under the same key every week or the
board fills with copies. Name the PROBLEM, not the run: `picks-n-plus-one`, not
`slow-query-2026-09-08`.

**`category`** — one of `bug`, `feature`, `performance`, `ux`, `data`, `ops`,
`tech-debt`. Bounded; anything else is rejected.

**`severity`** — one of `critical`, `high`, `medium`, `low`. Four levels with no
middle, on purpose. Reserve `critical` for something losing data, money or a
Saturday.

**`evidence`** — the numbers you were looking at, and which snapshot section
they came from. Aggregate only. Never invent a number: if the snapshot does not
carry it, leave it out.

**`prompt`** — the scaffolded Claude Code prompt, and the reason you exist.
Name the files, name the fix, name how it would be tested. Write it for a
session that has not read the snapshot.

**Not `status`, not `position`, not `source`.** Where work sits on the board is
a human's answer. Send them and they are ignored.

## What this project will hold you to

Read `CLAUDE.md` and `.ai/rules/index.md` before proposing anything. The
non-negotiables that most often make a proposal wrong here:

- **Never write a default when data is missing.** `null` means "no data" —
  callers skip, they never substitute. A proposal that suggests defaulting a
  missing value to zero is proposing the bug that broke three previous versions.
- **Never hardcode the current season.** `App\Services\CfbCalendar` is the only
  source of truth, and `currentYear()`, `resultsYear()` and `scoreboardYear()`
  answer different questions.
- **Respect the ESPN sync cost tiers.** Live scoring is ONE request per minute
  in total. A proposal that fans out per game is proposing the v3 outage.
- **Every change is programmatically tested**, and no test is deleted without
  approval. A prompt that does not say how the fix is proven is half a prompt.
- **Mobile-first at 390px**, and **American spelling everywhere**.

## Reading the signals well

- **`ops.slow_queries` is the only detector an N+1 has.** `preventLazyLoading`'s
  per-instance flag is false under test, so no feature test in this repository
  can catch a missing eager load. A slow query naming a relation is a real find,
  every time.
- **`errors.client`** is browser JavaScript, which no server log sees. Check
  `viewport` and `standalone`: a 390px installed PWA is the primary target, and
  a break that only happens there is invisible everywhere else.
- **`ops.pulse_ingest` failing means the MONITOR is down**, not the app. A
  stalled `pulse:work` looks exactly like no traffic, so treat every quiet
  performance section as suspect until that row is green.
- **`funnel`** carries no denominator of its own. `slate_entered` minus
  `first_pick_made` is the abandonment; `invite_opened` against registrations is
  acquisition. Do not read a rate off fewer than ~20 samples.
- **`schedule[].overdue`** during the off-season is usually a season gate doing
  its job, not a failure. Check `season.phase` before proposing.
