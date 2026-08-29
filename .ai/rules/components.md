---
paths:
  - 'resources/views/livewire/pickem-home.blade.php,resources/views/livewire/lobby.blade.php,resources/views/components/group-card.blade.php,resources/views/components/group-hero.blade.php'
---

# Components

## GROUPS and ROOMS are two products and never share a heading
My Picks splits `cards()` three ways — groupCards (`! isLobby()`), roomCards (`isRoom()`), tableCards (evergreen `isLobby() && ! isRoom()`) — and each zone carries a one-line definition, because one "Your groups" heading over both meant a public room joined an hour ago sat under the season-long word and nothing said either was what it was. Projections only; never a fourth query. Evergreens may not be folded into either other zone — "public room that plays one Saturday" is a label the data does not support for a table with no week.

A room keeps its URL forever and leaves the inventory when its week ends, so it has no slate for the CURRENT week and falls through the state match to `waiting` — which told a reader their public room was waiting on a commissioner it never had. `cards()` carries a `past` flag (`isRoom() && week_id !== $weekId`) and group-card's waiting branch tests it FIRST.

First-run means no PRIVATE groups, not no memberships: one public seat must not suppress the pitch. The three x-mode-door tiles stay the ONLY create affordance, and the Lobby door lives in `partials/lobby-door` so the first-run block and the screen foot render one door off one `roomsOpen` read — never two.

`x-group-hero`'s chip renders for both kinds (Public / Private). A badge only one side of a pair wears is a badge nobody reads as a pair.
