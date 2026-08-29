# UX Overhaul: Lobby subtabs, My Picks reorganization, entry-submitted feedback, chat removal

## Context

Flip is Tue Sep 1; first public Saturday Sep 5. PASS 4 split the pick'em landing
into My Picks (`/picks`) and the Lobby (`/lobby`), but the navigation still
isn't intuitive enough. User-approved decisions (2026-08-29):

1. **Lobby** gets room-type subtabs — filter behavior with an **"All" default**
   (nothing hidden by default; a shelf tab shows only that shelf).
2. **My Picks** gets a **hero "make your picks" CTA** plus a
   **This week | Results** subtab split (Last week + ladder move to Results).
3. **Pick surface** gets an **automatic "your entry is in" celebration** — no
   schema change, no fake submit button; completeness stays derived. Plus a
   visible entry checklist in the sticky chrome.
4. **The chat section at the foot of the clubhouse is removed** (user: it's
   confusing and distracting in its current state). Game/Team threads stay.

Ship order: **D → A → B → C** (D is smallest/independent; B and C both touch
`pickem-home.blade.php` and PickemHomeTest, so C lands after B). All four add
rows to PickemVoiceTest's dataset — land serially.

House constraints that bind everything: mobile-first at 390px, additive
breakpoints; ChromeConsistencyTest (no new `overflow-x-auto`, `border-b-2`,
inlined gutter track, or `flux:select`); sticky offsets via
`top-[var(--chrome-offset)]` only; every new user-facing string gets all three
ContentRating registers (LOUD surface — roast the pick never the person, no
Georgia); zero new inventory queries (projections only); `wire:key` on repeated
nodes; `#[Url]` props normalized in BOTH `mount()` and the updated hook; no
test deletion — updates only; American spelling; "board"/"floor" banned.

---

## Task A — Lobby: room-type subtabs, "All" default

**Files**: `resources/views/livewire/lobby.blade.php`,
`app/Enums/LobbyShelf.php`, `app/Support/Voice.php`,
`tests/Feature/Screens/LobbyTest.php`, `tests/Feature/LobbyRoomsTest.php`,
`tests/Feature/PickemVoiceTest.php`. Contingency only:
`resources/views/components/gutter-tabs.blade.php`.

**Design**:
- **Component**: `x-gutter-tabs` variant `shrink` (x-plate throws above 3 tabs).
  Labels via new `LobbyShelf::tabLabel()`: **All | House | Quick | Spotlight |
  Conference** ("All" literal at call site). Fit math says ~353–356px against
  358px available at 390 — knife-edge, so measure in the device harness
  (`scrollTo({left:999}); window.scrollX === 0` at w=390). **Contingency
  (pre-decided)**: if it overflows, add an opt-in `density="compact"` prop to
  gutter-tabs (px-2 on shrink, default unchanged, zero ripple to other
  callers). Do NOT fall back to a filter-menu — the user chose subtabs.
- **State**: `#[Url(except: 'all')] public string $view = 'all'` — shared
  `$view` name, values `all` + the four `LobbyShelf` backing values (`house`,
  `quick_hits`, `spotlight`, `conference`). Normalize in `mount()` AND
  `updatedView()` — invalid values fall back to `'all'`, never an error
  (pattern: `group.blade.php` `normalizedView()` ~line 114, and
  `pickem-leaderboard.blade.php:21`). `wire:key` prefix `lobby-view`.
- **Placement**: inside the existing sticky Saturday band
  (lobby.blade.php ~182–192) — band becomes a two-row flex-col (Saturday/count
  row, then the tab row). One sticky block, nothing to travel through, filter
  reachable mid-scroll. Tab row renders only when `$this->shelves !== []`.
  "N rooms open" count stays global.
- **Filtering**: new computed `visibleShelves()` — a pure filter of the
  existing `shelves` computed; `openRooms` stays the one inventory read. A
  shelf tab shows that shelf's open rows AND its closed/dashed content.
  **Evergreens ("Always open"), invite block, group-wizard row, "How it's
  played", callouts/notices: All-tab/unconditioned chrome** — tabs filter the
  Saturday shelves only (evergreens show on All only; components.md forbids
  folding them into shelves).
