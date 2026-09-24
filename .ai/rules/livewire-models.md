---
paths:
  - 'app/Support/WeekTrends.php,resources/views/livewire/group.blade.php,app/Models/Slate.php'
---

# Livewire Models

## The season ledger has a FOURTH join: WeekTrends
AMENDS "THE LEDGER IS THREE JOINS" (the practice-window rule). Since CFB-30 (2026-09-24), `App\Support\WeekTrends::for()` also reads settled entries with `where('slates.exhibition', false)`: the you-strip's run of recent places on the clubhouse Standings tab. Change one ledger join and change all four: seasonStandings, seasonHasHistory, the My Picks wins badge, and WeekTrends. A practice week showing up in the run is the same bug as one moving the season table.
