---
paths:
  - 'resources/views/livewire/pickem-home.blade.php,resources/views/livewire/lobby.blade.php,resources/views/components/group-card.blade.php,resources/views/components/group-hero.blade.php'
---

# Components

## GROUPS and ROOMS are two products; one stack, with the kind said on every card
(Amended 2026-09, supersedes "never share a heading".) My Picks sells every seat in ONE
"Where you play" stack: groupCards (`! isLobby()`, alphabetical) then roomCards
(`isRoom()`, past Saturdays last) then tableCards (evergreen `isLobby() && ! isRoom()`),
concatenated by whereYouPlay() — projections of cards() only; never a fourth query. The
HEADINGS merged because three headings over one thumb of cards read as three products; the
DISTINCTION did not: every card leads its micro-line with its kind in the join-landing's
grammar ("Private group, all season ·" / "Public room · this Saturday ·" / "Always
open ·"), so the kind is said once per CARD instead of once per zone. A past room's line
says "Saturday played", never "this Saturday"; an evergreen is "Always open", never a
room's one-Saturday label and never "table" — two user-facing container nouns, still. The
definition line under the heading is Voice (`picks.whereplay.subheading`); the kind lines
are facts and stay plain.

A room keeps its URL forever and leaves the inventory when its week ends, so it has no slate for the CURRENT week and falls through the state match to `waiting` — which told a reader their public room was waiting on a commissioner it never had. `cards()` carries a `past` flag (`isRoom() && week_id !== $weekId`) and group-card's waiting branch tests it FIRST.

First-run means no PRIVATE groups, not no memberships: one public seat must not suppress the pitch. The three x-mode-door tiles stay the ONLY create affordance, and the Lobby door lives in `partials/lobby-door` so the first-run block and the screen foot render one door off one `roomsOpen` read — never two.

`x-group-hero`'s chip renders for both kinds (Public / Private). A badge only one side of a pair wears is a badge nobody reads as a pair.
