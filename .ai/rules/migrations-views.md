---
paths:
  - 'app/Models/**, database/migrations/**, resources/views/**'
---

# Migrations Views

## Pick points are SIGNED — a backfired Woodshed Lock is a real −4
`picks.points` and `slate_entries.final_points` became SIGNED on 2026-08-14 for the Woodshed's Lock wager (+6 right, −4 wrong — the only path to negative points). Never render or aggregate points assuming ≥ 0: pick-card's loss branch prints the real number (it once hardcoded 0), weekly totals can be negative, and XP grants floor at zero (`SettleSlate::pay()` skips non-positive — the wallet stays earn-only). `picks.locked` is the stored WAGER, a deliberate player choice written only through `LockPick`; the temporal kickoff lock remains a clock check (`Game::hasKickedOff()`) and must never become a column — two different "locked"s, kept apart on purpose (see the Pick model docblock and docs/game-modes.md).
