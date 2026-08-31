# Tallboys — giving the currency a job

> **Handoff note for a fresh session.** This plan is self-contained: everything
> needed to build it is below, including the reuse findings that halve the work and
> the traps that would otherwise ship silently. Read `CLAUDE.md` and the matching
> files in `.ai/rules/` first, as always. The decisions in this document were worked
> through with the human on Aug 31, 2026 and are **final** — build them, do not
> re-open them. Where a decision looks wrong, the reasoning is kept beside it; read
> that before arguing with it. Ship the PRs bottom-up in the order given; **supply
> ships before sinks**, which is the one ordering constraint that matters.

## Context

Beast Lattes are earned in exactly two places and spent in zero.

`App\Actions\GrantWalletEntry` pays 1 latte on `email-verified` (once ever, keyed)
and 1 on `pickem-win` (per weekly win). Every other earn in the class —
`first-team`, `first-group-created`, `first-group`, `pickem-entered`,
`pickem-points`, `talk`, `film-room` — passes `lattes: 0`. No code anywhere writes a
negative-latte row. A median user holds **one latte, forever**, and
`x-wallet-chips` renders a number that never moves again.

That is worse than an unused feature, because onboarding already makes the app's
loudest promise about it. The signup splash *closes* on `splash.warmup.latte`,
holding the screen longest because "the last thing read is the thing remembered".
The guided tour says "The app runs on Beast Lattes." The verify nudge sells "your
first Beast Latte and XP are waiting." The app promises a currency that does
nothing. This is promise-debt, which is why the card is filed critical rather than
as a nice-to-have.

**The ledger is already built for the fix.** The `wallet_entries` migration says it
outright: signed integers, "a contest entry spends every entry", repeatable rows
pass no key. The plumbing exists; only the economy is missing.

### The name goes too

"Beast Latte" is a two-step in-joke — Busch Light's "Busch Latte" nickname crossed
with Milwaukee's Best as "the Beast" — that most users will not land. Worse, it
fights its own art: `public/brand/currency/README.md` describes the icon as
"unmistakably a tallboy through four cues", so the picture has always been a tallboy
while only the name said latte. That mismatch is the likely reason the mark reads
poorly at chip size — the label band exists to sell "latte", and it is what eats the
silhouette.

**Tallboy** fixes the mismatch, needs no explanation from anyone who has bought a
16oz can, and is a generic can size rather than anyone's trademark. (Naming it after
an actual brand was considered and dropped: fan tolerance for shirts and decals is
not the same category as use in commerce, and it would have reversed the currency
README's explicit "copy no brewery's trade dress… do not add one later.")

## Decisions (final — do not re-litigate)

### 1. The currency is a Tallboy

"Beast Latte" is retired everywhere: copy, Voice keys, asset filenames, docs.

### 2. The ledger column `lattes` is renamed `credits`

Neutral in the schema, per the house precedent stated on `ContestMode`: backing
values are "deliberately neutral where the product name is marketing… so a rename
never touches data." `credits` survives the next rebrand; `lattes` did not.

### 3. Tallboys are the Lobby's currency, not the app's

**Earned everywhere** (all play feeds XP, and XP feeds rung-ups); **spent only in
public rooms.**

This is what dissolves the multi-group problem. If credits were spendable inside
private groups, a user in four leagues would need four times the supply, and any
"guarantee everyone can play" rule would have to know how many groups you are in.
You never spend inside a group, so the question never arises.

It also reframes into a growth loop rather than a limitation: Tallboys are the
mechanism that makes a private-league player curious about the Lobby. The honest
cost is that a user who only ever plays one league with friends holds a currency
they cannot use — which is why the wallet chip must say where credits are spent
(PR 6, PR 7), instead of promising a vague something.

### 4. Two sinks, each with its own verb

- **Marquee entry — "ice down a Tallboy".** 1 credit for the **Spotlight shelf
  only** (`LobbyShelf::Spotlight`: Ranked Action, Under the Lights). The Conference
  family, Quick Hits and House rooms stay free, so a newcomer with no balance is
  never walled out of the Lobby — which matters because the Lobby is the front door
  for anyone without a private group.
