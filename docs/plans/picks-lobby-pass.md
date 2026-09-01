# Picks Front Doors Pass — /picks and /lobby

**Status: approved Aug 31, 2026. Plan of record. Ship: everything lands before
Sep 5** (the flag flips Sep 1; first public Saturday Sep 5) as stacked,
independently shippable, individually revertible PRs — the #54–#60 pattern,
merged bottom-up. No migrations, no schema, no flags, no route changes
anywhere in this pass — every revert is one `git revert`.

## Context

`/picks` and `/lobby` are the front doors of the Picks area and were
deliberately left untouched by the Aug 30–31 pass
([home-and-picks-pass](home-and-picks-pass.md), PRs #54–#61). The recon
confirmed every suspected friction point in code:

- `/picks` renders up to **6 section headings for 1–2 cards each**, organized
  by the container taxonomy (groups / rooms / evergreen tables) — only "Needs
  your picks" is intent-shaped.
- Gamification is nearly invisible where users start: the rank ladder renders
  **only on the Results tab**, and below `sm` the app header doesn't render at
  all, so **mobile users see zero wallet chips on either screen**. Lattes
  appear on no screen anywhere.
- Nothing celebrates anything: the one celebration in the house (the emerald
  entry-in banner) lives on the pick surface. No momentum data
  (place-in-field, movement, wins context) reaches `/picks`.
- `/lobby` rows are deliberately uniform ("thirteen pitches is an essay") —
  the 10 `LobbyFlavor` personalities render nowhere on the shelf; no lock
  clock; no seat urgency. Lines 444–509 of 513 are foot matter (invite +
  create link + full rules block).
- `/lobby` appears **nowhere** in the prior plan of record — unexamined, not
  deliberately preserved.

## Decisions (approved Aug 31, 2026 — final; do not re-litigate)

1. Consolidate `/picks`' three container zones into ONE "Where you play"
   stack with kind-first identity per card — a conscious amendment of the
   `.ai/rules/components.md` two-products rule (amended text below).
   **Reversed 2026-09-01** — two sections that mirror the group switcher's
   menu; see [picks-switcher-pass](picks-switcher-pass.md).
2. All four flash elements approved: weekly-win payoff moment, ticking
   countdown clocks (hero + lobby band), louder lobby rows (flavor pitch
   lines), "All in" state card.
3. Momentum surfaces **existing data only** (you-strip, place-in-field,
   ladder stays on Results). No streaks, no new backend concepts.
4. Everything before Sep 5.

Standing constraints this plan lives inside: the amber-budget and celebration
rules are prose-only but honored; the horizontal-scroll ban is test-enforced
and **this pass adds no horizontal scroll**. `Cadence::activeSaturday` is
unmemoized and already called 3×/render on /picks — nothing here may call it
again. `partials/pick-slate.blade.php` and the first-run "Two ways to play"
block are **out of scope and stay byte-identical**. Never render literal
"PG"/"PG-13"/"R" — `ContentRating::label()` only (CFB-31 pending). The filed
follow-ups `standings-week-scroller-paging` and `member-form-strip` are NOT
part of this pass.

---

## PR breakdown (7 stacked PRs, merge bottom-up 1→7)

PRs 1, 2, 3, 5 are mutually independent (parallelizable). PR 4 stacks on 3.
PR 6 can land any time after 1/3. PR 7 (biggest churn + rule amendment) lands
last, on a quiet morning before Sep 5.

### PR 1 — `picks-you-strip` — the "you" strip (Sleeper pattern #2)

`x-you-strip` (unchanged API: `name` + `stats: list<{label,value}>`, em dashes
pre-rendered by caller) gains its second render site: top of This week, after
the ribbon, before "Needs your picks", guarded `@if ($this->hasTabs)` so
first-run stays byte-identical.

New computed in `resources/views/livewire/pickem-home.blade.php`:

```php
#[Computed]
public function youStrip(): ?array
{
    $user = auth()->user();
    $wins = $this->cards->sum('wins');

    return [
        'name' => $user->handle !== null ? '@'.$user->handle : $user->name, // group.blade.php's rule
        'stats' => [
            ['label' => 'Rank',   'value' => $this->rank['name'] ?? '—'],
            ['label' => 'XP',     'value' => number_format($this->walletXp)],
            ['label' => 'Lattes', 'value' => number_format($user->walletTotals()['lattes'])],
            // Dash until the first win EXISTS — "0 Wins" all September is a
            // counter with no decision attached.
            ['label' => 'Wins',   'value' => $wins > 0 ? (string) $wins : '—'],
        ],
    ];
}
```

**Data cost: zero new queries** (`rank`/`walletXp` exist; lattes ride the
memoized `walletTotals()` SUM; wins is a projection of `cards()`). Match the
Lattes label to whatever `x-wallet-chips` calls the currency. 390 risk:
"All-American" is the widest rank — if identity collapses below ~3 chars in
the device pass, drop the **Lattes** column (the component's documented
fallback), never Rank.

**Tests** (PickemHomeTest): strip renders "You" + Rank/XP/Lattes/Wins + rung
name for a carded reader; Wins dash pre-settle; first-run test gains
`->assertDontSee('Lattes')`; flat-query guard (query-log count equal
before/after — the strip adds none).

### PR 2 — `results-momentum` — place-in-field + payoff banner

**Place-in-field**: `places()` computed copying
`pickem-history.blade.php:47-70`'s one-query pattern, scoped to
`$this->lastWeek->pluck('slate_id')`. Last week rows mirror history's markup:
Winner badge takes precedence, else `Number::ordinal($place).' of '.$of` in
`tabular text-micro text-zinc-500`. Referenced **only** from the Results
template branch — computeds are lazy, and that laziness is pinned by a
query-count test (0 entries reading `slate_entries` places on `view=week`;
exactly 1 on `view=results`).

**Payoff banner** (the house's second celebration — a conscious extension of
"the house has neither"): emerald banner atop Results when a settled win
exists in `lastWeek` (7-day window), entry-in grammar,
`wire:key="payoff-banner"`, `role="status"`, icon + words as the non-color
signal. `motion-safe:animate-entry-in` (the sanctioned keyframe,
app.css:197-211) applied **only on first appearance per session** — the
pick-surface's one-response protected-property trick doesn't fit a passive
arrival, so:

```php
#[Computed]
public function payoff() // projection of lastWeek — zero new queries
{
    $won = $this->lastWeek->filter(fn (SlateEntry $e) => $e->won)->values();
    return $won->isEmpty() ? null : $won;
}

#[Computed]
public function payoffFresh(): bool
{
    if ($this->payoff === null) {
        return false; // null guard — no wins, nothing fresh
    }
    $ids = $this->payoff->pluck('id')->all();
    $seen = session('picks.payoff.seen', []);
    $fresh = array_diff($ids, $seen) !== [];
    if ($fresh) session(['picks.payoff.seen' => array_values(array_unique([...$seen, ...$ids]))]);
    return $fresh;
}
```

Both touched only from the Results template (nothing is marked seen until the
banner actually rendered). Copy: `picks.payoff.banner` (one win) /
`picks.payoff.banner_many` (several) — Voice manifest below.

**Tests**: place assertion with a two-entry fixture (`'2nd of 2'`); banner:
won fixture + `view=results` → `assertSeeHtml('motion-safe:animate-entry-in')`
and the wire:key; a SECOND `Livewire::test` in the same test (same session) →
banner present, animation class absent (the no-re-animate mechanism
asserted); the laziness query pin; 2 keys added to the PickemVoiceTest
dataset.

### PR 3 — `kick-clock` — shared countdown component + hero final hour

New `resources/views/components/kick-clock.blade.php` — the countdown idiom's
ONE home. `partials/pick-slate.blade.php` is NOT touched.

```php
@props([
    'at',                        // CarbonInterface — callers SKIP the component with no kickoff
    'idlePrefix' => 'kicks',     // idle-state words: "kicks" (hero) / "First kick" (band)
    'suffix' => 'to kickoff',    // final-hour words after mm:ss
])
```

Single-span root with `data-kick-at="{{ $at->getTimestamp() }}"`, Alpine
`x-data` methods (`start()/stop()/label()` — no leading comments, no bare
`const`; AlpineExpressionsTest-safe), `x-init="start()"`, interval torn down
on `beforeunload` (pick-slate's grammar). `label()`: ≤0 → `Kickoff`; ≥3600s →
the idle string (`idlePrefix` + `D g:ia` in `config('cfb.timezone')`); else
`M:SS` + suffix. **The server renders the same string as static initial
content** — the automated tab has no rAF, so tests assert end-state DOM only
(`data-kick-at`, handler markers, the static string), never a tick. No
`$wire.$refresh()` at zero; no countdown-ring (that idiom stays pinned to its
component).

Hero call site replaces the static `kicks D g:ia` span:
`<x-kick-clock :at="$hero['firstKick']" class="shrink-0 text-micro {{ $heroPalette['body'] }}" />`
— the palette body class flows through attributes so the Woodshed's onDark
tile stays readable.

**Tests**: travel to 30 min pre-kick → hero carries `data-kick-at` +
`to kickoff` + static mm:ss; travel to 2 days out → idle string identical to
the old span's words. Woodshed onDark break-it-back (assert the `body` class
is genuinely applied, not defaulted). ChromeConsistencyTest banned-map entry:
`'data-kick-at=' => ['components/kick-clock.blade.php', '<x-kick-clock>']`.

### PR 4 — `lobby-first-kick` — the band clock (stacks on PR 3)

New computed in `resources/views/livewire/lobby.blade.php`: resolve each open
room's published slate id **off the relations `Lobby::openRooms` already
eager-loads** (slates carry `id/contest_id/week_id/status/saturday`) — mirror
`LobbyCatalog::shelves()`'s own slate resolution so two reads can't disagree
about the same Saturday — then ONE aggregate:

```php
$min = SlateGame::query()
    ->join('games', 'games.id', '=', 'slate_games.game_id')
    ->whereIn('slate_games.slate_id', $slateIds)
    ->where('games.kickoff_at', '>', now())   // future-only: the shopper's actionable clock
    ->min('games.kickoff_at');
```

Null → no clock (null = no data; also when the store is empty or every game
has kicked). Rendered as a third micro-row **inside** the existing sticky
band (still exactly one sticky block, `top-[var(--chrome-offset)]`, z-30
untouched):
`<x-kick-clock :at="$this->firstKick" idle-prefix="First kick" suffix="to first kick" class="text-micro text-zinc-500 dark:text-zinc-400" />`.
Add `firstKick` to the `ContestFull` catch's `unset(...)` list
(lobby.blade.php:250).

**Tests** (LobbyTest): open-room fixture → band carries `data-kick-at` +
`First kick`; empty store → `assertDontSeeHtml('data-kick-at')`; all-kicked
fixture → no clock. Query pin: log entries matching
`min("games"."kickoff_at")` `=== 1` with rooms open, `=== 0` with none.

### PR 5 — `lobby-louder-rows` — pitch lines, seats-left, foot diet

**Pitch line** (conscious reversal of a pinned decision):
`resources/views/components/room-row.blade.php` gains `'pitch' => null`,
rendered as one **truncating** `text-micro` line in the name cell (thirteen
rows stay a shelf, not an essay — rewrite the docblock that states the old
rule). Lobby passes, transient rows only:
`:pitch="$entry['room']->flavorEnum()?->blurb($entry['gameCount']) ?? $entry['mode']->blurb($entry['gameCount'])"`
— **zero queries** (enum reads of the loaded `flavor` column + the
already-passed count). Evergreen rows pass nothing (no Saturday → no pitch).
Blurbs / `ContestMode::blurb()` are register-constant product vocabulary —
not Voice.

**Seats-left signal**: computed in the component —
`$left = $room->member_cap === null ? null : max(0, $room->member_cap - $seats)`;
when `$left !== null && $left <= 2 && ! $seated` the seats fragment becomes
`<span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $left }} {{ Str::plural('seat', $left) }} left</span>`
— **weight, not color** (rows repeat; the amber budget is one per viewport
and the verify-callout may already hold it; weight survives dark-mode
un-branding). A fact, register-constant, not Voice (the PickemVoiceTest
dataset requires pg ≠ r, which a fact must not satisfy).

**Foot diet**: "How it's played" (heading + `lobby.rules.subheading` + 3
`x-mode-rules` + shared-laws paragraph) collapses into ONE disclosure in the
invite-code disclosure's exact grammar (unconditional
`x-data="{ open: false }"`, `x-bind:aria-expanded`, chevron rotate, `x-show`
+ `x-cloak`) — **all strings stay in the DOM** so LobbyTest's rules
assertions hold. Invite-a-friend card kept; heading drops from
`flux:heading size="lg"` to subheading weight.