- **Empty filtered tab**: one honest line, new Voice key `lobby.shelf.empty`
  (no tokens; all three registers; the instruction "the All tab has what's
  open" survives every register). Global empty store: unchanged callout, no
  tabs.

**Tests**: LobbyTest — default is All (existing stacked-shelf test keeps
passing untouched); `->set('view','quick_hits')` shows only that shelf;
`Livewire::withQueryParams(['view'=>'nonsense'])` → `'all'` (mount half);
`->set('view','garbage')` → `'all'` (hook half); empty-tab Voice line;
evergreens on All, gone when filtered. LobbyRoomsTest — add a dataset pin of
`LobbyShelf::tabLabel()` (the short labels are load-bearing for the 390px
fit). PickemVoiceTest — add `lobby.shelf.empty`.

---

## Task B — My Picks: hero CTA + "This week | Results" subtabs

**Files**: `resources/views/livewire/pickem-home.blade.php`,
`app/Support/Voice.php`, `tests/Feature/Screens/PickemHomeTest.php`,
`tests/Feature/PickemVoiceTest.php`.

**Design**:
- **ONE hero + compact rows** (not stacked heroes). Urgency: `live` state
  first, then soonest `firstKick` ascending, nulls last. Two new computeds,
  both projections of `needsPicks()` (zero new queries): `heroCard()` = first
  of the sorted list; `needsRest()` = the remainder using today's compact row
  markup (~lines 429–447, unchanged). Zone keeps its plain "Needs your picks"
  heading + existing `lobby.needs.subheading` line.
- **Hero treatment**: the card wears the contest mode's `palette()['tile']`
  (readable border+tint, no new on-accent color rules). Contents: mode icon
  puck, group name (plain), `x-slate-progress` N of M, kick clock (live state
  shows `x-slate-status` instead), render-guarded `picks.hero.zinger` line,
  and a full-width `flux:button variant="primary"` — plain label **"Make your
  picks"** (`made === 0`) / **"Finish your picks"** (`made > 0`) —
  `wire:navigate` into the clubhouse. `wire:key="hero-{id}"`.
- **Subtabs: `x-plate`** (a genuine "fork in the screen", 2 tabs, default
  model `view`). `#[Url(except: 'week')] public string $view = 'week'`,
  values `week|results`, normalized in mount + hook. `keyPrefix="picks-view"`.
  Tab content wrapped in the group screen's
  `wire:loading.class="opacity-60 pointer-events-none"` pattern.
- **Layout** — above the fork (always): sr-only h1, verify-callout, session
  notice, the plate. **This week**: week-ribbon, hero + needs rows, Your
  groups (or first-run pitch), Public rooms, Always open, invite-code
  disclosure, lobby door. **Results**: Last week zone (or new honest empty
  line `picks.results.empty`), the XP ladder, then an `x-link-row` "Season
  history" → `route('pickem.history')` (History stays the archive; Results is
  the fresh payoff). GROUPS and ROOMS keep separate headings; exactly one
  lobby door per screen.
- **First-run: no tabs.** Plate renders only when
  `$this->cards->isNotEmpty() || $this->lastWeek->isNotEmpty()`. Genuinely-new
  readers get today's single scroll ("Two ways to play", invite code, ladder).
  Rooms-only readers have cards → get tabs. `?view=results` on a tabless
  first-run normalizes silently. (Keeps GamificationTest and the ladder test
  green untouched.)

