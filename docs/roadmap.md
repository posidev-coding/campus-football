# Campus Football — concept and plan

**Working document.** This is the one file that is expected to change as the
product moves. Everything else in `docs/` describes what is already true; this
describes what we are trying to build and how far along it is.

Last reviewed: 2026-08-20.

---

## The concept

A college football pick'em app that people actually want to open on a Tuesday.

Two halves, and the split runs through the whole codebase:

**A trustworthy data layer.** Scores, standings, rankings, box scores, rosters,
recruiting, news — accurate, fast, and current. Three previous versions went
wrong on data, so this half is not a feature, it is the foundation the rest is
allowed to stand on. It is deliberately unglamorous and deliberately
opinionated: never write a default when a feed returns nothing, never store a
team's conference as a scalar, never trust an array position.

**A group chat with a scoreboard attached.** Pick'em, groups, streaks, taunts.
This half is where the product's personality lives, and it is the reason
`ContentRating` exists — an app that reads like a spreadsheet has already lost
to the group chat it is competing with.

The line between the two halves is enforced, not stylistic: factual screens
report facts, personal screens talk to you. See
[product.md](product.md#the-voice-contentrating-drives-copy-and-it-is-not-decoration).

### Why v4 exists

Fourth rebuild. The v3 codebase is preserved on the `production` branch; this
is an orphan `v4` branch with no shared history. v3's failures were structural
rather than cosmetic — season-scoped conference membership stored as a scalar,
defaults written over real standings, ~20 ESPN requests per second — and each
one is now a named rule in `CLAUDE.md` rather than a thing to remember.

---

## Where we are

Phases 1–4 are shipped. Phase 5's engine landed 2026-08-13/14 (schema,
mode engines, slates, picking, live grading, two-phase settlement — all
behind the admin-only `pickem` flag), and its front end was then REBUILT
wholesale (plan iteration 3, 2026-08-14) after the first cut ignored the
design system: the weekly thing is THE SLATE ("board" is purged and a
Voice sweep test enforces it), picking is a tap on a real matchup card
that fills with the team's color, a group plays ONE mode all season
(pivot = deliberate act, once per season, group notified), the Picks tab
lands on `/picks` with sections My Picks | Lobby | Leaderboard | History,
and public contests are transient weekly rooms that spawn on fill. The lobby's PASS 3
(2026-08-14) then rebuilt the landing as one urgency-ordered zoned scroll,
added `/join/{CODE}` invite links as the primary acquisition path (codes
demoted to the spoken-word fallback), gave every mode an identity (icon +
palette) — and shipped THE WOODSHED LIVE: the founders' rules were
recovered (email + the 2016 code) and implemented, Classic was renamed
Shotgun, and points rebalanced so every mode's perfect week is ~100
(Woodshed 101). See [game-modes.md](game-modes.md). Phase 5 CLOSED
2026-08-20 with its last three slices: The Conversation at three scopes,
the gamification finish (the RankLadder plus the two capped daily earns),
and flip prep — `PICKEM_OPEN` as the launch switch and `pickem:preflight`
as the readiness gate. PASS 4 (2026-08-20) then SPLIT the landing in two:
My Picks at `/picks` is the reader's own week, the Lobby at `/lobby` is a
contest browser of shelved uniform rows — pass 3's single scroll was shaped
for three rooms and the flavored build shipped thirteen. The same pass made
the pick'em vocabulary law in the code (see [product.md](product.md)). The
flag is still admin-only: flipping it is a decision, not a slice — the flip
is Tue Sep 1 with the first public Saturday Sep 5, and Aug 29 rehearsed
behind the closed flag.
Phase 6's notification loop landed 2026-08-22, ahead of it.

### Phase 1 — Data foundation ✅

ESPN integration across four hosts, the full schema, and the sync commands.

- `EspnClient` with rate limiting, retry policy and the User-Agent allowlist
- `cfb:migrate` / `cfb:sync` / `cfb:games` / `cfb:players` / `cfb:summaries` /
  `cfb:coaches` / `cfb:aggregate`
- Season-scoped conference membership through `team_seasons`
- `CfbCalendar` as the single source of truth for where we are in the year
- Sync cost tiers: live scoring is one request per minute, total
- Six seasons seeded (2021–2026): ~5,800 games, ~34,900 athletes, ~305,000
  box-score lines, 27,178 recruits

