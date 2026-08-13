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
