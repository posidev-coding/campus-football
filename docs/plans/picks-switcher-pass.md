# Picks Switcher Pass — the group switcher and the two-section overview

**Status: approved Sep 1, 2026 (plan file
`onboarding-hasn-t-gone-so-typed-treasure`); shipped the same day as seven
stacked PRs, merged bottom-up.** No migrations, no schema, no flags, no route
changes — every revert is one `git revert`.

## Context

The first onboarded readers were lost in the Picks area. They hold seats in
several private groups and public rooms at once, and `/picks` answered that
with one undifferentiated "Where you play" stack of borderline-only cards,
pushed down the page by a "Needs your picks" zone that rendered every one of
those same groups a second time as compact rows. Nothing on the clubhouse
said which of your groups you were standing in, and the only way to another
one was back out through the whole overview.

## Decisions (with the founder, Sep 1)

1. A **group switcher** — the multi-tenant scope selector — at the top center
   of My Picks and as the clubhouse title. Selecting a group NAVIGATES to its
   clubhouse; `/picks` stays the overview, listed first as "All my picks"
   (renamed in pass 2, 2026-09-01: trigger "My groups and rooms", menu row
   "All my groups and rooms" — see picks-pass-2.md).
2. The menu's "Week N Contests" section lists the rooms the reader is
   **seated in** plus a trailing "Browse the Lobby · N open" row — never every
   open room. The Lobby stays its own section tab and is where a room is
   joined.
3. "Where you play" becomes **two sections that mirror the menu**: "My Groups"
   and "Week N Contests". This deliberately REVERSES the 2026-08-31 one-stack
   merge ([picks-lobby-pass](picks-lobby-pass.md) decision 1): a stack of cards
   each carrying its own kind line read as one product with fine print; two
   headings that are the menu's own sections are the taxonomy said in two
   places, not three products.
4. The "Needs your picks" compact rows are **removed**: one hero + "and N
   more below"; the section cards carry each group's state.
5. Cards get a surface (`bg-white` / `dark:bg-zinc-900`) and `gap-3`, because
   two hairline borders 8px apart on the page ground is why they ran together.

Nothing is named `$scope` (the League's word). The switcher holds no
Livewire state. Every new heading and affordance is plain; every sentence
under one is Voice in three registers.

## What shipped, per PR (merge bottom-up)

| PR | branch | change |
|---|---|---|
| 1 | `seats-read` | `App\Support\Seats`: every seat the viewer holds, read once, partitioned (private groups / this Saturday's rooms / played rooms / tables), lazy past the groups; `cards()` and `roomsOpen()` consume it |
| 2 | `filter-menu-navigates` | `x-filter-menu` items may carry `href` (a navigating `flux:menu.item`, `note` as suffix); `variant="hero"` for a title that clamps instead of truncating |
| 3 | `group-switcher-picks` | `x-group-switcher` above the fork on `/picks`; `docs/ui-system.md` rule 8's one exception |
| 4 | `group-switcher-clubhouse` | `x-group-hero` `title` slot; the clubhouse name is the switcher, sr-only h1 kept |
| 5 | `needs-picks-one-hero` | one hero + "and N more below"; `x-slate-row` now renders only on Home |
| 6 | `picks-two-sections` | My Groups / Week N Contests; invite code under My Groups; the one Lobby door closes the contests; card micro-line is facts; Leaderboard chip "My Groups"; Voice, rules, docs |
| 7 | `group-card-surface` | card fill + `gap-3` |

### The switcher's menu

```
All my picks                 (pass 2: "All my groups and rooms"; the trigger reads "My groups and rooms")
── My Groups ──────────
Rocky Top Rejects   (bold when current)
The Back Porch
── Week 1 Contests ────
Hail Mary
The Big Lobby            Always open
Browse the Lobby         3 open
```

The page the reader is ON is always the trigger: a lobby previewed without
a seat, or a room whose Saturday is played, is spliced in as a bare row.
A null week label (no calendar week) skips the Contests heading — the tables
and the Lobby row render bare; never a substituted week.

## Voice key manifest

All three registers; every key in the PickemVoiceTest dataset.

| Key | pg | pg13 | r |
|---|---|---|---|
| `picks.groups.subheading` (revived) | Invite-only, and the standings run all season. | Invite-only, all season. Your people, your mode, one long argument. | Invite-only, all season. The people you picked, and the receipts you can't outrun. |
| `picks.contests.subheading` (new) | This Saturday's public rooms — the ones you are seated in, and the door to the rest. | This Saturday's public rooms. One Saturday each — the ones you're in, and the Lobby for more. | This Saturday's flings: one Saturday, one verdict, no rematch — and the Lobby is always selling another. |
| `tour.seats.body` (rewritten) | Your private groups run all season; this Saturday's public rooms sit just below them. The switcher up top jumps to any of them. | Season-long groups here, this Saturday's rooms underneath — and the switcher up top jumps straight to whichever one you're playing. | The season-long grudges live here, this Saturday's flings underneath. The switcher up top gets you to whichever one is currently ruining your weekend. |

Retired: `picks.whereplay.subheading` (its only render site is gone).
Deliberately plain: "All my picks" (pass 2: "My groups and rooms" / "All my
groups and rooms"), "My Groups", "Week N Contests", "Browse
the Lobby", "N open", "Always open", "and N more below", "12 members ·
you're the commissioner".

## Feature-move ledger (nothing silently lost)

**Moves**: "Where you play" → "My Groups" + "Week N Contests"; the kind
lines → the section headings; the invite-code disclosure → under My Groups
(one unconditional site); the Lobby door → closing the contests section
(the first-run block still hoists the same partial); the hero's compact rows
→ "and N more below"; `data-tour="seats"` → the My Groups block (the
contests section for a rooms-only reader); the clubhouse `h1` → sr-only, the
name → the switcher's trigger; Leaderboard's "My groups" chip → "My Groups".

**Removed**: `whereYouPlay()`; `needsRest()`; `picks.whereplay.subheading`;
the card's "Private group, all season" / "Public room · this Saturday" lines.

**Untouched**: the first-run "Two ways to play" block byte-for-byte; the
ribbon; the you-strip; "All in"; the plate fork; Results; the ladder; Season
history; "How this works"; the pick surface; the Lobby screen.

## Follow-ups filed, not shipped

- Chrome density at 390: chips, switcher, plate, ribbon, you-strip before
  content. Candidate: fold the ribbon's dateline into the switcher row.
  (CLOSED in pass 2 by `x-week-band`: one light card carrying the dateline
  and the you-strip as sibling rows.)
- The Lobby at zero rooms says the emptiness twice (band count + callout).
- "How this works" subline is jargon to a day-one reader. (CLOSED in pass
  2: "Scoring, ranks, and what a room costs.")
- A switcher-use signal (`UxSignal` is a closed enum).
- Place-in-field on live/final group cards.

## Verification

`php artisan test --compact` on the touched files then the full suite per PR;
`vendor/bin/pint --dirty --format agent`; `npm run build`; the device harness
at `/__device?path=/picks&w=390,768&h=800[&dark=1]` and
`/__device?path=/groups/{id}…` logged in through `/__device/act-as/{user}`,
with `scrollTo({left:999}); window.scrollX === 0` in each frame; the switcher
menu opened by hand at 390 on both screens.