- **The wager — "crush a Tallboy".** 1 credit staked on one game, priced below.

### 5. The wager is symmetric, +5/−5 — flat, never scaled

Three reasons, in order of weight.

**It breaks ties structurally.** Most games are worth 10 (`ClassicMode::GAME_POINTS`),
so every score sits on a 10-point lattice. A ±10 wager keeps you *on* that lattice
and therefore breaks no ties at all — it only moves you a bucket. At ±5, a wagering
player's score always ends in 5 and a non-wagering player's always ends in 0, so
**the two can never tie**. Critically it does not hand the tie to whoever paid: half
the time the separation is downward. It separates without favoring, which is exactly
what the pay-to-win objection asks for.

**It matches the only precedent in the app.** The Woodshed Lock is +6/−4 against
8/6/4-point tiers — about a 6% swing on a 101-point week. ±5 on a 100-point week is
~5%. A ±10 would have been double the Lock's leverage and the largest single swing
anywhere in the app.

**It makes the two sinks compete.** At ±10 the wager was strictly the better buy and
nobody would ever ice one down for a seat. At ±5 the two are close enough in value
that the choice is live every week, which is what makes holding a balance
interesting.

It is **EV-neutral** either way: picks are against the spread, so the true hit rate
is ~50%. A Tallboy therefore buys *variance*, not *advantage* — this is the whole
answer to "won't power users just buy wins?" They cannot. Someone holding six
credits cannot grind up the standings; they can only be spectacularly right or
spectacularly wrong more often. It self-balances as a comeback mechanic: variance is
worth something to whoever is behind and actively costly to whoever is ahead, so
**the leader spending credits is making a mistake**.

**Flat ±5 is deliberate even where it exceeds the game.** Triple Option's tier-3
games are worth 4, so a wager there outweighs the game itself. Keep it. It makes
junk games worth wagering on, which is interesting, and the alternative — scaling to
the tier — yields fractions on 9- and 7-point games. This is a choice, not an
oversight; do not "fix" it.

### 6. Wager eligibility: no mode that already carries a kicker, plus one identity exclusion

- **Back Porch** is out: it runs `ContestMode::Woodshed`, which owns the Lock. A
  slate must never offer two wagers.
- **Upset Alley** is out: its `underdog_ml` kicker already stacks onto winning picks,
  and a second modifier on the same pick is unreadable.
- **Two-Minute Drill is out on identity, not arithmetic.** At ±5 its leverage is ~9%,
  comfortably inside the ceiling below, so the math no longer excludes it. It is held
  out because its own blurb sells it as "The flash card: 5 games, in and out" — the
  room exists to be frictionless, and a wager is friction. Keeping one public shelf
  with zero spend decisions is also the clean answer to "is the Lobby pay-to-play?".

**The ~15% ceiling** stays as the guard for future flavors: a wager may never exceed
roughly 15% of a perfect week. Shotgun 10×10 → ~4.8%. Under the Lights 8×10 → ~5.9%.
Two-Minute Drill 5×10 → ~9.1%.

> **Trap: eligibility is per SLATE, not per flavor.** Ranked Action and all five
> conference rooms are `LobbyFlavor::dynamicSize()` — their slate is as big as the
> Saturday allows. A thin conference week could seat 3 games, making a perfect week
> 30 and a ±5 wager **16.7%, over the ceiling**. So `supportsTallboy()` must be
> evaluated against the contest's own frozen `slate_size` at publish time, exactly
> the way `ContestMode::blurb($games)` takes the contest's size rather than the
> mode's default — "a pitch that says '10 games' over an eight-game card is the room
> lying about the game it is selling." A static per-flavor allowlist ships a silent
> over-leverage bug on the first thin Saturday.

Which leaves, today:

