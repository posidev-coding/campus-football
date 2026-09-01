# Game modes: the three ways a contest is played

Every mode picks **against the spread** — the product's hard rule, not a
mode's choice — and every contest line obeys **the half-point law**: no line
ever sits on a whole number, so no pick can ever push. A pick wins or loses,
full stop. (`SpreadGrader` keeps a push branch purely as defense against
corrupt data.)

The engine seam: `ContestMode` (enum, labels, blurbs, icons, palettes, rule
lines) → `ModeEngine` subclasses in `app/Services/Contests/` (slate shape,
`pointsFor`, `pointsForPick`, publish validation). The stored mode values are
deliberately neutral (`classic`, `tiered`, `woodshed`); the product names live
in `label()` and Voice so a rename never touches data. The lobby's rules
explainer, the mode doors, the join landing and this document all read
`ContestMode::ruleLines()` — one source, so the game is never described two
ways.

**The parity principle (settled 2026-08-14): every mode's perfect week lands
at ~100 points.** Shotgun 10×10 = 100 · Triple Option 45+35+20 = 100 · The
Woodshed 90 + 6 + 5 = **101** — the founders' game keeps a one-point premium.
XP pays `points × 10` at settlement (floored at zero), so the rebalance also
equalized what a perfect week EARNS across modes; before it, a perfect
Shotgun week paid 100 XP against Triple Option's 300.

## Shotgun (`classic` · ClassicMode)

The casual door: **10 games, every one worth 10 points.** One decision per
game and nothing to weigh — which is exactly why it exists beside the tiered
main event. No tiers (`tier` stays null on every slate game — never a
default 1), no lock, no Bear. Perfect week: 100.

Shotgun is the one mode that FLEXES, so ten is its default and not its
promise: `settings.slate_size` is frozen per room at spawn (Week 0 seats
eight games, or seven), and `blurb()`/`ruleLines()` take that size so a
downsized room states its own count and its own perfect week. Every caller
holding a contest passes it; null keeps the mode's own shape, which is the
honest answer for the mode doors and the lobby explainer, where there is no
contest to describe. `LobbyFlavor::blurb()` is sized the same way — Upset
Alley's headline ten is seven on a Week 0 Saturday.

## Triple Option (`tiered` · TieredMode)

The main event: **15 games in three tiers of five, by game quality — tier 1
pays 9, tier 2 pays 7, tier 3 pays 4.** The commissioner sorts the card;
the Game Quality Score suggests, never authors. Values settled 2026-08-14
(from 3/2/1) for the parity principle. The proposed tier names — the Pitch,
the Keep, the Dive — remain screen vocabulary, not engine facts. Perfect
week: 100.

## Woodshed (`woodshed` · WoodshedMode)

The founders' game, recovered whole in August 2026 from the original
league's rules email and a working copy of its 2016 code (cfbpickem.net —
a MySQL database literally named `woodshed`), and implemented 2026-08-14.
The CODE has since been discarded, so its mechanics survive only as written
here — **for those, this document is the record** and anything not written
down is gone. The rules email itself survived and is preserved verbatim at
[woodshed.md](woodshed.md).

**The card:** 15 games in three tiers of five paying **8 / 6 / 4** — a
90-point slate.

**The Lock** — the only path to negative points. One game each week is
lock-eligible: the **featured game**, which IS the designated tiebreaker
game (`slates.tiebreaker_slate_game_id` — one designation, two jobs).
Locking is optional and stakes your existing pick there: correct pays
**+6** on top of the tier (a tier-1 featured game pays 14), wrong pays
**−4**. `picks.locked` is the wager — a deliberate stored choice, distinct
from the temporal kickoff lock, which remains a clock check and never a
column. `LockPick` holds every gate `MakePick` holds, plus the wager's
three: the mode must offer a Lock, only the featured game takes it, and
there must be a pick to stake. Toggling off before kickoff is the same
door. Signed columns (`picks.points`, `slate_entries.final_points`) exist
because of this mechanic: a backfired Lock is a real minus that persists.

**The Bear** — the house's mythical contestant. At publish, `BearPicks`
stamps a weekly theme onto the slate (`bear_theme`, rotating by week
number: favorites → dogs → home → road → alternating) and his side onto
every game (`slate_games.bear_team_id`). His picks are **public by
design** — a paw on his side of every card while you pick, plus a taunt
tagline in the reader's register (`picks.bear.tagline.*`; the theme line
itself stays a plain instruction). He cannot lock. At settlement, STRICTLY
beat his raw weekly total with your adjusted total and take **+5**; tie
him and take nothing. He sits in the This-week standings as a label row
and can never win the week — he has no entry. Sibling public rooms clone
the Bear verbatim with the slate (the identical-house-slate rule), which
is why `PublishSlate` only seeds him when `bear_theme` is still null.

**The tiebreaker** is pinned: a Woodshed slate's question is always the
featured game's combined points (the OG over/under), enforced at publish
by `picks.publish.featured_metric`. Perfect week: 90 + 6 + 5 = **101** —
and the +5 is structurally guaranteed at 15-for-15 with the Lock, since 96
beats the Bear's 90-point ceiling; perfection WITHOUT the Lock can stall
at 90 if the Bear also ran the table, because the beat is strict.

Founders' values are engine constants (`TIER_POINTS`, `LOCK_BONUS`,
`LOCK_PENALTY`, `BEAR_BONUS`); `contests.settings` remains the landing pad
for per-league overrides, deliberately not built yet.

## Shared law (all modes)

- Per-game lock at each kickoff; picks private until then
  (`Pick::visibleTo` — the Bear is the one documented exception, and he is
  not a Pick row).
- A missed pick is an ABSENT ROW worth zero — nothing ever picks on a
  user's behalf.
- The weekly clock: commissioner slates due Tuesday 23:59 ET; results
  preliminary at the last final, OFFICIAL Sunday noon ET (both
  admin-configurable via Cadence + Pick'em Settings). Payouts only at
  official.
- Tied weeks: closest tiebreaker call wins; silence loses to any answer; an
  unresolvable actual shares the win — never an invented number.
- One mode per group per season; one deliberate, announced pivot allowed
  before it is spent.

## OG heritage: what v4 adopted, what stayed in 2016

Recovered from the founders' rules email — kept verbatim at
[woodshed.md](woodshed.md) — and the original cfbpickem.net code. Adopted, with adaptations noted above: the tiered card,
the featured-game Lock (+6/−4, one per week — the old `save_pick.php`
unlocked every other game when a new lock saved), the Bear (user_id 0 in
the old database, his picks and weekly tagline visible while picking, the
strict beat-the-Bear bonus), and the featured-game over/under tiebreaker.

Left behind, deliberately:

- **Money.** Entry fees and the $70 / $35 / $35 weekly payout tree — v4's
  stakes are XP and Tallboys.
- **Divisions and their honors** (Overall / Division / Wildcard winners,
  the medal icons). The OG league itself dropped divisions at some point;
  v4 groups are flat.
- **The postseason** — top-10 playoff, consolation bracket, bowl pools,
  the Army/Navy-week intermission. A v4 season is the regular-season
  ledger, for now.
- **The single weekly deadline** (all picks due Saturday noon, 30-second
  grace, admin override). v4's per-game kickoff locks are strictly better:
  a Thursday mistake no longer freezes your whole Saturday.
- **The week 2–13 calendar and the week-4 league lockout** — v4 groups
  open and join year-round.
- **The earliest-submission second tiebreaker.** v4 shares a doubly-tied
  week instead — the honest office-pool outcome, and one less timestamp to
  argue about.