**Voice keys**: `picks.hero.zinger` (optional hero line — kickoff-stakes
energy; the CTA button stays plain) and `picks.results.empty` ("nothing
settled yet — results land after Saturday" in three registers; no tokens).

**Tests** (updates, no deletions): rework the pinned zone-order test (~line
83) — default render asserts `['My Picks','Needs your picks','Your groups',
'Have an invite code?','The Lobby']` + `assertDontSee('Last week')`; then
`->set('view','results')->assertSeeInOrder(['Last week','Season history'])`.
Monday-payoff test gains `->set('view','results')`. New: hero renders (name,
CTA label flip after first pick, clubhouse route); urgency choice (soonest
kick wins the hero, other slate is a compact row; live outranks upcoming); tab
default + both normalization halves; first-run renders no plate; Results
reachable for rooms-only readers. PickemVoiceTest — add both keys.

---

## Task C — Pick surface: entry checklist + one-time celebration

**Files**: `app/Livewire/Concerns/MakesPicks.php`,
`resources/views/partials/pick-slate.blade.php`,
`resources/views/components/group-card.blade.php`,
`resources/views/livewire/pickem-home.blade.php` (one derived flag in
`cards()`), `app/Support/Voice.php`, tests: `PickemPickingTest.php`,
`GroupPageTest.php`, `Screens/PickemHomeTest.php`, `PickemVoiceTest.php`.

**Design**:
- **Completeness stays derived, stated once**: new
  `protected function entryComplete(Slate $slate): bool` in MakesPicks =
  slate has games AND all its game ids appear in `myPicks` AND
  (`tiebreaker_slate_game_id === null` OR the entry's
  `tiebreaker_total !== null`). Reads only already-loaded computeds. The
  Woodshed lock wager plays no part.
- **Fires once via a PROTECTED (non-persisted) property**:
  `protected ?int $entryJustCompleted = null;` — Livewire serializes only
  public properties, so it exists for exactly the completing response. In
  `pick()` and `saveTotal()`: snapshot `$wasComplete` BEFORE the action, after
  `refreshPicks()` compute `$nowComplete`; set the flag on the false→true
  transition only. Refusals change nothing → never fire. Changing a pick
  after completion: never re-fires, checklist stays ✓ (no un-celebrating).
  When the tiebreaker save is the completing act, SKIP the routine
  `picks.tiebreaker.saved` notice on that response — one banner, not two.
  Blade accessor: `public function entryCelebrating(int $slateId): bool`.
- **Checklist in the existing sticky sub-chrome** (pick-slate.blade.php
  ~68–135, already measured into `--pickem-chrome`). Middle slot becomes
  three-state (interactive, upcoming/live): (1) picks incomplete →
  `x-slate-progress` N of M (unchanged); (2) picks done, tiebreaker missing →
  plain amber "Tiebreaker left"; (3) complete → emerald check + "Entry in"
  (persistent — derived, so a reload agrees). Fits at 390 beside the ring.
- **Celebration row** below the sticky chrome, guarded
  `@if ($interactive && $this->entryCelebrating($slate->id))` — the
  slate-builder preview (`interactive: false`) and outsider previews can
  never show it. Markup follows the verify-celebration precedent
  (home.blade.php ~446–468): emerald ring row, check-badge icon, one LOUD
  Voice line (`picks.entry.celebration`), Alpine-dismissable,
  `role="status" aria-live="polite"`, entrance behind `motion-safe:`. No
  toasts, no confetti — the house has neither.
- **Group-card "Entry in ✓"**: `cards()` gains
  `'entryIn' => $slate !== null && published && total > 0 && made >= total &&
  (no tiebreaker game || tiebreaker saved)` — all operands already in scope.
  Group-card's `upcoming` branch swaps the progress cluster for emerald check
  + "Entry in"; kick time stays.

**Voice key**: `picks.entry.celebration` — three registers, no tokens. PG:
"Your entry is in — every game picked, tiebreaker called. Good luck
Saturday." PG13/R escalate confidence/roast of the slate, never the reader.

**Tests**: new `describe('the entry is in')` in PickemPickingTest —
celebration appears exactly on the completing response (last pick, and
tiebreaker-save variants), gone on the next `$refresh` while "Entry in"
persists; tiebreaker-saved notice suppressed when it's the completing act;
no-tiebreaker slate completes on last pick; "Tiebreaker left" state; changed
pick after completion → no re-fire; preview never celebrates (source sweep on
the `$interactive` guard + outsider render assertDontSee). PickemHomeTest —
complete entry shows "Entry in" on the card, incomplete keeps progress.
PickemVoiceTest — add the key. Also run LockPickTest + PublicContestTest
(shared trait/host).

---

## Task D — Remove the chat section from the clubhouse

**Files**: `resources/views/livewire/group.blade.php`,
`tests/Feature/ConversationTest.php`, `.ai/rules/livewire.md`.

**Design**: delete group.blade.php lines ~908–918 (the `border-t` foot
wrapper + `<livewire:conversation :topic="$group" lazy ...>` embed and its
comments — the wrapper is the only group-scope chrome around it). **Render
site only**: `PostToConversation` (group stays whitelisted),
`DeleteConversationPost`, the conversation component/model/morph map, and
Filament moderation all stay; group posts remain moderatable. Deep-link audit
came back clean — nothing else links to the group thread. Game and Team
embeds untouched.

**Rule edit (user-directed)**: `.ai/rules/livewire.md:15` currently says the
conversation "mounts at the FOOT of Game, Team and Group". Update to Game and
Team, noting the 2026-08-29 removal from Group (clubhouse is the pick
surface; the group scope stays whitelisted server-side).

**Tests** (updates, no deletions): the three-host source sweep
(ConversationTest ~397–414) drops the `group` row and gains the inverse pin —
group.blade.php must NOT contain `<livewire:conversation`. The lazy-embed
test (~416–438) moves its shell assertions to the game host; the hydration
half (`Livewire::test('conversation', ['topic' => $group])`) stays as-is.
All action-level membership/moderation/voice tests stay green untouched.

---

## Verification (every task)

1. `php artisan test --compact --filter=<affected>` per task:
   - A: LobbyTest, LobbyRoomsTest, ChromeConsistencyTest, PickemVoiceTest, AlpineExpressionsTest
   - B: PickemHomeTest, PickemGroupsTest, GamificationTest, VerifyCalloutTest, PickemVoiceTest, ChromeConsistencyTest
   - C: PickemPickingTest, GroupPageTest, PickemHomeTest, PickemVoiceTest, LockPickTest, PublicContestTest
   - D: ConversationTest, GroupPageTest, PickemPickingTest, PublicContestTest
2. `vendor/bin/pint --dirty --format agent` after PHP; `npm run build` after Blade.
3. Device harness (Chrome won't shrink below ~600px):
   - `/__device?path=/lobby&w=390,768&h=800` (+`&dark=1`) — five tabs on one
     row; `scrollTo({left:999}); window.scrollX === 0`; band+tabs stick as one
     opaque block. If overflow: execute the `density="compact"` contingency.
   - `/__device?path=/picks&w=390,768&h=800` — both tabs, all three mode
     palettes on the hero (Woodshed's dark tile especially), one lobby door.
   - Clubhouse at 390 — three-state band, celebration fires once on the
     completing mutation, dismissable, absent in reduced motion's entrance;
     no orphaned border at the foot where the chat was.
4. URL checks: `/lobby?view=house` loads filtered, All carries no query
   string; `/picks?view=results` survives refresh, `week` carries none.
5. Full suite (`php artisan test --compact`) after all four land — the
   assertSeeInOrder pins interlock.
