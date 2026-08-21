---
paths:
  - 'resources/views/livewire/pickem-home.blade.php,resources/views/livewire/lobby.blade.php,app/Support/Lobby.php,routes/web.php'
---

# Livewire Support

## Two pick'em doors, no redirect between them, and the teaser is a COUNT
Pass 4 (2026-08-20) split the old lobby: MY PICKS (/picks, pickem-home) is the reader's own week, THE LOBBY (/lobby) is the contest browser. Both sit outside the flag middleware and both include partials/pickem-promise for guests and flag-off readers.

NEVER add a redirect between the two routes. /picks was a 301 to /lobby; browsers cache a 301 forever, so one pointing back would loop for every dev browser still holding the old one.

My Picks' lobby teaser reads Lobby::openRoomCount() — a lean COUNT — and must never call openRooms()/joinable(): a dashboard paying for the inventory graph to print an integer is what made the old screen heavy. LobbyRoomsTest pins count == joinable's transient half; that parity test is the only thing catching the drift.

Lobby::openRooms() is SEAT-INCLUSIVE and flags seated rooms rather than dropping them, because LobbyCatalog::shelves() has to tell "you're in this one" apart from "this Saturday can't seat it". Feasibility is NEVER asked at render time — resolve()/viableCount() is a slate suggestion per row; the dashed closed rows are inferred from the sweep's own output, and an empty lobby dashes nothing at all.
