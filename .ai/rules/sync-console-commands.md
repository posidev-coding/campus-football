---
paths:
  - 'app/Services/Espn/Sync/SyncGames.php,app/Console/Commands/SweepLiveSummariesCommand.php,routes/console.php'
---

# Sync Console Commands

## The live tier's guard must be able to START coverage, not just continue it
`SyncGames::live()` guarded on `Game::inProgress()->exists()` alone — a deadlock. A game sits at `pre` until a request says otherwise, and the minute tier refused to spend one until something was already `in`. Only the hourly `--tier=current` could break it, so a noon kickoff had no score, clock or gamecast until 13:00 — and everything else keyed on `inProgress()` (the box-score sweep, the `live` queue, GradeGamePicks) stayed empty with it. Measured 2026-08-29: UNC at TCU 10-10 in the 2nd on ESPN, `cfb:games --tier=live` reporting "0 changed, 0 requests".

The guard now also matches `Game::expectedLive()` — not completed, kickoff inside the last 6 hours. Bound the floor: "any unfinished past kickoff" lets a postponed game hold the tier open all season.