Reference: [espn-data.md](espn-data.md), [data-model.md](data-model.md)

### Phase 2 — The public product ✅

Everything a signed-out visitor can read.

- Scores (week scroller, bowls/CFP split, scope filter)
- Game screen — one shell in three states (Preview / Live / Recap)
- League: Standings · Rankings · Teams · Players · Stats · Recruiting
- Team, player, coach and conference pages
- News index, article reader, search (three surfaces, one backend)
- Mobile-first chrome: 390px design target, two-level navigation, the shared
  chrome component vocabulary

Reference: [screens.md](screens.md), [ui-system.md](ui-system.md)

### Phase 3 — Identity and personalization ✅

The app becomes yours.

- Registration with first/last name and content rating (the handle is
  claimed later on Account — nothing consumes one yet)
- Follows as an ordered list, capped at 5 — position 1 is what "favorite" used
  to mean
- Home as swipeable at-a-glance cards, one per followed team
- In-place onboarding (three counted screens, credentials last; the team
  picker is an uncounted post-signup moment that collects ONE favorite and
  seeds 25 XP)
- Account: teams, appearance, content rating, notification preferences
- `Brand` — one resolver, shipped defaults, admin-editable overrides
- `Voice` — the three-register copy resolver, with Account as the reference
  implementation

Reference: [product.md](product.md)

### Phase 4 — Delivery and hardening ✅ (August 2026)

- Mail on Cloudflare Email Service, queued, branded, budgeted, with RFC 8058
  one-click unsubscribe
- SMS on Vonage, with consent as three separate claims and a STOP webhook
- Uploads on R2 (`config('cfb.upload_disk')`)
- Ops layer: `feed_runs` ledger, `CoverageReport`, `cfb:doctor`, the Filament
  Sync Health page
- Schema audit — `game_drives` split out, dead indexes dropped, the
  leaderboard index added
- 54 test files, including the sweeps that hold the chrome and Alpine rules

Reference: [operations.md](operations.md)

---

## Phase 5 — Pick'em, Groups & Gamification ✅ (August 2026)

The product's point. Planned 2026-08-13 (plan iteration 1, approved); the old
Phases 5–7 collapsed into this one phase, because two of its decisions made
the split artificial: commissioner-built slates make groups load-bearing from
day one, and gamification is a consistent second fiddle from the first
screen, not a later coat of paint. The goal is weekly-cadence retention —
reward often, never superficially, and no candy-crush mechanics: no
daily-login rewards, no timers, no FOMO.

**Settled decisions:**

- **Three contest modes, ALL against the spread** — never straight-up.
  Full rules and heritage: [game-modes.md](game-modes.md). Points
  rebalanced 2026-08-14 so every mode's perfect week is ~100:
  - **Shotgun** (renamed from Classic 2026-08-14; stored value stays
    `classic`, the Triple Option precedent) — a 10-game slate, every game
    worth 10.
  - **Triple Option** — the core mode: 15 games in 3 tiers of progressive
    game quality paying **9/7/4** (settled 2026-08-14, from 3/2/1). Stored
    as `tiered` so the product name lives in `Voice` and labels, never in
    data. Proposed tier names from the play itself (still open as screen
    vocabulary): **The Pitch**, **The Keep**, **The Dive**.
  - **Woodshed** — the founders' game, RECOVERED AND IMPLEMENTED
    2026-08-14 (the rules email surfaced — kept verbatim at
    [woodshed.md](woodshed.md) — and a working copy of the 2016 code
    confirmed the mechanics before being discarded, so the code's half now
    lives only in [game-modes.md](game-modes.md)): 15 games at 8/6/4,
    the LOCK (+6/−4, optional, featured game only — the one path to
    negative points, hence the signed points columns), and the BEAR (house
    contestant, themed picks public while you pick, +5 for strictly
    beating his weekly total). Perfect week 101 — the founders' premium.
    Deliberately NOT ported: money, divisions (the OG league itself
    dropped them), the playoff structure, the single Saturday deadline.
