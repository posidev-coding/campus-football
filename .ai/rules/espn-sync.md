---
paths:
  - 'app/Services/Espn/**'
  - 'app/Console/Commands/**'
  - 'app/Jobs/**'
  - routes/console.php
  - 'routes/**'
---

# ESPN sync

Long-form reference, including every measurement behind these: `docs/espn-data.md`
and `docs/operations.md`.

## Never write a default when a feed returns nothing
`EspnClient` returns `null` for "no data". Callers skip; they never substitute.
v3 defaulted standings to zero on a lookup miss and overwrote 9-1 teams with 0-0.

## Read records and stats by NAME, never by array position
v3 indexed `stats[0]`/`stats[1]` and broke every time ESPN reordered. Player
lines are positional arrays with a parallel `keys[]` — zip them.

## Non-positive ESPN ids are not real entities
An unannounced fixture is team `-1` (home) / `-2` (away) named "TBD"; a box-score
"Team" row is a negative athlete id. Map to null or skip — the columns are
unsigned and inserting one throws.

## Isolate every event in a loop over a payload
One bad game must not cost a season. A throw inside `SyncGames::range()` once
aborted the whole scoreboard request and silently lost all 43 bowl and playoff
games behind it. Per-event `try/catch`, the same way job fan-out isolates teams.

## Respect the sync cost tiers
Live scoring is ONE request per minute in total — a single scoreboard payload
carries every live game's score, clock, period and status. Never decompose it
per game. v3 burst to ~20 requests/second.

    live 0-1 · today 1 · current 1 · recent 2 · season 9

## Fan out for isolation and latency, never for throughput
Decomposing something that is already one request is strictly worse. Fan out
only by natural unit where the count is high: per game, per team, per week.

## Don't re-sync what cannot have changed
Published rankings never change retroactively; a final game's summary never
changes. Before scheduling a sweep, ask what new information the run obtains.

## Sync writes only rows that actually changed
`fill` + `isDirty`. Scale-to-zero MySQL means writes are not free.

## The site host allowlists HTTP-client User-Agents
A custom agent gets 403 from the `site` host (scoreboard, summaries) while
`core` and `web` keep working — so it fails silently and partially.
`config('espn.http.user_agent')` is `GuzzleHttp/7`; `ESPN_USER_AGENT` overrides.

## Never hardcode the current season
`App\Services\CfbCalendar` is the only source of truth. Do not read
`config('cfb.season')` and do not select "the latest season" — a season exists
in the database months before it is played. `currentYear()`, `resultsYear()` and
`scoreboardYear()` answer different questions; conflating them empties a screen.

## verified middleware is reserved for participation surfaces
Email verification gates Pick'em actions and XP earning ONLY — never reading your own data. /account sits behind auth alone; never re-add `verified` to it. Unverified accounts are nudged (Home/Account/Picks callouts), rewarded on verify (100 XP + 1 Tallboy), and pruned after User::VERIFICATION_GRACE_DAYS instead of being walled out. The v3 lesson in the route comment is "middleware actually applied", not "verify early".

## A game stuck live has exactly one escape, and it is the summary
`SyncGames::store()` is the ROUTINE writer of `games.status` and `games.completed`, and it can only correct an event its scoreboard payload actually carries. When ESPN leaves an entry mid-quarter, or the event falls outside the ET date bucket `liveScoreboardDays()` derives from our own stored `kickoff_at`, nothing in the scoreboard path un-sticks the row. Every screen reads live before final — game card, game page, pick card, league sheet, search — so a finished game wears "5:00 - 4th" indefinitely and stays `inProgress()` forever, holding open the live tier's guard, `cfb:summaries:live`, the scoreboard's live flag and pick grading with it. Measured 2026-09-06 on game 401856636.

It also costs. `SyncGameSummary::isStale()` treats a game and its summary disagreeing as permanently stale, so the two-minute sweep re-fetched that 544 KB payload for the rest of the season, learning nothing each pass.

The summary is fetched by EVENT ID, not by date, so it is the one source that cannot lose a game to a bucket — and its header already carried `type.completed`, which we read and discarded. `SyncGameSummary::reconcileFinal()` (PR #151, 535405e) writes it back: status, detail, period, clock, the final score where the header names both sides, the situation columns cleared, and `GameWentFinal` so a rescued final grades by the scoreboard's own path. A side the header does not name is left alone, never zeroed.

So the scoreboard is no longer the only writer — it is the only UNGATED one. `reconcileFinal()` is the sanctioned second, and it fires only when `Game::isStuckLive()` holds: in progress AND past `KICKOFF_GRACE_HOURS` (6). Never eagerly. ESPN briefly reports a game complete and flips it back, and premature finality grades picks and flips a slate to prelim. Any future source that can contradict the scoreboard needs the same shape: late, gated on the clock, and riding a request already paid for.
