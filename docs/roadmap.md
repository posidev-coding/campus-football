# Campus Football — concept and plan

**Working document.** This is the one file that is expected to change as the
product moves. Everything else in `docs/` describes what is already true; this
describes what we are trying to build and how far along it is.

Last reviewed: 2026-08-13.

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
lands on `/lobby` with sections Lobby | Leaderboard | History, and public
contests are transient weekly rooms that spawn on fill. The lobby's PASS 3
(2026-08-14) then rebuilt the landing as one urgency-ordered zoned scroll,
added `/join/{CODE}` invite links as the primary acquisition path (codes
demoted to the spoken-word fallback), gave every mode an identity (icon +
palette) — and shipped THE WOODSHED LIVE: the founders' rules were
recovered (email + the 2016 code) and implemented, Classic was renamed
Shotgun, and points rebalanced so every mode's perfect week is ~100
(Woodshed 101). See [game-modes.md](game-modes.md). Still open in Phase 5:
conversations, the gamification finish (rank ladder), notifications, and
flip prep.

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

## Phase 5 — Pick'em, Groups & Gamification ← **next**

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
  - **The Woodshed** — the founders' game, RECOVERED AND IMPLEMENTED
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
  book when a game joins the board (whole numbers shade to a half point),
  is adjustable up to 3.0 either way while drafting, and publishing
  COMMITS it — one printed board, office-pool style, and grading never
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

- **The weekly clock (settled 2026-08-13):** boards slate only games in the
  SATURDAY WINDOW — noon Eastern to midnight, no breakfast kickoffs. The
  commissioner has until the SLATE DEADLINE (default Tuesday end-of-day ET;
  admin-configurable on the Pick'em Settings panel page) to publish; past
  it, `pickem:publish-boards` publishes the STANDARD slate — best quality
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
~~conversations~~ → gamification finish → flip prep. The first six are the
flippable minimum; conversations and gamification may trail the flip if the
season arrives first.

**The Conversation shipped 2026-08-20** — one Livewire component at the
three sanctioned scopes, mounted at the FOOT of Game, Team and Group rather
than as a tab on any of them. That placement is a constraint, not a
preference: `x-plate` holds three tabs and Group already has three, and the
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

**Open for plan iteration 2:** the proposed Pitch/Keep/Dive tier names (as
screen vocabulary); XP numbers, ladder names, perfect-week bonus and streak
design; lobby shape (one house lobby vs per-conference); member caps and
multiple group membership (default: yes); `contests.settings` overrides for
the founders' Woodshed numbers. ~~Tier values and sizes~~ — settled
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

## Phase 6 — Notifications and the weekly loop

Partly built already — `WeeklyDigest`, `SendWeeklyNewsletter`, the SMS
channel, and now WEB PUSH end to end: VAPID + `push_subscriptions`
(subscription = consent, device-scoped, no server flag), the service worker's
`push`/`notificationclick` handlers (a tapped push opens the INSTALLED app —
the only true deep link an iOS PWA has), the Account device switch and Home's
standalone-only nudge, a welcome push proving the pipe, and `cfb:kickoff-alerts`
sweeping the live window for followed teams. What is missing is the loop that
makes them matter: pick reminders before lock, results when a slate settles,
and a rival's result when it stings — all of which ride the same plumbing
with nothing to unwind.

Reverb is installed and only the default private user channel is registered.
Live pick'em standings during a Saturday are the case that would justify it.

## Phase 7 — Native mobile

Speculative, and listed so the constraint is not forgotten: the App Store age
rating is why "roast the pick, never the person" is a hard rule rather than a
taste preference. Nothing should be built in Phases 5–6 that would need
unwinding for a native shell.

The same constraint owns the currency contingency. Beast Latte is a fictional
can that copies no trade dress (see `public/brand/currency/README.md`) and
the copy around it never uses drinking vocabulary — but if App Store review
reads the art as alcohol imagery anyway, the fallback is a per-user variant
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
