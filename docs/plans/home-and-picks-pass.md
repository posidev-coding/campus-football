# Home + Picks pass: Sleeper-caliber experience without the busy

## Context

Approved 2026-08-30, the day after the pick'em UX overhaul (lobby subtabs, My
Picks hero + This week | Results, entry checklist + celebration, clubhouse chat
removal) fully shipped (PRs #22–#27). Reference UX: Sleeper's NFL Pick'em —
"phenomenal but overwhelmingly busy; achieve a similar experience, don't copy
it." Flip is Tue Sep 1; first public Saturday Sep 5.

Two problems, measured:

1. **Home reads no pick'em state and is ~half news.** News-ish content is
   ~1,070px of a ~2,200px signed-in scroll, the page ends on ESPN articles,
   the teaser is static config-gated copy, and the nudges are independent
   banners with no priority — nothing computes "the one thing to do next."
2. **The picks area buries its loop.** ~20 first-contact concepts, six
   container nouns, standings in four places, the first pickable card below
   hero + blurb + standings table — and group trash talk fully built
   server-side with no UI door.

Decisions (Taylor, 2026-08-30): Group Talk returns as a **dedicated screen**
`/groups/{group}/talk` (the pick surface stays chat-free; ConversationTest's
no-embed pin on group.blade.php stays). Timeline: **land it all ASAP** — ship
order below is priority order, every PR independently shippable.

## Design disciplines (bind every task)

- **One amber thing per viewport**, counted or deadlined, tappable.
- **Tone budget:** amber = needs you · emerald = done/won · blue = the viewer ·
  mode palette = container identity · zinc = everything else. Dark mode
  un-brands, so every state keeps a non-color signal.
- **Two-tab contest surface** (Slate | Standings); everything else is a
  collapsible, modal, or deep link. No chat drawer, no bottom sheet, no FAB,
  no scrolling chip rows.
- **Null renders dashes or nothing — never zeros or defaults.** Counters only
  where a decision is attached. The pick-card's content set is frozen: no
  win-prob, views, or reactions on pick surfaces.
- **Two user-facing container nouns: group and room.** "Contest" swept from
  member-facing copy; evergreens keep "Always open" and are never called
  "tables" or "rooms."
- Every new string ×3 ContentRating registers; roast the pick/entry/record,
  never the person; instructions stay plain; no Georgia.
- Zero new inventory queries — projections of existing reads; new resolvers
  get query-parity pins; slates resolve via `Slate::onCard()` / `Cadence`.

## Ship order

### 1. Clubhouse restructure — Slate | Standings ✅ (this PR)

- Plate collapses to two tabs for both kinds; Members and Season merge into
  **Standings**; legacy `?view=season|members` normalize across (both hooks).
- **Slate tab = pure play**: the standings table and winner callout leave it;
  the first pickable card rises ~300px. The winner callout moves ABOVE the
  plate so both tabs walk in on the news.
- **Standings tab**, top to bottom: `x-you-strip` (viewer's line — Wk rank ·
  Wk pts · Wins · Pts, em dashes wherever no data; rooms drop the season
  pair) → `x-invite-panel` (groups only — rooms never advertise invites;
  collapsible, open at ≤3 members; link/copy/share/spoken code) → This week
  table → Season table (not for rooms) → Members disclosure (rows, Remove,
  Leave) → `x-mode-rules` sized from the CONTEST (the scoring panel).
- **State-aware front door**: a bare visit opens to Standings when the entry
  is in AND the card is live/prelim/final; explicit `?view=` always wins;
  completing an entry mid-session never yanks the surface (asked once, at
  mount).
- **Live-gated poll**: `wire:poll.30s.visible` on the Standings tab only
  while a slate game is live — the Saturday heartbeat, our DB only.
- `x-invite-panel` is the one home for the invite idiom (panel + moment
  wearings); the creation wizard's step 3 wears `moment`.
- Week-scroller paging of past cards deferred to task 7 (split-week bracket
  work; week 1 has one card).

### 2. Home momentum pass

"Your picks" section replaces the static teaser for flagged-in members —
compact `x-slate-row`s (extracted from pickem-home's needsRest markup) with
made/total, amber "Tiebreaker left", emerald "Entry in", kickoff; zero-container
members get the mode-door pitch. News demotion: team panels 5→3 + "More {Place}
news" door per panel; Latest news 6→3 (heading + "More" stay unconditional —
the News screen's only phone path). Foot door so the page never ends on
articles (`Lobby::openRoomCount()`, the lean COUNT). `data-tour="room"`
migrates with the slot. HomeTest 1-vs-5 query parity stays pinned.

### 3. PickemPulse + the next-up slot

`App\Support\PickemPulse::for(User): ?Nudge` — one lean read (`group_members`
→ contests → `Slate::onCard(Cadence::activeSaturday(...))` + pick counts +
tiebreaker flag; never `slate_entries`/`picks` as the root), memoized, shared
with task 2's strip. Ladder, first match wins: picks due → tiebreaker left →
fresh slate → commissioner build door (ONLY inside the Cadence deadline
window, `fromCount()` cached ~5 min — feasibility never runs at render on
Home; NULL leaves the door alone) → thin group → live now → results official
(window from `Cadence::officialFinal()`, never typed) → join the week →
locked-in calm. One card on Home: tone tint, one Voice line with a count or
deadline, whole card the CTA. Verify callout outranks it. ~9 Voice families ×3
registers; parity pins for 1-vs-3 groups AND commissioner-vs-member.

### 4. Small wins

Tiebreaker anchor (the amber chip scrolls to the box); vocabulary sweep
(group/room only; "contest" out of member copy); group-card amber "Tiebreaker
left" sub-state; "Kicked off" rename on the pick-card's temporal chip ("Lock"
stays exclusively the Woodshed wager); docs sync (screens.md post-overhaul
sections, roadmap's stale flip date).

### 5. Group Talk screen

`pickem.talk` → `/groups/{group}/talk`, a thin screen mounting the existing
conversation component with the group scope (write gates unchanged in
`PostToConversation`). Talk button in the group hero + link-row at the
Standings foot + a "go talk" door on the entry celebration. Members-only, both
kinds. `.ai/rules/livewire.md` amended to record the 2026-08-30 decision; the
group.blade.php no-embed pin STAYS.

### 6. Post-lock picks reveal grid

`x-picks-grid` on the Standings tab: rows = members (viewer pinned first),
columns = the slate's games, cells revealed PER GAME — dash until that game
kicks off, then the picked side, then green/red graded; signed points in the
PTS column. Inside the `stat-grid` scroll container with a sticky first
column; one pick-level query gated on the tab; "Picks show at kickoff" said
plainly. Leak test: pre-kickoff cells never render a pick.

### 7. Standings flavor + paging

Rank-change delta (▲2/▼1 vs last settled week) in the season table; followed-
team logo chip beside handles; trend-pills reclaim (orphan component); week-
scroller render site paging played cards (bracket mechanism, split weeks);
Picks-tab presence dot fed by PickemPulse cached per (user, card), busted from
`MakesPicks` on the completing transition.

## Explicitly out of scope

Persistent chat drawer, bottom-sheet primitive, FAB, matchmaking/browse,
organize/archive, invite QR (dependency), win-prob on pick surfaces, streaks/
perfect-week, Results-inline history, new UxSignal cases, Reverb standings
push (the live-gated poll is the interim), post-celebration push pitch (rule
amendment first).

## Verification (every task)

1. `php artisan test --compact --filter=<affected>`, then the full suite —
   the assertSeeInOrder pins interlock.
2. `vendor/bin/pint --dirty --format agent` after PHP; `npm run build` after
   Blade.
3. Device harness at 390/768 (+dark): `scrollTo({left:999}); scrollX === 0`;
   you-strip fits or drops a column; every mode palette on the changed
   surfaces.
4. URL checks: legacy `?view=` values normalize in BOTH hooks; `except:`
   defaults carry no query string.
5. Break-it-back where a wrong default is the risk (PickemPulse null cases,
   grid pre-kickoff cells).
