---
paths:
  - 'app/Services/Espn/Sync/SyncGames.php,app/Services/Espn/**'
---

# Espn

## ESPN buckets scoreboard events by EASTERN date — never ask for "today"
A 22:30 ET Saturday kickoff lives on `dates=20251129`; `dates=20251130` returns zero events. Verified against the feed.

So `live()` asking `day()` for the current ET date froze every late game at midnight: the live window runs to 03:00 exactly to cover a West Coast night game, and at 00:05 ET the request rolled to the new date and came back empty. Derive the scoreboard date from the GAME's kickoff, not the wall clock — `liveScoreboardDays()` does, and spans both days as one range when a slate straddles midnight, so it is still one request.

## ESPN sends -1 for "does not apply", and one bad column skips the whole game
ESPN's situation block uses -1 as a placeholder rather than omitting the key: on a kickoff or an extra point there is no down and distance, so it sends `distance: -1`. `distance`, `down`, `yard_line` and both timeout columns are UNSIGNED TINYINT, so MySQL refuses the write in strict mode — and store()'s per-event try/catch then skips the ENTIRE event. The score, clock, period and status are lost along with the one column that was bad.

Measured 2026-09-03: Akron at Wake Forest sat frozen at `pre` 0-0 from its preseason row while ESPN had it 38-10 in the fourth. Every live pass for the whole second half logged "Skipped an unstorable game" and moved on.

Run every situation number through `unsigned()` — null outside 0-255, `min: 1` for `down`. Out of range is out of DATA, never a zero: writing 0 renders a real "1st & 0" on the gamecast. Same rule as the unranked 99 sentinel and the negative competitor ids.

Per-event isolation limits the blast radius to one game; it does not make that game correct. Any new unsigned column fed from a feed needs the same guard.

## Odds must never fail silently — parse every known shape, fall back to core, log the shape that beat us
Measured 2026-09-23: ESPN's site showed a spread on every Saturday game while our newest `current` line was stamped Sep 15 — the scoreboard's odds stopped parsing and nothing said so (per-event try/catch swallowed it; a block with no top-level `spread`/`overUnder` was skipped without a word). Every group's build door shut on four stale lines. `SyncOdds` now reads the top-level numbers, the `pointSpread`/`total`/`moneyline` market blocks, and `details` ("UGA -10"); skips `$ref` stubs; and logs ONE warning per run with the unparsed block's keys and a sample. The favorite comes only from a source that NAMES it (side flag, home market line sign, `details` abbreviation) — never from the top-level `spread`'s sign, whose convention is ESPN's; a wrong favorite grades picks backwards. `cfb:games --tier=current` then runs `SyncOdds::refreshStale()`: core `events/{id}/competitions/{id}/odds` for each upcoming Saturday-window game in the week lacking a current line captured in the last 3 hours — zero requests on a week the scoreboard behaves.

## A refused scoreboard steps down and fails loud — it never reads as a quiet week
From Sep 15 2026 ESPN answered 400 to the scoreboard; `range()` returned 0 on the null body, every week/season run recorded "complete", and no game or line was written for eight days. `SyncGames::scoreboard()` now walks a ladder — the one-request window, `limit=300`, then one ET day at a time (limit 1000, 300, none) — remembering refused shapes per RUN (instance, never static, so it recovers by itself). If every shape is refused it THROWS, so the feed run records failed. `--tier=current` still runs the core odds fallback before rethrowing. `EspnClient` logs the query and 300 chars of body on any unsuccessful response.
