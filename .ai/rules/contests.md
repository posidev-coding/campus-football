---
paths:
  - 'app/Services/Contests/**'
---

# Contests

## game_odds.spread is the HOME handicap — never read its sign as the favorite's
Verified against live 2026 lines: ESPN's scoreboard spread is home-relative — negative when home is favored, POSITIVE when the road team is (Navy at Army stored +2.5 with details "NAVY -2.5"). The favorite's burden is abs(spread); favorite_team_id says whose it is. SpreadGrader is built on magnitude + favorite_team_id and is invariant to the sign; any new consumer that reads the sign as "the favorite's number" will silently flip every away-favorite grade. Line MOVEMENT is correctly computed as abs(current - open) in that home-relative space, where a flip through zero counts as real movement.

## The half-point law: contest lines never sit on whole numbers
A league rule from the founders, hard product requirement: every contest spread is a HALF POINT so no pick can ever push. slate_games.spread is the COMMISSIONER'S line, not the book's — seeded from game_odds current via ContestLine::seedValues (whole numbers shade down 0.5), adjustable through SetSlateGameLine within ContestLine::MAX_ADJUSTMENT (3.0) of the book, floor MIN_BURDEN (0.5), favorite never flips. market_spread keeps the book's number for the audit trail. PublishSlate VALIDATES and commits — it never copies the market. The engine refuses whole-number lines with picks.publish.whole_line. SpreadGrader keeps its push branch purely as defense against corrupt data.