| Room | Shelf | Entry | Wager |
| --- | --- | --- | --- |
| Ranked Action | Spotlight | 1 credit | yes |
| Under the Lights | Spotlight | 1 credit | yes |
| SEC Showdown | Conference | free | yes |
| Big Ten Blitz | Conference | free | yes |
| ACC Action | Conference | free | yes |
| Big 12 Shootout | Conference | free | yes |
| Pac-12 After Dark | Conference | free | yes |
| House rooms (flavorless) | House | free | yes |
| Two-Minute Drill | Quick Hits | free | no — identity |
| Back Porch | Quick Hits | free | no — Woodshed Lock |

Note the **whole Quick Hits shelf is credit-free**: no entry cost, no wager. That
fell out of the rules rather than being designed, and it is worth preserving.

### 7. Earn side, and the cooler

Keep `email-verified` and `pickem-win` exactly as they are. Add **XP rung-up grants**
(the six `RankLadder` promotions), **streak/milestone grants**, and a **graduated
weekly top-off**.

The top-off exists because rung-ups are front-loaded and then stall. `RankLadder`
thresholds are 0 / 250 / 750 / 1750 / 3500 / 7000 / 15000, and a verified account
starts at 125 XP, so Redshirt lands in week one — then Rotation, Starter and Captain
arrive further and further apart. A casual player reaches Rotation and their income
stops around week four, exactly when the habit should be forming. Without a floor the
economy only works for people who are already winning.

**The cooler holds six.** The grant is tiered on current balance, so a single rule is
both a floor for the thirsty and a ceiling on hoarding:

| Balance | Weekly top-off | In plain words |
| --- | --- | --- |
| ≤ 2 | **+2** | cooler's empty — restock |
| 3–5 | **+1** | room left — top it off |
| ≥ 6 | **none** | you're stocked |

The equilibrium falls out on its own. A power user spending 2 a week (one marquee
entry, one wager) hovers at ≤ 2 and stays fully funded. A dormant-but-activated
account drifts up to 6 and stops. Six is three weeks of maximum spend — enough to
bank a rivalry-week splurge, low enough that sitting on credits visibly stops paying.

A **flat 2/week was considered and rejected**: over a ~14-week season it equals
maximum demand on its own, leaving rung-ups, wins and milestones as pure surplus at
roughly 1.7× oversupply. The balance would become a number that only goes up, which
is the same failure this whole plan is fixing, pointed the other way.

**The rung-up and milestone amounts, decided.** These are constants in
`GrantWalletEntry`, so rebalancing is a deploy rather than a migration — the same
property `RankLadder` was built for. Build these numbers; do not stop to ask.

| Promotion | XP threshold | Credits | Lands roughly |
| --- | --- | --- | --- |
| Redshirt | 250 | **2** | week 1 |
| Rotation | 750 | **3** | week 3 |
| Starter | 1750 | **4** | week 7 |
| Captain | 3500 | **5** | end of a strong season |
| All-American | 7000 | **6** | beyond one season |
| Legend | 15000 | **8** | multi-season |

| Milestone | Credits | Key shape |
| --- | --- | --- |
| First slate ever entered | **1** | once ever |
| 5 weeks entered | **2** | once ever |
| 10 weeks entered | **3** | once ever |
| A perfect week | **3** | per slate |
| First room won | **2** | once ever |

A verified account starts at 125 XP and a full pick'em week pays about 250, so a
strong first season collects Redshirt through Captain — 14 credits — on top of the
top-off. That is deliberately not enough to live on: the top-off is the floor, the
rungs are the reward for climbing.

**Oversupply is self-correcting, which is why these numbers are safe.** Rung-ups can
push a balance above 6, and at 6 the top-off stops paying entirely. The cooler
ceiling absorbs a generous rung without inflating the economy, so there is no second
mechanism to tune.

**Activated on first Picks visit**, not at signup — the economy starts when the
reader actually meets it.

**Granted lazily, never swept.** A scheduled weekly job would write a row for every
activated user whether they showed up or not. Grant on the Picks visit instead,
idempotent through a week-stamped key — the `GrantWalletEntry::daily()` trick one
period up, where "the key is the cap itself". Two details that will bite otherwise:

