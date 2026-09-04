---
paths:
  - 'app/Support/Cadence.php,app/Actions/PublishSlate.php,app/Filament/Pages/PickemSettings.php,app/Models/Slate.php'
---

# Pages Models

## The practice window is scoped by KIND: private groups rehearse, public rooms count
Amends "The practice window: counts_from decides exhibition, once, at publish" (2026-09-03, founder's call). `counts_from` is still one league-wide date, but it covers PRIVATE GROUPS only unless `pickem_settings.practice_includes_rooms` is on: the rooms and evergreen tables are the shop window and count from the day they open, so a rehearsal weekend cannot silently make a stranger's first Saturday worth nothing. `Cadence::isPractice($saturday, Group $for)` takes the group as a REQUIRED second argument — no default, because a caller that could omit it would be answering for a kind it never looked at — and PublishSlate::force passes `$slate->contest->group` (its `loadMissing('contest.group')` is load-bearing). Scope on `isLobby()`, never `isRoom()`: a table is no more the founder's rehearsal than a room is. The boolean is NOT nullable, unlike the clock overrides beside it — those answer "what time?" where blank means the shipped default, this answers a yes-or-no whose false is a decision — and it does nothing while counts_from is null. The preflight prints the scope beside the date because two scopes wear the same date.