**Tests** (LobbyTest): REVERSE the pin
`->assertDontSee('The flash card: 5 games, in and out.')` →
`->assertSee(...)`, comment rewritten to record the approved reversal.
Seats-left break-it-back both directions: cap-1 fixture asserts
`'1 seat left'` AND `assertSeeHtml('font-semibold text-zinc-900')`; roomy
fixture asserts `'of 20 seats'` and `assertDontSee('seats left')`. Foot:
rules strings still present; `assertSeeHtml('aria-expanded="false"')` on the
new disclosure.

### PR 6 — `picks-all-in` — the "All in" card

`#[Computed] allIn(): bool` =
`$this->cards->isNotEmpty() && $this->needsPicks->isEmpty() && $this->cards->contains(fn ($c) => $c['entryIn'])`
— pure projection. Static emerald status card (NOT animated — a state, not an
event) rendered in the "Needs your picks" slot when the zone is empty:
`wire:key="all-in"`, `flux:icon.check-circle-fill` + bold "All in." lead-in
(the non-color signal), `Voice::line('picks.allin.body')`.

**Tests** (PickemHomeTest): after the last pick lands → `assertSee('All in')`
+ the Voice line; one pick missing → `assertDontSee('All in')`. 1 key to the
Voice dataset.

### PR 7 — `where-you-play` — consolidation + rule amendment + hardening

