---
paths:
  - 'app/Enums/ContestMode.php,app/Enums/LobbyFlavor.php,resources/views/livewire/group.blade.php,resources/views/livewire/join.blade.php'
---

# Views Livewire

## Copy that states a game count is sized from the CONTEST, never the mode
Shotgun's slate size is frozen per room in `contests.settings.slate_size` (LobbyCatalog::resolve downsizes on a thin Saturday — Week 0 seats 7 or 8), so the mode's default 10 is a default, not a promise. `ContestMode::blurb()/ruleLines()` and `LobbyFlavor::blurb()` take an optional `?int $games`: every caller holding a contest passes `$contest->mode->engine($contest->settings)->slateSize()`; null means "the mode's own shape" and is only correct where there is no contest (mode doors, mode cards, the lobby's How-it's-played explainer). Found in the Week 0 rehearsal: the room screen and the invite landing read "10 games, 10 points each" over an 8-game slate the same screen counted as "0 of 8 picks" — the slate came from the contest and the sentence came from the enum. Perfect-week numbers are derived (count x ClassicMode::GAME_POINTS), never re-typed.
