---
paths:
  - app/Support/**
  - app/Actions/**
  - app/Services/CfbCalendar.php
---

# Support classes, caching and actions

Long-form reference: `docs/data-model.md` and `docs/product.md`.

## Never cache anything that is not a plain scalar or array
Eloquent models and Carbon instances round-trip through Redis as
`__PHP_Incomplete_Class` and fail on the SECOND request, not the first. Any test
for this class of bug must call twice — a single-call test always passes.

## Never Cache::remember an empty list that gates a screen
Production served a stats screen whose season menu had no options because `[]`
was cached as authoritative for an hour while a backfill drained. Use
`App\Support\Remember::filled()`, and apply any calendar fallback OUTSIDE the
cache so a fallback year cannot be pinned either.

## One query per CONCERN across all rows, never per row
`TeamGlance` holds league-wide maps (records, ranks, standings, conference
names) as plain arrays. Home asserts the same query count for one followed team
as for five.

## An arrow function captures by VALUE
`fn ($g) => $claimed[$g->id] = true` writes to a copy, silently. Any closure
that MUTATES captured state must be `function () use (&$ref)` or a foreach.

## Voice::line() must be passed `for: $user` in anything queued
It falls back to `auth()->user()`, which is null in a job — so a missing
argument does not error, it renders the PG-13 line to everybody. A test for it
must NOT `actingAs` the recipient, or the fallback passes by accident.

## Copy does not belong in exceptions
An exception carries a developer message for logs; what the user reads comes
from `Voice`, because a baked-in string can only speak in one register.

## Writes with side effects go through app/Actions
`FollowTeam` dispatches the team's news sync, appends at `max(position) + 1`,
and checks "already following?" BEFORE the follow cap. Never write to the
relation directly, or a new caller silently skips the side effect.

## A throttle window must be a spelled-out constant
`now()->addDay()->diffInSeconds()` is NEGATIVE 86400 in Carbon 3, which expires
the limiter key the instant it is written and makes the throttle permit
everything. It fails OPEN — the worst direction for a guard.

## An in-flight guard must be RELEASED, not given a TTL
`Cache::lock()` acquired for the fetch and released in a `finally`. A
never-released `Cache::add($key, true, 60)` is a freshness gate wearing an
in-flight label, and it silently swallows any hand-asked refresh.

## Wallet writes go through GrantWalletEntry, keyed when one-time
Never insert wallet_entries directly. One-time grants pass `key` (the (user_id, key) unique index makes double fires a zero-row no-op); repeatable entries (spends, weekly wins) pass no key. Totals are SUMs via User::walletTotals() — there is deliberately no balance column to drift. Earning requires a verified email, with ONE documented exception: the 25 XP first-team seed in the onboarding moment (key `first-team`).

## Pick'em settlement payouts are KEYED — superseding "weekly wins pass no key"
The older support.md line saying repeatable weekly wins pass no key predates event-driven settlement and is SUPERSEDED for pick'em: SettleSlate can double-fire (sweep overlap, retried jobs), so its payouts ride idempotency keys — `slate:{id}:win` (100 XP + 1 latte per winner) and `slate:{id}:pts` (points × 10 XP) — and the (user_id, key) unique index is what makes a re-settle pay nobody twice. Payouts happen ONLY at official settlement (past Cadence::officialFinal), never at the preliminary flip. The settle claim (whereNull settled_at → update) comes LAST; everything before it is idempotent by construction.

## kickoff_day is a weekday name, and a date cast is midnight UTC
Two ways to match "a game on this Saturday" that both fail silently.

`games.kickoff_day` stores a WEEKDAY NAME ("Sat"), not a date — GameFactory writes `format('D')`. Never compare it to a date string. Read the day off `kickoff_at`, converted to `cfb.timezone`: 20:00 ET Saturday is 00:00 UTC Sunday, so matching the UTC date drops the whole night window.

A column with a `date` cast (gameday_weeks.saturday) arrives as midnight UTC. Calling `->setTimezone('America/New_York')->startOfDay()` on it lands at 20:00 the PREVIOUS evening and yields the wrong day. Take `->toDateString()` and re-parse it in ET — a calendar date is re-pinned, never converted, the distinction Cadence already draws. Nothing throws either way; the query just finds nothing.

## Search is word-wise: all of your words, then whatever has the most
Scout's DatabaseEngine matched the ENTIRE query as one LIKE, so a second word not in the name took results to zero and word order decided whether anything was found. `App\Support\Search` now builds the match itself, per word. Scout still DECLARES the surface — `toSearchableArray()` says which columns, `#[SearchUsingPrefix]` says how — and both are read off the model rather than restated. Do not reintroduce `Model::search()` here.

Two passes. First: every word must match at least one column (AND across words, OR across columns) — one query, the path everybody is on. Second, ONLY when the first found nothing: OR across words, ranked by how many matched. The fallback can therefore never widen a search that was already working, which is what keeps "Rose Bowl" returning Rose Bowl games rather than every bowl.

`MIN_FALLBACK_MATCHES = 2` is the quality of that second pass. At one, "Rose Bowl" filled Players with everyone named Rose and Teams with Bowling Green — every row honestly matching a word, none of them the answer. A row has to corroborate itself.

STOPWORDS are not tidiness. "How many passing yards did Joey Aguilar throw?" put Adam Howanitz on top of Players — "How" is a real prefix match on a real surname — and buried the person named in the question. The answer layer taught people to type questions into this box. `at` is in the list because it is in every game name we hold and so discriminates nothing.

Relevance is applied as the FIRST orderBy and the group's own domain ordering follows it, so FBS-before-everyone and active-before-departed decide every tie. A query builder emits `order by` in call order — that sequence in `run()` is load-bearing.

The prefix strategy is not cosmetic: Athlete and Recruit carry it, and `LIKE 'agu%'` can walk athletes_last_name_index across 34,000 rows where `LIKE '%agu%'` cannot. Warm cost is 2-3.5 ms per group, ~32 ms for all six on one keystroke.

The Players and Recruiting roster filters share the splitter through `Search::everyTerm()` — AND only, no fallback, because a filter that widens when it fails to match is one nobody can trust.

## A conditional-UPDATE claim must read back the winner, not the row count
MySQL's affected-row count is rows CHANGED, not rows matched. So the shape `update([...]) === 0 ? refused : taken` is wrong for any claim a holder may renew: writing identical values inside the same second updates zero rows and reads as refused. `cfb:issue start` run twice in a row hit exactly this (ClaimWorkbookItem::take, 2026-08-28).

Keep the atomicity in the WHERE clause — that is what serializes concurrent writers — but decide the outcome by re-reading the winner: `->value('claimed_by') === $by`. The winner and the renewing holder both see themselves; a loser whose WHERE no longer matched sees the holder.

Pick'em settlement's `whereNull('settled_at')` claim is unaffected: it flips null to a timestamp, so a re-run always changes the row.