**The stack**: `whereYouPlay()` =
`$this->groupCards->concat($this->roomCards)->concat($this->tableCards)->values()`
(groups alphabetical → rooms past-last → evergreens). The three projections
STAY (the first-run fork and lobby-door foot guard still key off
`groupCards->isEmpty()`). One heading row: "Where you play" + the existing
"Start a group" link (unchanged destination). **"Find a room" is retired** —
the lobby-door link-row below is the same destination and the one-door rule
(the partial's own docblock) forbids drawing it twice. One definition line:
`picks.whereplay.subheading`. Cards keyed
`wire:key="play-{{ $card['group']->id }}"`.

**Kind-first micro-line on `resources/views/components/group-card.blade.php`**
(no prop change — `card` already carries `group` + `past`; past branch tested
FIRST; join-landing grammar, join.blade.php:341-356):

- private group: `Private group, all season · {N} {member(s)}`
- room, past: `Public room · Saturday played · {N} {member(s)}`
- room, current: `Public room · this Saturday · {N} {member(s)}`
- evergreen: `Always open · {N} {member(s)}` — **never "table"/"room"**: the
  house has exactly two user-facing container nouns and evergreens keep
  "Always open" (docs/product.md vocabulary law).

Kind lines are product facts: plain, register-constant, not Voice.

**Rule amendment** — `.ai/rules/components.md`, replace the first rule's
heading + paragraph (paragraphs on `past`, first-run, group-hero stay):

```markdown
## GROUPS and ROOMS are two products; one stack, with the kind said on every card
(Amended 2026-09, supersedes "never share a heading".) My Picks sells every seat in ONE
"Where you play" stack: groupCards (`! isLobby()`, alphabetical) then roomCards
(`isRoom()`, past Saturdays last) then tableCards (evergreen `isLobby() && ! isRoom()`),
concatenated by whereYouPlay() — projections of cards() only; never a fourth query. The
HEADINGS merged because three headings over one thumb of cards read as three products;
the DISTINCTION did not: every card leads its micro-line with its kind in the
join-landing's grammar ("Private group, all season ·" / "Public room · this Saturday ·" /
"Always open ·"), so the kind is said once per CARD instead of once per zone. A past
room's line says "Saturday played", never "this Saturday"; an evergreen is "Always
open", never a room's one-Saturday label and never "table" — two user-facing container
nouns, still. The definition line under the heading is Voice
(`picks.whereplay.subheading`); the kind lines are facts and stay plain.
```

Also in this PR (so a revert can't orphan the amendment): `docs/screens.md`'s
My Picks section rewritten for the single stack. **GuidedTourTest
hardening**: extend the Georgia-sweep prefixes (GuidedTourTest.php:288) to
include `'picks.'` and `'lobby.'` (pre-checked: no existing violations).

**Voice**: add `picks.whereplay.subheading`; **retire**
`picks.groups.subheading` from `Voice::LINES` and the dataset (its only
render site merges away). `picks.rooms.subheading` survives (first-run site).

**Tests** (PickemHomeTest, pins quoted):

- "orders the zones by urgency" assertSeeInOrder: `'Your groups'` →
  `'Where you play'`.
- Promise test and "names both ways to play": `assertDontSee('Your groups')`
  → `assertDontSee('Where you play')`.
- "keeps a small escape to the wizard": `assertSee('Your groups')` →
  `assertSee('Where you play')`; `Start a group` + create-route assertions
  unchanged.
- "files a private group and a joined room under their own headings" →
  renamed; asserts order `['Where you play', $group->name, $room->name]`
  (groups before rooms), both kind lines, the subheading Voice line,
  `assertSee(route('pickem.lobby'))` (the door),
  `assertDontSee('Your groups')`, `assertDontSee('Public rooms')`.
- Past-room test: add `assertSee('Saturday played')` +
  `assertDontSee('Public room · this Saturday')` (both directions).
- Surviving pins, deliberately untouched:
  `substr_count($html, '1 public room open this Saturday') === 1`;
  `assertDontSee('Hail Mary')`; `assertDontSee('10 of 10')`;
  `assertDontSee('0 public rooms open')`; first-run no plate / no
  `wire:key="picks-view-results"`.

---

## Voice key manifest

All three registers; all keys appended to the PickemVoiceTest dataset; every
token already exists in the shared replacement map — no map additions.

| Key | Tokens | pg | pg13 | r |
|---|---|---|---|---|
| `picks.payoff.banner` | :group, :points | You won the week in :group — :points points. Take a bow. | You took the week in :group. :points points, and the standings agree. | You won the whole week in :group. :points points, and somebody in there is still demanding a recount. |
| `picks.payoff.banner_many` | :count | You won the week in :count groups. Take the victory lap. | Winner in :count groups this week. Act like you expected it. | :count groups, :count wins. Go collect your receipts everywhere at once. |
| `picks.allin.body` | — | Every entry is in. Nothing left to do but watch. | Every entry is in. The picks are on their own now. | Every entry is in. Your picks are out there alone, and a couple of them already look nervous. |
| `picks.whereplay.subheading` | — | Every seat you hold — season-long groups and one-Saturday rooms. | Every seat you hold. Each card says which kind it is; the standings say how it's going. | Every seat you hold: the season-long grudges up top, the Saturday flings underneath. |

Deliberately plain (headings/affordances/facts, register-constant, NOT
Voice): "Where you play", "All in.", the kind lines, stat labels
Rank/XP/Lattes/Wins, kick-clock's "kicks"/"First kick"/"to kickoff"/
"Kickoff", ":n seats left", "3rd of 9". No "board", no "floor", no "form",
no Georgia; roast the pick/slate, never the person.

## Feature-move ledger (nothing silently lost)

**Moves**: "Your groups"/"Public rooms"/"Always open" zones → one "Where you
play" stack (definitions move onto every card as kind lines); "Start a group"
link → the merged heading row; hero's static `kicks D g:ia` → x-kick-clock's
idle state (same words); "How it's played" → one collapsed disclosure (all
strings stay in the DOM); invite-a-friend heading weight only.

**Removed**: "Find a room" heading link (duplicate door — the lobby-door
remains); `picks.groups.subheading` key (render site merged away).

**Untouched**: first-run "Two ways to play" block byte-for-byte; invite-code
disclosure incl. auto-open-on-error; lobby-door partial + both mutually
exclusive render sites + one `roomsOpen` read; ribbon; plate fork; ladder
card (incl. tabless first-run); Season history link-row;
`picks.results.empty`; lobby band/tabs/shelves/closed rows/empty
states/evergreens; "Want a season-long group?" link-row; the promise outside
the flag; pick-slate and its entry-in celebration.

## Verification (per PR, in order)

1. `php artisan test --compact --filter=` the touched test file(s), then the
   full suite before each merge (the assertSeeInOrder pins interlock).
2. `vendor/bin/pint --dirty --format agent` (PHP touched in every PR).
3. `npm run build` (Blade touched in every PR — new static classes must
   survive the Tailwind 4 content scan; all class strings full and static).
4. Device harness, both schemes, every PR:
   `/__device?path=/picks&w=390,768&h=800[&dark=1]`,
   `/__device?path=/picks%3Fview%3Dresults&…`, `/__device?path=/lobby&…`.
   In each frame: `scrollTo({left:999}); scrollX === 0`. Specifics —
   PR 1: four stat columns with a long handle AND rank "All-American";
   PR 2: banner in dark (ring+icon+words carry the state), reduced-motion
   shows no entrance; PR 3: Woodshed onDark hero clock readable, mm:ss
   doesn't wrap at 390; PR 4: band height at 390, content passes under
   cleanly; PR 5: 13-row shelf stays uniform (one truncating pitch line),
   seats-left legible in dark, foot disclosure collapsed by default;
   PR 6: card carries state without color in dark; PR 7: kind lines truncate
   before badges at 390, first-run byte-identical, a seeded evergreen shows
   "Always open ·".
5. End-state only for anything moving: `data-kick-at` + the static server
   string, never a tick (no rAF in the automated tab).
6. Break-it-back where a wrong default is the risk: seats-left both
   directions; Woodshed body-class applied-not-defaulted; payoff
   no-re-animate; Wins dash pre-settle.

## Risks / rollback

No migrations, schema, flags, or routes — every PR reverts with one
`git revert` mid-week. PR 3: the Alpine interval is torn down on navigate
(pick-slate's grammar); a stale "Kickoff" at zero matches today's static
behavior. PR 4: +1 pinned query per lobby load is the entire cost. PR 5:
pitch lines capped at one truncating line; the reversed LobbyTest pin
re-reverses with the revert. PR 7 is the one to watch mid-week: heading text
+ wire:keys change (`lobby-group-*` → `play-*`), so live morphs re-render the
stack once — cosmetic; a revert restores zones + subheadings + rule text in
one commit. Words only; no data or URL changes anywhere.
