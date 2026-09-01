---
paths:
  - 'app/Models/Standing.php,app/Services/Espn/Sync/SyncStandings.php'
---

# Sync

## ESPN's playoff_seed 0 is "unseeded", never first
ESPN seeds only the teams that have PLAYED and publishes `playoffSeed: 0` for the rest, so `ORDER BY playoff_seed` floated every team that had not kicked off above the ones that had won — the whole league, every opening weekend. Verified live 2026-09-01, mid-week-1 ACC: four 1-0 teams seeded 1-4, twelve 0-0 teams seeded 0, NC State 0-1 seeded 5, which ESPN renders winners → unplayed → loser. `win_pct` and `conf_win_pct` carry the same sentinel: 0.0000 for a team with no games is indistinguishable from one that has lost them all, so derive percentages from the win/loss columns and count no games as .500.

`Standing::inStandingsOrder()` therefore applies the seed only where EVERY row in that conference carries one (a correlated EXISTS; while any team is unseeded the key goes inert and records decide). Don't "simplify" it to seeded-teams-first — that puts an 0-1 team above a 0-0 team. And don't replace the seed with record sorting: measured over five completed seasons, record order moves a third of all rows off ESPN's position, because the seed is where head-to-head and division tiebreakers live.
