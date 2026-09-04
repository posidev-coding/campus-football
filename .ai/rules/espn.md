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
