# Campus Football — concept and plan

**Working document.** This is the one file that is expected to change as the
product moves. Everything else in `docs/` describes what is already true; this
describes what we are trying to build and how far along it is.

Last reviewed: 2026-08-11.

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

Phases 1–4 are shipped. Phase 5 is the next body of work and has not started.

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

## Phase 5 — Pick'em core ← **next**

The product's point. Nothing here is built yet; the seams are.

**What already exists to build on:**

- `GameScoreChanged` and `GameWentFinal` (dispatched from `SyncGames::store()`,
  after save, never on a first insert) — the subscription points a contest
  recompute listens to rather than polling. No listeners exist yet.
- The **Picks area is already live** — fifth tab (center slot, label
  "Picks"), `/picks` coming-soon screen, a guided-tour stop, and the teaser
  card on Home now links there. When Pick'em ships, the screen's promise
  cards become the real slate/groups/records surfaces and the area gains
  sections.
- `Game::slateEligible()` and the scope filters, for choosing a week's slate.
- `GameRanks` and `game_odds`, for tiering and for spread-based formats.
- Laravel Pennant is installed with one flag (`guided-tour`) — the intended
  mechanism for rolling this out behind a flag.
- The **verified-email gate is decided and documented**: Pick'em actions and
  XP earning require `hasVerifiedEmail()` (the `verified` middleware is
  reserved for exactly this; `/picks` already explains the gate to
  unverified users). Verification itself pays 100 XP + 1 Beast Latte.
- The **wallet ledger exists** (`wallet_entries` + `GrantWalletEntry`):
  Pick'em payouts are keyless repeatable entries into the same table the
  chips already read.

**Open decisions** (none of these are settled; this is the list to work
through first):

- Format: straight-up picks, against the spread, confidence points, or
  survivor. Probably more than one eventually, which argues for a contest
  *type* rather than a hardcoded scoring rule.
- Scope of a slate: a week's full FBS card is 60+ games. Top 25 only? A curated
  slate? User-chosen?
- Lock time: per game at kickoff, or the whole slate at the first kickoff.
- Late joins, missed picks, and tie-breaks.
- Whether picks are private until lock (they should be).

**The rules this phase must not break:**

- Scoring recompute is driven by events, never by polling the scoreboard.
- Pick'em is a LOUD surface — every string gets all three `ContentRating`
  variants written when the screen is written, not later.
- Roast the pick, the team, the record — never the person.

## Phase 6 — Groups

Pick'em against strangers is a leaderboard; pick'em against your group chat is
the product. Invites, a group leaderboard, group-scoped taunts, a commissioner.

Depends on Phase 5 having a settled contest model. LOUD surface.

## Phase 7 — Gamification

The shelf is half-live: `x-wallet-chips` reads REAL Beast Latte and XP sums
from `wallet_entries` (`User::walletTotals()`, one memoized query) — the
verification reward and the onboarding seed already pay into it — while the
rank is still the literal starting "Rookie", because the ladder is this
phase's to define. The component sits in `x-home-nav`'s reserved slot below
`sm` and in the layout header above, with a guided-tour stop of its own, and
is deliberately the ONLY file that knows the currency's name or art. This
phase defines the rank ladder, the earn/spend table, and replaces the Rookie
literal with a computed rank. Streaks join the chips here; they are the part with real
retention value and the part most likely to feel cheap if done badly.

Deliberately after groups: a streak counter nobody can see is a number, and a
streak your group can see is a stake.

## Phase 8 — Notifications and the weekly loop

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

## Phase 9 — Native mobile

Speculative, and listed so the constraint is not forgotten: the App Store age
rating is why "roast the pick, never the person" is a hard rule rather than a
taste preference. Nothing should be built in Phases 5–8 that would need
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