- The grant AMOUNT is computed from the balance at grant time, so **check the week
  key before reading the balance**, or a double-fire computes twice and writes once
  with the wrong number.
- `wallet_entries.key` is `string(40)`. `topoff:2026-w05` fits with room; keep any
  new reason/key shape inside that budget.

Like `daily()`, it refuses unverified accounts — every capped earn is a
participation reward, and participation is what verification gates.

### 8. Drinking vocabulary is permitted for the rest of this season

Deliberately waived by the human, reversing the standing rule at `Voice.php:356`
("The currency stays out of drinking vocabulary on purpose") and the currency
README.

**Already recorded** — the amendment landed in `.ai/rules/actions.md` with this
plan, through `record-rule` rather than a hand-edit, so the rule is in place before
any code relies on it and no session can read the old one and quietly revert the
name. Nothing to do in the PRs below.

### Voice: varied slang, one constant label per surface

Spending phrases are consumption slang and vary freely — "crush a Tallboy", "ice one
down", "crack one open" — across all three `ContentRating` registers, per the
non-negotiable that all three registers get written when the screen is written.

**But the codebase's hard convention still applies.** `ContestMode::label()`/
`blurb()` and `LobbyFlavor::label()` are "product vocabulary, constant across
registers… the game is never described two ways." So each sink gets **one canonical
verb on buttons and rules text** — *Crush* and *Ice down* — with the slang living in
the Voice lines *around* them. A button reading "Crush" beside rules text saying "ice
down" reads as two different features.

## Reuse findings — read these before writing anything

These cut the build roughly in half. Every one was verified against the code.

- **`Pick.locked` is already the wager column.** A boolean on picks, cast and
  fillable (`app/Models/Pick.php`), written through `App\Actions\LockPick` and frozen
  by the same kickoff clock as the pick itself. Since a slate can only ever offer
  *one* wager — the Lock in Woodshed, the Tallboy everywhere else, never both, which
  is decision 6 restated — **one column serves both mechanics and no migration is
  needed.** Rewrite the docblock at `app/Models/Pick.php:22-29`, which currently
  frames the column as Woodshed-only, or the next reader will think it is a bug.
- **`ModeEngine::pointsForPick()` is the scoring seam** — documented as "the one seam
  live grading (PickGrader) and settlement share, so the money math can never fork."
  Tallboy pricing goes there and nowhere else, exactly the way `WoodshedMode`
  overrides it for the Lock (`WoodshedMode.php:75-81`).
- **`LockPick` is the action shape to copy** — verified email → claimed handle →
  membership → published slate → temporal kickoff lock, then the wager's own rules.
  The Tallboy differs on three points: any game is eligible (not only the featured
  one), the engine gate is a new `supportsTallboy()` rather than `supportsLock()`,
  and it **spends a credit**.
- **`LobbyShelf` is the entry-price seam.** `LobbyFlavor::shelf()` already sorts
  rooms into House / QuickHits / Spotlight / Conference, so "Spotlight costs a
  Tallboy" reads off data that already exists.
- **`GrantWalletEntry` is the only wallet doorway.** Spends are negative rows with no
  key (repeatable); a wager pulled before kickoff refunds as a **new positive row**,
  never an update — "corrections are new rows, the way a bank does it". Needs a
  balance guard: no spend may take a wallet negative.
- **`x-wallet-chips` is the single naming seam** — "the only place in the app that
  knows its name or its art" — which is what makes the rename contained.
- **`x-mode-rules` already explains a mode**, reading `ContestMode::ruleLines()`.
  PR 7 reuses it rather than building a second explainer.
- **`resources/views/livewire/tour.blade.php` already runs a guided walk**, and its
  docblock draws the line PR 8 needs: "Home decides WHETHER this renders… this
  component only runs the walk and stamps the finish."

## PR breakdown — stacked, merge bottom-up

### PR 1 — `tallboy-rename`

