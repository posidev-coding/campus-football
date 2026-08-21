---
paths:
  - 'app/**,resources/views/**,tests/**,docs/**'
---

# App Views

## The pick'em vocabulary is law, in code as well as copy
Settled 2026-08-20 and swept through the whole tree; long form in docs/product.md. GAME = a football game on a field, nothing else. PICKS = a user's calls. SLATE = one contest's games for one Saturday. ENTRY = a user's seat and results (slate_entries). CONTEST = the playable thing; ROOM = the colloquial word for a public one-Saturday contest; GROUP = the private season-long container. LOBBY = where open contests are browsed and entered.

BANNED: "board" (retired to SLATE — PickemVoiceTest sweeps the Voice families, and the internals are clean: pickem:publish-slates, slateGames(); the Stats leaderboards keep the word because they really are boards) and "floor" for the lobby (Cadence::activeSaturday is the Saturday this pick'em week is ON; numeric floors — RankLadder, TeamPalette ratios, rate-stat minimums — and the PWA's offline floor are a different word and stay).

Also: a group plays a MODE, never "a game"; the screen says "your groups", never "your games".
