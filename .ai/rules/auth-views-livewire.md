---
paths:
  - 'app/Actions/JoinGroup.php,resources/views/livewire/join.blade.php,resources/views/livewire/auth/register.blade.php,resources/views/livewire/group.blade.php'
---

# Auth Views Livewire

## A private seat is not gated on verification; the picks are
AMENDS "verified middleware is reserved for participation surfaces": since 2026-09-02 `JoinGroup` seats an unverified account in a PRIVATE group — the invite code is the credential, and the seat earns nothing (the first-group XP waits for the first seat taken verified). Public rooms keep the gate: capped, house-run seats. Picks, the Lock, the tiebreaker, CreateGroup and every wallet write keep theirs. The funnel this replaced: register, land back on the same invite card with a button that refused you, lose the invite when the verify click landed on Home. So the join screen parks `join.auto` (the code) beside `url.intended` in its guest arm and `seatOnReturn()` takes the seat in mount() on the way back from register — the reader lands in the clubhouse, never the card twice — the clubhouse carries the verify callout, and the register screen names the group off the intended URL. Do not move the gate back into JoinGroup for private groups, and do not drop the clubhouse callout: it is the only nudge a fresh seat sees.