Mechanical, no behavior change. `lattes` → `credits` in the schema and every
reference; "Beast Latte" → "Tallboy" in every piece of copy.

62 occurrences across 14 source files and 11 test files. The source files:
`GrantWalletEntry`, `User::walletTotals()` (the returned array key changes too),
`WalletEntry`, `GrantVerificationReward`, `WalletEntryFactory`, the create migration,
four Filament surfaces (`WalletEntryResource`, `WalletEntriesRelationManager`,
`UserResource`, `UserStats`, `EngagementStats`), `wallet-chips`, `pickem-home`'s
you-strip, and `onboarding`. Also: Voice keys, asset filenames, the currency README,
and the `docs/` mentions in `product.md`, `roadmap.md`, `screens.md` and
`game-modes.md`.

Tests move as renames, not rewrites. If a test's *meaning* changes here, something
has gone wrong.

### PR 2 — `tallboy-icon`

*Also tracked on the board as **CFB-39**, which carries the same brief. Build it
here, in sequence — do not wait for that card to be worked separately.*

A new pixel-perfect tallboy mark replacing `beast-latte-*` and the retained
first-pass `latte-*` in `public/brand/currency/svg/`.

**Design it at 18px first, then scale up** — that is the only size it actually
renders at, beside the balance in `x-wallet-chips`. A mark that reads at 64px and
turns to mud at 18px has failed. There is no reference image to wait for; the cue
list below is the brief, and if one is supplied later it is visual reference only,
never trade dress to copy.

The current README is a good brief that the art under-delivers on: the four cues that
read as a tallboy (top rim wider than the neck, long shoulder taper, base rim line, a
cylinder reflection interrupted by the label band), light and dark cuts swapped the
way `x-team-logo` swaps its marks, sizes 16–256, and the simplified `*-16` cut
because "below 24px the reflection, base rim and range all drop". Now that the name
says tallboy, **the silhouette can carry more of the work and the label band can
shrink** — it existed to sell "latte" and it is what eats the shape at chip size.
Update the README alongside the art.

### PR 3 — `tallboy-earn` — supply first

Rung-up grants, streak/milestone grants, the graduated weekly top-off, and the
first-Picks-visit activation column. **This ships before any sink**, so there is a
balance to spend before anything asks for one.

Add a `weekly()` sibling to `GrantWalletEntry::daily()`, same key-is-the-cap shape,
same unverified refusal, same football-day timezone. **The rung-up and milestone
amounts are decided in decision 7 — build those numbers, do not stop to ask for
them.**

### PR 4 — `tallboy-entry`

Spotlight shelf costs 1 credit. Balance guard in `JoinGroup` (it already throws
`PickemParticipationGated` and `ContestFull`; this is a third refusal). Lobby copy
saying the price on the card, in three registers.

### PR 5 — `tallboy-crush`

The wager: `supportsTallboy()` on `ModeEngine` evaluated against the frozen slate
size, `pointsForPick()` pricing, the action modeled on `LockPick`, the pick surface,
and refund-on-pull as a new positive ledger row.

### PR 6 — `tallboy-onboarding`

The splash and tour copy currently promise a currency with no named sink. Both need
copy naming where Tallboys are spent, in all three registers. This is the card's
explicit requirement, not an extra.

### PR 7 — `picks-explainer`

A "How this works" surface in the Picks area. The economy adds a second thing to
understand on top of three contest modes that are only explained in the Lobby today.

Reuse, do not rebuild: `ContestMode::ruleLines($games)` is the ONE source for rules —
"the lobby's explainer, the mode doors, the join landing and the docs all read" it,
so the mode is never described two ways. `x-mode-rules` is the existing expandable
per-mode card. `LobbyFlavor::shelf()`, `label()` and `blurb($games)` carry everything
the room grid needs; the credit columns are the only new facts.

It must also explain **the cooler** — the graduated top-off is the one rule a reader
needs to plan a week, and "the cooler holds six" is the sentence that does it. Three
tiers, plainly, near the balance.

