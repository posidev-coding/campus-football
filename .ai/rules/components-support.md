---
paths:
  - 'resources/views/livewire/pickem-home.blade.php,resources/views/components/group-card.blade.php,app/Support/Lobby.php'
---

# Components Support

## A public room is transient — it leaves My Picks when its Saturday is played
Public rooms are one-Saturday contests. A room the reader played must NOT appear under "Week N Contests" — nor in the group switcher — the next week: carrying it over says they are already seated in this Saturday's public contests, when the point is that a fresh week starts with the decision unmade. `roomCards` keeps only rooms still on the card; `pastRooms` collects the played ones and renders as ONE door to History (route pickem.history) [SUPERSEDED 2026-09-01 by pass 2: the week tab renders NO door for them — History is a text door on the Results "Last week" heading row and a section chip; `pastRooms` still projects them so nothing can stack them]. Nothing is deleted — the membership, the leaderboard and the room's URL all outlive it.

`past` is read off the room's OWN Saturday, never off `week_id` alone: an ESPN week holds two Saturdays (2026 Week 1 = 8/29 and 9/5), so an 8/29 room still satisfied `week_id === $weekId` on the Tuesday after and rendered as "Public room · this Saturday". cards() reads the room contests' slate Saturdays as DATE STRINGS (a date column casts at the app timezone while the card being sold is an ET midnight — comparing instants makes the same Saturday four hours "earlier" and files a live room under played).

A room with NO slate at all is not past: its card was never published or was taken away, which is group.room.no_card, a different sentence. Never infer "played" from a missing row.
