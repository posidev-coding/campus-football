---
paths:
  - 'app/Services/Espn/Sync/SyncGames.php,app/Services/Espn/**'
---

# Espn

## ESPN buckets scoreboard events by EASTERN date — never ask for "today"
A 22:30 ET Saturday kickoff lives on `dates=20251129`; `dates=20251130` returns zero events. Verified against the feed.

So `live()` asking `day()` for the current ET date froze every late game at midnight: the live window runs to 03:00 exactly to cover a West Coast night game, and at 00:05 ET the request rolled to the new date and came back empty. Derive the scoreboard date from the GAME's kickoff, not the wall clock — `liveScoreboardDays()` does, and spans both days as one range when a slate straddles midnight, so it is still one request.
