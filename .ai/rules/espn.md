---
paths:
  - 'app/Services/Espn/Sync/SyncGames.php,app/Services/Espn/**'
---

# Espn

## ESPN buckets scoreboard events by EASTERN date — never ask for "today"
A 22:30 ET Saturday kickoff lives on `dates=20251129`; `dates=20251130` returns zero events. Verified against the feed.

So `live()` asking `day()` for the current ET date froze every late game at midnight: the live window runs to 03:00 exactly to cover a West Coast night game, and at 00:05 ET the request rolled to the new date and came back empty. Derive the scoreboard date from the GAME's kickoff, not the wall clock — `liveScoreboardDays()` does. It never sends a range (ESPN 400s every multi-day `dates` since Sep 2026): on a midnight straddle it takes the two most recent dates and alternates them by minute, so it is still one request a minute.

## ESPN sends -1 for "does not apply", and one bad column skips the whole game
ESPN's situation block uses -1 as a placeholder rather than omitting the key: on a kickoff or an extra point there is no down and distance, so it sends `distance: -1`. `distance`, `down`, `yard_line` and both timeout columns are UNSIGNED TINYINT, so MySQL refuses the write in strict mode — and store()'s per-event try/catch then skips the ENTIRE event. The score, clock, period and status are lost along with the one column that was bad.

Measured 2026-09-03: Akron at Wake Forest sat frozen at `pre` 0-0 from its preseason row while ESPN had it 38-10 in the fourth. Every live pass for the whole second half logged "Skipped an unstorable game" and moved on.

Run every situation number through `unsigned()` — null outside 0-255, `min: 1` for `down`. Out of range is out of DATA, never a zero: writing 0 renders a real "1st & 0" on the gamecast. Same rule as the unranked 99 sentinel and the negative competitor ids.

Per-event isolation limits the blast radius to one game; it does not make that game correct. Any new unsigned column fed from a feed needs the same guard.

## Odds must never fail silently — parse every known shape, fall back to core, log the shape that beat us
Measured 2026-09-23: ESPN's site showed a spread on every Saturday game while our newest `current` line was stamped Sep 15 — the scoreboard's odds stopped parsing and nothing said so (per-event try/catch swallowed it; a block with no top-level `spread`/`overUnder` was skipped without a word). Every group's build door shut on four stale lines. `SyncOdds` now reads the top-level numbers, the `pointSpread`/`total`/`moneyline` market blocks, and `details` ("UGA -10"); skips `$ref` stubs; and logs ONE warning per run with the unparsed block's keys and a sample. ESPN's `spread` is HOME-relative (verified 2026-09-23: home dog TENN `spread: 4.5` beside "TEX -4.5"; home favorite UGA `-14`), and a line rebuilt from `pointSpread.home` or `details` is stored in that same convention so movement compares like with like. The favorite comes from the side flag first, else the home-relative sign. NOTE the September 2026 outage turned out to be the scoreboard 400 on date ranges (next rule), not a shape change — the payload shape was unchanged. `cfb:games --tier=current` then runs `SyncOdds::refreshStale()`: core `events/{id}/competitions/{id}/odds` for each upcoming Saturday-window game in the week lacking a current line captured in the last 3 hours — zero requests on a week the scoreboard behaves.

## Scoreboard: no date ranges, limit=300, and a refusal fails loud
Measured in production 2026-09-23: `dates=YYYYMMDD-YYYYMMDD` answers 400 "Failed to get events endpoint." at any limit; `limit=1000` on one day silently returns ESPN's default 25 events (65 existed); `limit=300` or no limit returns all 65. From Sep 15 the week/season tiers wrote nothing while recording "complete". `SyncGames::scoreboard()` sends `limit=300` always (never larger), tries a range only when no refusal is cached (`espn:scoreboard:ranges-refused`, 12h), then walks the window one ET day at a time, then a day with no limit. Only a definite 400 (`EspnClient::lastStatus()`) teaches a refusal — a timeout or 5xx never does. A day refused on every shape is reported in `missing`: range() stores what arrived, then THROWS naming the days; season() finishes every window before throwing. Page-size payloads (25/300) are flagged per response in ask(). `--tier=current` still runs the core odds fallback before rethrowing.

## Every games sync names its season
A bare `cfb:games` resolves `--year=current` through CfbCalendar, and every scheduled `cfb:games` entry (and SyncHealth's run-a-task list, and PickemPreflight's remedies) names `--year=current`. The schedule once ran the current/recent tiers bare, `config('cfb.season')` was 2025, and all of September 2026 they synced 2025's final week (Dec 8-13) — every hour — while this season went unwritten. `SyncScheduleTest` fails if a bare non-live entry returns.