> **Trap: the eligibility table must not be a table on mobile.**
> `ChromeConsistencyTest` fails any view containing `overflow-x-auto` outside a
> three-file allowlist (week scroller, section nav, Home's swiper), and the
> non-negotiables put the design at 390px first. Render **one stacked card per room**
> — name, shelf, and two state chips (entry: free / 1 credit; wager: yes / no, with
> the reason when no) — widening to a grid above `sm`. A `<table>` that scrolls
> sideways is the obvious first draft and it will fail the suite.

### PR 8 — `picks-tour`

A second guided walk on first Picks visit. A new step list and a new gate, **not a
new component** — `tour.blade.php` already runs the spotlight walk.

Mirror the existing gate: a Pennant flag beside `guided-tour`, a `UxSignal` case
beside `TourDismissed`, and a replay entry in Account beside "Replay the tour".

**Two columns, not one, and the distinction matters.** The **first-visit** stamp is
the shared fact — it fires this tour once and switches on PR 3's top-off. **Completion**
is this tour's own, because dismissing a tour and having arrived at Picks are
different things, and a replay must never re-trigger the grant.

Targets are `[data-tour]` keys, and the existing "each step spotlights whichever
element wearing its key is actually visible" pattern means one step list serves both
widths for free — the wallet chip step works from the bottom nav below `sm` and the
header above it.

Sequencing: PR 7 stands alone and is worth shipping regardless. PR 8 should follow
it, because a tour pointing at a screen that does not yet explain itself is just a
second thing to dismiss.

## Verification (per PR, in order)

Every PR follows CLAUDE.md's order, and none of it is optional:

1. `php artisan test --compact --filter=…` on what changed, then the suite when the
   change is broad. **A new behavior needs a new or updated test.**
2. `vendor/bin/pint --dirty --format agent` if any PHP changed. Never `--test`.
3. `npm run build` if any Blade changed, or new Tailwind utilities are missing at
   runtime and it looks like a design bug.
4. `/__device?path=/picks&w=390,768&h=800` for anything visual — the harness, not a
   resized window. PRs 2, 4, 6, 7, 8 all need this.
5. **Verify the END state, not the animation** (PR 8 especially): the automated tab
   produces no rendering frames, `requestAnimationFrame` never fires and
   `IntersectionObserver` delivers no entries. Drive the reactive end state and
   assert what it toggles.
6. **Break the fix back** wherever a wrong default was the bug — that class of test
   passes for the wrong reason more often than not.

Per-PR specifics worth calling out:

- **PR 1** — the whole suite, because the rename touches 11 test files. Green before
  and after with the same assertion count is the bar.
- **PR 3** — test the top-off tiers at their boundaries (0, 2, 3, 5, 6), the week-key
  idempotency under a double fire, the unverified refusal, and that the amount is
  computed *after* the key check.
- **PR 5** — test the ±5 both ways, the per-slate eligibility against a deliberately
  thin dynamic slate (the trap above), the refund row on pull, and that a spend
  cannot take a balance negative.
- **PR 7** — `ChromeConsistencyTest` must stay green; that is the point.

## Risks / rollback

- **The economy is wrong on contact with real users.** Most likely failure: the
  top-off tiers are mis-tuned and everyone is either broke or rich by week four. All
  the numbers are constants in `GrantWalletEntry`, so a rebalance is a deploy, not a
  migration — the same property `RankLadder` was built for.
- **PR 1 is the risky one**, not the feature PRs: a column rename touching a ledger
  that every balance reads. It is also the most mechanical, and it is independently
  revertable because nothing else in the stack depends on the *name*.
- **The wager is the piece most likely to need pulling.** It is gated behind
  `supportsTallboy()`, so disabling it everywhere is one method returning `false`.
- **Private-league-only users hold an unusable currency** (decision 3, accepted).
  Watch whether they read the Lobby as "the place my credits work" or as "a thing
  the app keeps nagging me about". PR 7 is the mitigation; if it does not land, the
  next move is earning credits in private groups while still only spending them in
  public rooms — which this design already permits without any change.
