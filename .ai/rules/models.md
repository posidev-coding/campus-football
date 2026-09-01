---
paths:
  - 'app/Services/Contests/ModeEngine.php,app/Actions/CrushTallboy.php,app/Actions/LockPick.php,app/Models/Pick.php'
---

# Models

## Wager eligibility is per SLATE, and picks.locked carries both wagers
`supportsTallboy()` is asked of the ENGINE built from the contest's own settings, never of a per-flavor list. Ranked Action and all five conference rooms are dynamic-size: their slate is as big as the Saturday allowed and frozen into contests.settings.slate_size at spawn. A thin conference week seats three games — a 30-point perfect week where ±5 is 16.7% and over the ~15% ceiling. A static allowlist ships a silent over-leverage bug on the first thin Saturday. Same shape as ContestMode::blurb($games) taking the contest's size rather than the mode's default.

`picks.locked` IS THE WAGER COLUMN and carries BOTH mechanics — the Woodshed's Lock (+6/−4, featured game, LockPick) and the Tallboy (+5/−5, any game, CrushTallboy, costs a credit). One column, no migration, because a slate can only ever offer one: supportsTallboy() excludes any mode that supportsLock() or carries a kicker. A locked pick under a mode offering neither grades PLAINLY — never as a wager nobody bought.

ONE WAGER PER SLATE is what the ceiling is a guarantee about, so moving the Tallboy is a MOVE, not a second purchase; only a new wager spends. A pull refunds as a NEW POSITIVE ROW, never an edit. A wager whose game has kicked off neither moves nor pulls.

Two-Minute Drill is excluded by a `tallboy => false` settings knob — IDENTITY, not arithmetic (its 10% is inside the ceiling). The engines never hear the word "flavor", so the exclusion has to arrive as a plain knob.

TallboyWagerTest reds on a break-back of either the frozen-size read or the negative arm.