- **THE HALF-POINT LAW (added 2026-08-13, a founders' rule): no contest
  line ever sits on a whole number**, so no pick can ever push — every
  call wins or loses. The commissioner OWNS the line: it seeds from the
  book when a game joins the slate (whole numbers shade to a half point),
  is adjustable up to 3.0 either way while drafting, and publishing
  COMMITS it — one printed slate, office-pool style, and grading never
  moves off it whatever the market does after. `market_spread` keeps the
  book's number beside the commissioner's for the audit. A game without a
  posted line can never publish.
- **Slates are commissioner-built per group.** The Game Quality Score
  (`matchup_quality` + line movement + ranks — inputs already synced) is
  the commissioner's *suggestion engine*, never the author. Public lobbies
  are house-run groups where the app is the commissioner, auto-published
  through the identical suggest-and-publish path.
- **First ship: Classic + Triple Option + groups together**, behind a
  `pickem` Pennant flag, before the flag flips.

**Design pillars** (proposals holding until plan iteration 2 revises them):

- **The weekly clock (settled 2026-08-13):** slates carry only games in the
  SATURDAY WINDOW — noon Eastern to midnight, no breakfast kickoffs. The
  commissioner has until the SLATE DEADLINE (the default lives on
  `Cadence::DEADLINE_DOW` / `DEADLINE_TIME` — Thursday noon ET since
  2026-08-20; admin-configurable on the Pick'em Settings panel page) to publish; past
  it, `pickem:publish-slates` publishes the STANDARD slate — best quality
  games, auto-designated combined-points tiebreaker — so a group is never
  hung out to dry by a commissioner who lost track of Tuesday. Results go
  final in TWO PHASES: preliminary when the last game finals, OFFICIAL at
  Sunday noon ET (also configurable) — the stat-settling window that lets
  ESPN's occasional day-after corrections land before a stat-based
  tiebreaker pays the wrong person. Payouts wait for official. (Two-phase
  settlement lands with the scoring slice.)
- Per-game lock at kickoff; picks private until lock; a missed pick is an
  ABSENT ROW worth zero — never auto-picked. The no-defaults rule as schema.
  Live scoring runs from the second a game kicks: every score change
  recomputes standings through the same event-driven grading, so Saturday
  reads live without a single extra ESPN request.
- The weekly tiebreaker is a QUESTION the commissioner sets, rotating like
  the paper league's did: a metric (combined points, one team's points,
  passing/rushing yards) plus its game and — when one-sided — its team.
  Entrants answer with one number; settlement resolves the actual from data
  the app already syncs, falling back to a shared win when a stat has not
  landed rather than inventing a number.
- Grading is event-driven off `GameWentFinal` and adds ZERO ESPN requests; a
  daily DB-only settle-sweep catches games that go final without firing.
  Settlement payouts are KEYED wallet entries, so a double-fired settlement
  pays nobody twice.
- Handle claim happens at the first pick or first post — the seam
  `product.md` reserved. The verified gate lives inside the mutating
  Actions, never route walls: unverified users see everything.
- **The Conversation** — one polymorphic discussion surface at exactly three
  scopes: Game (below the facts; the facts stay PURE), Team, and Group. No
  league firehose, no per-week threads, no DMs. A slate's chatter belongs
  to the group.
- XP rides `wallet_entries` through `GrantWalletEntry` with date- and
  slate-stamped idempotency keys — the unique index IS the anti-farming
  cap, no throttle code. "Film Room" XP rewards power users reading
  previews and box scores, capped daily. The rank ladder replaces the
  chips' "Rookie" literal as a pure computation over `walletTotals()` —
  no table, rebalancing is a deploy.
- Streaks are deliberately deferred to iteration 2: they are the part with
  real retention value and the part most likely to feel cheap if done badly
  — and a streak your group can see is a stake, so they need groups live
  first.

**Slices** (each lands green and invisible without the flag): schema and
factories → engine core (modes, grader, quality score, suggestions) →
groups → slate build and publish → picking → scoring and settlement →
~~conversations~~ → ~~gamification finish~~ → ~~flip prep~~. All nine have
landed; what remains before launch is a decision, not a slice.

**The Conversation shipped 2026-08-20** — one Livewire component at the
three sanctioned scopes, mounted at the FOOT of Game and Team rather than
as a tab on either (the Group foot embed was removed 2026-08-29: the
clubhouse is the pick surface, and a thread under it read as distraction —
the group scope stays whitelisted server-side, and a dedicated Group Talk
screen was approved 2026-08-30, see docs/plans/home-and-picks-pass.md).
That placement is a constraint, not a preference: `x-plate` throws past
three tabs, and the
team nav is a measured 358px row with 54px spare that deliberately does not
scroll. The talk belongs to the screen rather than to whichever tab you are
on, so it follows you across all of them. Reading is open to everyone
including guests; `PostToConversation` holds every write gate (verified,
handle, membership for groups, the three-scope whitelist, a spelled-out
60-second limiter) and `DeleteConversationPost` is the only moderation verb
there is — the table has no `updated_at`, so a regretted line is deleted
whole and never quietly rewritten. The handle claim now lives in
`ClaimsHandle`, shared with the pick surface, because it is one claim
raised at whichever comes first: the first pick or the first post.

**The gamification finish shipped 2026-08-20.** `App\Support\RankLadder`
replaces the chips' "Rookie" literal — Walk-On · Redshirt · Rotation ·
Starter · Captain · All-American · Legend, thresholds roughly doubling, a
pure function of `walletTotals()['xp']` with no table and no stored column,
so rebalancing is a deploy. The chip has room for the rung alone; My Picks
carries the only surface that names the NEXT one and the XP left to it, and
at the top of the ladder `next`/`remaining` are NULL and the line is SKIPPED
rather than rendered as a finished bar. Rung names deliberately stay out of
Voice: a rank is a label you compare with somebody else's, so it says the
same word in every register.

Two capped daily earns landed with it, and the cap in both is the KEY, never
throttle code: `GrantWalletEntry::daily()` stamps the FOOTBALL day (Eastern —
01:00 UTC Sunday is still Saturday night) into the key, so the `(user_id,
key)` unique index is the anti-farming cap itself and a race under-pays by
one rather than paying twice. **Talking** pays 5 XP three times a day —
deliberately a smaller number than the conversation limiter allows in one
minute, because a limiter stops a flood and a cap stops farming. **The Film
Room** pays 5 XP for up to five DIFFERENT games a day, slotted by game id, so
re-reading the same box score earns once ever; it fires from the game
screen's `mount()` and tab hook and never from `render()`, which re-runs
every thirty seconds on a live game. Only Preview and Box count — a score is
not film. Guests and unverified accounts earn nothing and are shown nothing:
reading is never gated.

**Flip prep shipped the same day.** The `pickem` flag now reads
`config('cfb.pickem_open')` / `PICKEM_OPEN`, so launch is an environment
change with an instant rollback rather than a deploy. `pickem:preflight`
reports what has to be true underneath it and exits non-zero while anything
blocks. The landmine it exists for: Pennant's database driver PERSISTS
resolved values, so flipping the config reaches nobody who has already loaded
a page until `pennant:purge pickem` clears their rows — full procedure in
[operations.md](operations.md).

**Still deliberately unbuilt:** the perfect-week bonus and streaks, which
remain iteration-2 items — streaks are the part with real retention value and
the part most likely to feel cheap if done badly. `contests.settings`
overrides for the founders' Woodshed numbers are likewise still a landing pad.

**Open for plan iteration 2:** the proposed Pitch/Keep/Dive tier names (as
screen vocabulary); perfect-week bonus and streak design; member caps and
multiple group membership (default: yes); `contests.settings` overrides for
the founders' Woodshed numbers. ~~Lobby shape~~ — settled 2026-08-20: a
thirteen-room flavored inventory, sold from a dedicated browser at `/lobby`
on four named shelves, with the reader's own week split off to My Picks at
`/picks` (see [screens.md](screens.md)). ~~Tier values and sizes~~ — settled
2026-08-14 at 9/7/4 and 5/5/5 (the ~100 parity principle, see
[game-modes.md](game-modes.md)). ~~Woodshed teaser vs hidden~~ — mooted:
the rules arrived and the mode shipped live. ~~Push handling~~ — dissolved
by the half-point law: pushes are structurally impossible.

**The rules this phase must not break:**

- Scoring recompute is driven by events, never by polling the scoreboard —
  and the whole contest engine adds zero ESPN requests.
- Pick'em, Groups and Conversations are LOUD surfaces — every string gets
  all three `ContentRating` variants written when the screen is written.
  The conversation *chrome* on Game and Team screens is voiced; the facts
  above it stay factual.
- Roast the pick, the team, the record — never the person.

## Phase 6 — Notifications and the weekly loop ✅ (August 2026)

The plumbing was already there — web push end to end (VAPID,
`push_subscriptions`, the service worker's `push`/`notificationclick`
handlers), `WeeklyDigest`, the SMS channel with its single consent gate,
`cfb:kickoff-alerts`. What was missing was the loop that makes them matter.
Shipped 2026-08-22, ahead of the Sep 5 flip:

- **Pick reminders** (`pickem:remind`, two waves: a day out, then ninety
  minutes). Anchored on the next OPEN kickoff — not the commissioner's
  deadline, which is when an unpublished slate forfeits, and not the first
  kickoff either, which stops being anybody's deadline once the noon games
  start while the late card is still pickable.
- **Results** when a slate settles, dispatched off `SettleSlate`'s claim —
  the only once-ever signal in that path.
- **The rival**, folded into the results as a LINE rather than a fourth
  send: the person one place above you, or one below when you won. A weekly
  pick'em rivalry is week to week, and the settled field already knows who
  that is. No table, no declaration. The Bear gets the same treatment.
- **The inbox** at `/notifications`, a section of Account. The `database`
  channel costs no budget and no consent, and with zero push subscriptions
  at launch it is the only channel that reaches everybody.

**The two rules this phase established:**

1. **The audience roots in MEMBERSHIPS, never in entries.** A
   `slate_entries` row is created lazily on a member's first pick, so
   somebody who has picked nothing has no entry and no picks — and is
   exactly who a reminder is for. A sweep built the obvious way reminds only
   the people who already played, silently, while looking correct.
2. **Two claims, never one.** `settled_at` claims the money;
   `results_announced_at` claims the noise. Keeping them apart is what makes
   a botched announcement repairable (`pickem:announce --slate=`) without the
   wallet hearing about it, and what stops a queue retry mailing the room
   twice.

The weekly digest moved off Sunday to Tuesday in the same pass: it and the
results announcement are both bulk mail spending one daily budget, and
sharing a day released the second one's tail into Monday.

Reverb is installed and only the default private user channel is registered.
Live pick'em standings during a Saturday remain the case that would justify
it — and that case gets stronger once real people are playing.

**Still open:** SMS reminders are wired, throttled, tested and OFF
(`PICKEM_REMINDER_SMS`); nothing prunes read notifications for a surviving
user; there is no cross-feature per-user rate limit, so a member of three
contests whose followed team also kicks can hear from the app several times
on one Saturday.

## Phase 7 — Native mobile

Speculative, and listed so the constraint is not forgotten: the App Store age
rating is why "roast the pick, never the person" is a hard rule rather than a
taste preference. Nothing should be built in Phases 5–6 that would need
unwinding for a native shell.

The same constraint owns the currency contingency. Tallboy is a fictional
can that copies no trade dress (see `public/brand/currency/README.md`). The
copy around it DOES talk like a beer now — the no-drinking-vocabulary rule was
waived deliberately for the 2026 season (.ai/rules/actions.md), which raises
this contingency rather than removing it. If App Store review reads the art or
the copy as alcohol, the fallback is a per-user variant
(sodas, stadium food) behind the one-component seam: `x-wallet-chips` is the
only file that knows the currency's name or art, so the swap never touches a
screen.

---

## Standing constraints on every phase

These do not change between phases and are the ones most likely to be
forgotten under delivery pressure:

1. **Mobile-first, always.** Design at 390px, widen additively. Nothing may be
   reachable only at a breakpoint above base.
2. **Voice is a requirement, not a coat of paint.** Write all three registers
   when you write the screen.
3. **The factual screens stay factual.** Scores and League never joke.
4. **Sync cost tiers are not negotiable.** Live scoring is one request per
   minute regardless of how many games or viewers there are.
5. **Every change is programmatically tested.**
