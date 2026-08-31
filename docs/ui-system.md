# UI system: layout, navigation, chrome and color

The design system. Breakpoints, the two levels of navigation, the shared
chrome components, team color resolution, and the layout traps that produced
them. Per-screen behavior lives in [screens.md](screens.md).

## Mobile-first, always

Design at 390px first, then widen. Every breakpoint above base is ADDITIVE —
it may add a column, a rail, or a label, but it must never be the only place
something is reachable. The bottom nav was once `@auth`-gated while the header
links were `sm:hidden`, which left a signed-out visitor on a phone with no
navigation at all; that is the failure mode to avoid.

    base   single column, bottom nav, header nav hidden
    sm     header nav appears, bottom nav retires, cards go two-up
    lg     right rail appears ALONGSIDE content, never instead of it
    xl     third card column
    max    max-w-7xl (1280px), about a 14" laptop

Capped deliberately: past ~1280px line lengths stop being readable and the page
reads as a spreadsheet stretched across a monitor. Desktop should feel like a
traditional sports site — persistent section nav, dense multi-column content,
a standing right rail — not like a phone layout centred in whitespace.

Verify with the device harness rather than a resized window; Chrome will not go
below ~600px. See below.

## Navigation is two levels, and they are not the same list

    AREAS     the bottom tab bar. A small fixed set of places the app is IN.
              Home · Scores · League · Account. They do not change as you
              move around inside one.
    SECTIONS  the scrolling strip at the top, belonging to the CURRENT area.
              League shows Standings · Rankings · Teams · Players · Stats ·
              Recruiting. Home and Scores have none.

**No screen shows a visible heading.** Recruiting was the last holdout with a
`flux:heading`; the section strip already names every League screen, so an `h1`
said the same word twice and is `sr-only` everywhere. Scores remains the one
exception, because it has no strip.

Team Stats and Player Stats were once two sections, which spent two of six
slots on one idea and made "stats" a place you had to guess at. They are one
Stats screen with a Team/Players sub-toggle now, and the freed slot went to
Players — a player index the app did not have at all.

**A section's `routes` list is what lights it on a detail page.** `player` was
in the League area's routes but belonged to no section, so a player page lit the
League tab with the entire strip unlit — you could see you were in League and
not where. Any new detail route needs adding to its section's `routes`, not just
to the area's.

Both once listed the same nine sections, which made the top strip a second copy
of the bottom bar. `App\Support\Navigation` is the single source of truth for
both — add a route to an area's `routes` array or it will not light a tab.

A tab is lit by AREA, not by URL equality: a game page keeps Scores lit and a
player page keeps League lit. Comparing `request()->url()` to the tab's own href
lights up only on the area's landing screen.

**No screen shows a visible heading except Scores.** The section strip already
names every other screen, so an `h1` said the same word twice — it stays as
`sr-only`. Scores is the exception precisely because it has no strip: bowls and
the playoff live in its week scroller, leaving it alone in its area, so it
carries a real heading with the scope filter inline beside it.

Chrome above content went from 97-197px to 32-73px at 390px.

**Below `sm` there is no top bar at all** — 56px reclaimed. That is only safe
because every header affordance has a phone equivalent: brand → Home, ⌘K →
the search bar on Home, avatar → Account. Anything added to the desktop header
must get a phone route too, or it is unreachable at 390px. Log out and Admin
live on the Account screen for exactly this reason.

Pick'em gets the fifth tab when it ships — Search gave its tab up for exactly
that. The bar sizes its columns from the area count rather than hardcoding it.

## Navigation is chips; the underline belongs to controls

The section strip (`x-section-nav`) speaks the CHIP language of the desktop
area nav (`x-area-nav`) — active section on a soft zinc chip, the rest muted
text. It used to render BYTE-IDENTICAL underlined tabs to `x-plate`, which
forced a "distinguished only by bleed" rule and a page-wide class count in
`NavigationTest` that read 2 on any screen with a plate. Now the split is
semantic: APP-LEVEL NAVIGATION (area nav, section strip, bottom bar) is chips
and color; the UNDERLINE is the in-content idiom — a reader never has to ask
whether an underlined row navigates or filters.

`ChromeConsistencyTest` allowlists `border-b-2` in exactly two files:
`plate.blade.php` and `team-nav.blade.php`. The second is the team page's own
sub nav, which wants the plate's shape — a rule reaching both edges with the
active tab's underline resting ON it — but has FIVE tabs, and the plate throws
past three deliberately (a plate is a fork in a screen, not a menu of
sections). The two never appear together: where the team nav rules a screen,
the level beneath it is pills.

**Its underline is NEUTRAL, not the team color.** `--team-accent` is the
obvious choice and is wrong on real data — the palette ladder's rung 1 leaves
a LIGHT surface behind dark text, so Colorado's gold rule would sit at 1.6:1
against the page and vanish. Making it safe would need a second contrast
ladder for a 2px line. The hero directly above already carries the brand.

**Weight does not change between active and inactive** either, in this or the
plate. Bolding the active tab reflows the row on every switch, so the labels
visibly shift as the reader moves along them; color and the underline do the
work.

Two consequences worth keeping straight:

- **The active chip classes are shared with the area nav's current tab**,
  which is `md:flex`-hidden but in the DOM on every League page. A test for
  "which section is lit" must slice the page between `aria-label="Sections"`
  and the strip's `</nav>` — counting the chip string page-wide reads 2 by
  design. `NavigationTest` does exactly that.
- **The bleed rule survives on its own merits**: chrome bleeds, a control
  inside content does not. The team page's stats toggle still bleeds because
  it is a hero-led screen whose tabs run the viewport; the League Stats
  screen's plate sits in the content column and must not.

## League chrome speaks one vocabulary

Every screen's top chrome is built from five components in
`resources/views/components/` — `filter-menu` (and its wrappers
`scope-filter` and `season-menu`), `plate`, `gutter-tabs`, `filter-bar` —
plus the existing `week-scroller`. `ChromeConsistencyTest` sweeps the views,
so inlining the old markup is a red test rather than a quiet drift. The rules
the components encode:

1. **Nothing scrolls horizontally except `x-week-scroller`, `x-section-nav`
   and Home's card swiper.** The week scroller earns it because a season's
   weeks are a spatial sequence you scrub along; the section nav because six
   sections measure 461px at 390 and navigation auto-centers its active item;
   the swiper because it is content, not a control. Every other list that
   outgrows its row goes in a menu that scrolls VERTICALLY — which is why the
   22-position filter on `/players` is a `filter-menu`, not the pill strip it
   used to be. Data tables still scroll inside their own `stat-grid`
   container; the ban is on chrome and the document.
2. **There are NO select boxes in screen chrome.** Every dropdown — scope,
   season, class, poll, position — is the same text-button-plus-menu idiom
   (`x-filter-menu`), because a boxed `flux:select` beside a text-button
   dropdown reads as two different kinds of control doing one kind of job.
   The sweep fails any `<flux:select` in a view; the one segmented control
   outside the components is Account's appearance toggle, which binds
   `$flux.appearance` through Alpine and renders identically to a gutter.
3. **WHO** (Top 25 / FBS / FCS / a conference): `x-scope-filter`, or bare
   `x-filter-menu` where a screen splits the division out. The division
   options read **"All FBS" / "All FCS"** — beside a list of conferences the
   bare acronym reads as one more league rather than the whole division.
   Standings splits the division into plate tabs instead (FBS | FCS are
   different LISTS, not a narrowing of one — almost nobody leaves FBS), whose
   tabs write `$scope` directly while its menu holds only the active
   division's conferences; a conference id still lights its division's tab,
   because `division()` looks the classification up rather than assuming.
   `$scope` speaks the same values everywhere: `fbs`, `fcs`, a conference id
   as a string.
4. **WHEN** (season, recruiting class, poll): `x-season-menu` (or a poll
   `filter-menu`), always the LAST control on its row, menu aligned `end`.
   Never a scroller; **period within a season** (weeks, poll releases) is
   always `x-week-scroller`, never a menu.
5. **`x-plate`** is the ruled "which list am I looking at" row: two tabs,
   three at the very most (the component THROWS past three), resting their
   active underline directly on the rule, with the row doubling as the shelf
   for right-aligned actions — typically the scope and season menus.
   Standings, Stats and Recruiting speak it, value-compatible
   (`team`/`players`). Its `bleed` variant now has NO caller — the team page
   was the only hero-led screen it existed for, and that screen has
   `x-team-nav`; it stays for the next one.
6. **`x-team-nav`** is the plate's shape for a hero-led screen with more tabs
   than a plate holds: bled to both edges (`-mx-4 px-4`), pulled flush under
   the hero (`-mt-5` cancelling the container's `gap-5`), one `border-b` the
   full width with the active tab's `border-b-2` resting on it. Labels are
   left-aligned with a shared gap and size to their own words — not equal
   cells, which put the widest label over its padding at 390. Team page only,
   and the level beneath it must then be pills.
7. **`x-gutter-tabs`** — the zinc track with the raised white pad — is for
   tab sets neither of those holds, and for any FILTER sitting under one:
   `shrink` drops into a flex row (roster squads, centered over content),
   `block` fills it and divides it equally (stat categories, the team page's
   stats scope). `block` runs `px-2` where `shrink` runs `px-3` — "Special
   Teams" at `px-3` sits 0.03px from clipping a three-up cell at 390, and
   five equal cells put "Schedule" 5.4px over its padding, which is what sent
   the team page's sections to `x-team-nav`. Neither scrolls; a set that
   cannot fit either way belongs in a `filter-menu`.
8. **Row order, top down**: plate or team nav → filter bar → gutter →
   content. The WHEN menu rides the plate's actions slot when one exists,
   else the filter bar's — or, on the team page, the hero.
9. **Names**: `$year`, `$q`, `$scope`, `$sort`, `$view`, `$position`;
   `$perPage` never `#[Url]`; `wire:key` prefixes are per-screen (the team
   page and `/stats` once collided on `statsview-`).

The team page's five tabs FIT at 390 instead of scrolling, and the margin is
the whole budget. Measured in the browser at a 358px row: 223.9px of labels
(Schedule 59.8, Recruits 53.0, Roster 42.4, News 35.7, Stats 33.0) plus four
20px gaps is 303.9, leaving 54px spare. That is also why the tab says
"Recruits" rather than "Recruiting", and why a sixth tab or a longer word has
to be measured before it ships — `x-team-nav` deliberately does not scroll.
Widths this marginal come from the font file (`fontTools` against
`archivo-variable-latin`) or the rendered document, never the eye.

**The team page's season menu is the ONE exception to rule 4, and it is in the
hero.** It does not fit beside those tabs: 350px of strip plus a 12px gap plus
a 52px menu is 414 in a 358px row, so it wrapped to a line of its own and cost
the screen a 32px band before any content. The hero already had 48px of unused
height beside its 80px logo, so the menu stacks under the follow button —
measured after: the strip has its row to itself at both 390 and 768, the hero
did not grow, and the document still does not scroll sideways.

That needed `x-filter-menu`'s **`accent` variant**, because the default trigger
is hardcoded `text-zinc-500` and no fixed zinc reads against 136 team colors.
`accent` sets NO color at all — it inherits `currentColor`, which is the hero's
computed text color and therefore the one pairing `TeamPalette` already proved
readable (verified on Tennessee: the trigger resolves to the hero's exact
white, 2.49:1 on the accent, identical to the follow button). It wears the same
`ring-current/50` as the Following state, so action and qualifier read as one
stack. One home at every width, deliberately — a control that sits in the hero
on a phone and beside the tabs on a laptop is two controls to learn.

Note the verification trap this turned up: stripping `.dark` from `<html>` at
runtime to "check light mode" reports a color mid-transition — the trigger read
zinc-100 against a light hero, which looks exactly like a broken inherit. Set
`localStorage['flux.appearance']` and RELOAD instead.

## Sticky offsets are measured, not hardcoded

The scoreboard's title and week strip stick as one block, and day headings stick
below it. That offset comes from Alpine measuring the block into
`--scores-chrome`, because the strip's height varies with font and the title
wraps at narrow widths — a guessed constant leaves a gap or an overlap.

Three things that measurement has to get right, each one already paid for:

**Height alone is not the offset.** The chrome is `top-0` at base but
`sm:top-14`, clearing the layout header that only exists from `sm` up. Its
resting bottom edge is `offsetHeight + getComputedStyle(el).top`. Measuring
height alone parked every day heading 56px too high from `sm` up, behind the
chrome instead of below it.

**Write it to `document.documentElement`, never to the component root.** The
server HTML carries no `style` attribute there, so Livewire's morph treats an
inline one as drift and strips it. Picking a different week wiped the variable,
`top` fell back to 0, and the headings stuck underneath the chrome. Livewire
never morphs `<html>`.

**Observe, don't just init.** A `ResizeObserver` catches the changes a window
resize never sees — the webfont swapping in, the title wrapping, the strip
gaining or losing the postseason pills. A window `resize` listener catches the
reverse: crossing `sm` changes the chrome's `top` without changing its height.

## A sticky block should have nothing to travel through

"The heading drifts up slightly when you scroll" is a sticky element resting
BELOW where it sticks. It scrolls normally until it closes that gap. Three
sources, all of them removed on the scoreboard:

- the layout container's `py-5` — cancelled with `-mt-5` on the sticky block,
  the same way `-mx-4` already cancelled its `px-4`
- the block's own `pt-1` — spacing moved INSIDE as `pt-3`, so it belongs to the
  chrome and travels with it instead of scrolling away
- **one pixel of header border.** `h-14` plus `border-b` is 57px, not 56, so a
  flat `sm:top-14` left exactly 1px of drift.
- **the whole section strip**, which is the one that actually hurt.

Below `sm` that header can be genuinely EMPTY — Scores is a single-screen area,
so the bar is `sm:flex` and the strip renders nothing, leaving an unconditional
`border-b` as a 1px rule floating at the top of the screen. It is now
`sm:border-b`, plus `border-b` at base only when sections exist.

### Stick against `--chrome-offset`, the MEASURED header (2026-08-21)

Screen chrome used to stick at `top-[env(safe-area-inset-top)]` with
`sm:top-[var(--header-offset)]`, and `--header-offset` is the app BAR — `h-14`
plus its border plus the standalone inset. The `<header>` also contains the
section strip, so in an area that has one the offset was short by exactly the
strip and every sticky block slid underneath it and disappeared on the first
scroll. Measured in a real 390px viewport: 41px of overlap at 390 and 40px at
768, against a 40px band — buried whole, at both widths.

It hid for months because **Scores has no section strip**, so the scoreboard —
where all of this was worked out and pinned — was always correct. The Lobby's
Saturday band is what surfaced it: a band nobody can see while scrolling is a
band with no job.

The header now publishes its own `offsetHeight` as `--chrome-offset` on
`document.documentElement` (`ResizeObserver` + `resize`, the `--pickem-chrome`
pattern), and every sticky offset reads that one variable at every width. The
strip's height is deliberately NOT summed back in: it wraps, and it restyles
into an underlined tab row at `lg`. The `:root` declarations are the pre-JS
fallback and reproduce the old pair exactly, so a frame before Alpine boots is
never worse than what shipped.

No browser test can see this class of regression — in a browser tab every
`env()` is 0 and the numbers coincide on Scores — so `BrandingTest` holds it
two ways: the header must publish the variable, and a SOURCE SWEEP fails if any
Blade file sticks against `--header-offset` again.

Prefer `sticky` with zero travel over `fixed`. They look identical, but `fixed`
leaves the flow and drops the page underneath it — needing a spacer the exact
height of a block whose height is variable, which is what `--scores-chrome`
exists to measure in the first place.

## `truncate` cannot clip a box that is free to grow

The symptom does not look like a text problem. The page scrolls sideways, and
because the tab bar is `fixed` and the screen chrome is `sticky` — both of which
pin to the VIEWPORT, not to the content — they stay put while everything else
travels underneath them. It reads as the nav losing its positioning and the
screen coming apart on both axes.

The cause is always the same shape. A flex or grid ITEM keeps its automatic
minimum size, which is its **min-content width**. `truncate` sets
`white-space: nowrap`, and the min-content width of unwrappable text is the
whole string. So the item grows to fit the text rather than clipping it, and
truncation never gets a constrained box to work against.

**`min-w-0` on the item is what makes `truncate` work at all.** Three live
instances, all found by measuring rather than reading:

    game card        404px in a 343px track   longest CFP bowl name
    recruit row      516px in a 343px track   high school + hometown
    conference head   select pushed off-screen  long conference name

It surfaces where the longest strings are, so the postseason and a team's
hometown find it before anything else does.

Check for it with the document, never the eye — an element's
`getBoundingClientRect()` still reports its full width inside an
`overflow-x: auto` container, so a `stat-grid` table reads as an overflow when
it is behaving exactly as intended. The real test is whether the document
actually scrolls:

    scrollTo({left: 999}); window.scrollX === 0

**In a TABLE the fix is `w-full max-w-0`, not `min-w-0`.** Same cause — a cell
sizes to its content's min-content width, and `truncate` makes that the whole
string — but a `<td>` does not respond to `min-w-0`. Zeroing the max width lets
the cell be told its size instead of asking for one, and `w-full` hands it
whatever the fixed numeric columns leave. Rankings went from an 18px inner
scroll at 390px to fitting exactly, with full team names and no ellipsis.

Three more things a dense table needs, all measured on Standings at 390px,
where six columns had been forced into a `min-w-md` horizontal scroll:

- **The HEADERS set the column widths, not the values.** "Overall" claimed 69px
  for a value needing 30. Abbreviate them and keep the full word as `sr-only`,
  so nothing is lost to a screen reader.
- **`whitespace-nowrap` on the TABLE.** Abbreviating a header makes its column
  narrower than a four-character record, so "13-0" wrapped to two lines and made
  the top three rows of every conference 6px taller than the rest — which reads
  as a rendering glitch, not as a wrap. The team cell overrides it with
  `truncate`; that is the one place text may be cut instead of wrapped.
- **`px-1.5` on the numeric columns.** Worth 24px across five of them.

Together: the team column went 108px to 158px and the names that no longer fit
went from 90 of 136 to 4, each clipping by a pixel or three.

**Say the PLACE, not the mascot, in any dense table** — "Ohio State", never
"Ohio State Buckeyes". `x-team-link label="location"` and `Team::placeName()`,
the same call the game card makes. It is the single biggest saving available,
and a table is scanned rather than read. Remember `location` in the constrained
eager load: omit it and every team silently falls back to its display name,
which reads as a design decision rather than a missing column.

## An opaque background does not win a z-index tie

Sticky headings need an OPAQUE background — `bg-white/90` with `backdrop-blur`
softens what scrolls behind but does not stop it competing. That was necessary
and NOT sufficient, and the second half looks identical to the first: team names
painting over the heading reads as "the background is gone".

A game card's inner wrapper is `relative` with `z-index: auto`, which opens **no
stacking context**. So the team rows' `relative z-10` stays in the ROOT context,
ties with the day heading's `z-10`, and wins on tree order because cards come
later in the document.

The ladder, and the rule behind it — app chrome is always above anything a
screen sticks to its own viewport:

    40   layout header, bottom tab bar      app chrome
    30   scoreboard title + week strip      screen chrome
    20   day headings
    10   game card contents

`position: relative` with `z-index: auto` creating no stacking context is the
part that surprises. Check for it before assuming a paint order is safe.

## A team logo never sits on the team's color

A one-color mark in the team's own color vanishes into an accent surface —
Tennessee's orange Power T on Tennessee orange was invisible. Two rules, both
in the glance-card header and the team-page hero:

- **The logo rides a neutral puck**: `bg-white` in light mode, `dark:bg-zinc-950`
  in dark — which also matches the logo variant `x-team-logo` picks, since
  ESPN's dark-variant logos are drawn for dark surfaces.
- **Text color on an accent is COMPUTED, never assumed.** See below.

The branding lives in the surface instead: the `team-accent` utility and a
3px `alt_color` keyline along the header's bottom edge, jersey-piping style.

**That surface is FLAT, and the utility used to be called `team-gradient`.**
It painted `linear-gradient(115deg, accent 35%, accent-far)`, where
`--team-accent-far` was the primary shifted 22% away from the text color —
darker under white text. It did not read as depth; it read as a shadow falling
across the header, which is the failure mode of any gradient subtle enough to
be tasteful: too weak to be a deliberate effect, too strong to go unnoticed.
The color itself is the branding.

So there is no second surface color anywhere now — `--team-accent-far`,
`TeamPalette::$far`, `GRADIENT_SHIFT` and `shiftAwayFrom()` are all gone, and
`TeamPaletteTest` asserts a palette has EXACTLY `surface` and `text` so nothing
can reintroduce one. The old flat `team-accent` utility was dead (defined,
never used in a single view) and its name was the right one, so it was
absorbed rather than left as a near-duplicate.

## Legibility is the floor, not the target

`App\Support\TeamPalette` picks a branded header's colors, and it took three
passes to learn what the rule actually is. A YIQ brightness rule chose
Auburn's orange on navy — **brightness difference is not contrast** (99.8
points of YIQ, 4.2:1 of ratio, with white available at 11.6:1). A strict
WCAG-4.5 rule then chose near-black on Tennessee orange — perfectly legible,
and wrong to every fan who has seen a jersey, because white-on-orange at
2.49:1 IS Tennessee. **No purely ratio-driven picker can produce a school's
actual branding.** The target is what the fan expects; the ratio is the floor.

The ladder, applied in light mode only:

    0. teams.header_style set        -> the admin picked; render it
    1. secondary vs primary >= 7.0   -> SECONDARY as text (Michigan maize,
                                        Colorado gold). A secondary must EARN
                                        text duty; Auburn's 4.2:1 does not.
    2. white vs primary     >= 2.2   -> white, the sports default — down
                                        through the mid-tone brands
                                        (Tennessee, Clemson, Miami)
    3. white vs secondary   >= 4.5   -> SECONDARY as the surface (Arizona
                                        State goes maroon)
    4. darken primary                -> last resort; zero FBS teams today

**Rung 2 was two rungs.** White above 4.5 rendered plain; white in the 2.2-4.5
band picked up a subtle dark text-shadow — the ESPN treatment, reaching 25
teams. They always chose the same COLORS and differed only in that flourish,
which is gone: a mid-tone header renders flat white, which is what the jersey
does. `TeamPalette` no longer carries a `shadow` flag and there is no
`team-text-shadow` utility; `HomeTest` asserts its ABSENCE on Tennessee, which
is what stops it creeping back. `WHITE_COMFORT` (4.5) survives as the bar a
SECONDARY must clear to be swapped in as the surface, not as a text rule.

Near-black text exists ONLY behind the explicit `dark-text` override. A palette
resolves exactly two colors, and `--team-accent`, `--team-accent-contrast` and
`--team-keyline` are set per surface.

**`teams.header_style` is the admin override** — a Filament "Team Branding"
page with presets only (Auto / white / secondary-text / secondary-surface /
dark-text), because the last few percent of taste cannot be computed and a
preset cannot be configured unreadable. It is not in the sync payload, so
ESPN can never clobber a curated choice.

**Dark mode is NEUTRAL — the palette is a light-mode concern.** Under `.dark`
the `team-accent`, `team-invert` and `team-keyline`
utilities un-brand themselves: page-dark surface, no logo puck,
no keyline, zinc text, neutral buttons, and `x-team-logo`'s dark-mode mark
sits directly on the page. A brand color block on a dark theme was the harder
half of every contrast fight, so it no longer exists.

**A control ON the accent must draw its colors FROM it** — the follow button
uses the `team-invert` utility (the hero's text color as fill, the accent as
label), in CSS rather than an inline style precisely so dark mode can
neutralize it.

**Verifying that a color was APPLIED is not verifying that it is READABLE.**
The browser probe that "confirmed" Tennessee checked which variable was set,
never the ratio, so a 2.49:1 regression passed review twice. Read the computed
`color` and `background-color` and compute the ratio, and sweep all 136 teams
rather than spot-checking one.

## Prefer Bootstrap Icons

Reach for [Bootstrap Icons](https://icons.getbootstrap.com) first. They are
16px FILLED paths — no stroke — so they sit lighter than Lucide's 2px outlines,
which read as heavy next to everything else on a dense screen.

`php artisan flux:icon` imports from Lucide ONLY. Bootstrap ones are added by
hand, which is fine because a Flux icon is just a Blade file in
`resources/views/flux/icon/` following a small contract — see
`pin-angle.blade.php` for the shape. Credit the source in a comment; Bootstrap
Icons are MIT.

Two things that shape how they are used:

- **`variant` controls SIZE only.** Bootstrap ships outline and filled as
  separate icons, not variants of one, so a filled state selects a different
  component (`pin-angle-fill`) rather than passing `variant="solid"`.
- **Pass them as a CHILD, never through `icon="..."`.** That prop resolves
  against Flux's own set and falls back silently when the name is not in it, so
  a button renders a stroked 24px glyph while `flux:icon.pin-angle` on its own
  renders the Bootstrap one. As a child it is unambiguous, and its colour can be
  set directly instead of being fought past the button's own `text-*`.

Heroicons stay in place where they are already used and where the set has a good
match — this is a preference for new work, not a migration.

## Naming a font in `@theme` does not load it

`--font-sans` sat in `app.css` naming a family that was never fetched, so the
whole app rendered in system-ui and looked merely "a bit off" rather than
broken. `@vite` does NOT emit font faces; the layout needs `@fonts`, in BOTH
`layouts/app` and `layouts/auth`.

The face is Archivo, self-hosted as a real VARIABLE font — one 35 KB file whose
`font-weight: 100 900` covers `font-thin` through `font-black`. Two dead ends
before that, both worth not repeating:

- **bunny/google css2 have no variable Archivo.** `wght@100..900` is accepted
  and silently returns the same nine static cuts, so a full range would be
  eighteen downloads.
- **`fontsource()` cannot resolve a variable package.** It matches subset files
  by `-{subset}-{weight}-{style}`, which never matches
  `archivo-latin-wght-normal` whose weight parses as the string "100 900". It
  throws at build time.

So the woff2 is checked into `resources/fonts/` and declared with `local()` at
weight `'100 900'`. Only the `latin` subset: verified, not assumed — zero of
34,836 athlete names use a character outside Latin-1.

## Rebuild assets after touching Blade

Tailwind 4 only emits utilities it finds in source. Adding a class to a Blade
file and NOT running `npm run build` means that class silently does nothing —
and it fails in a way that looks like a design bug, not a build one. A missing
`size-14` rendered a 500px team logo at full size; missing `w-28` made inline
selects stack full-width; the custom `@utility team-accent` and `stat-grid` were
absent entirely because nothing used them at the previous build.

    npm run build     # after any new utility class, always

## Appearance lives on Account, and Flux owns the mechanism

Light / Dark / System is a segmented `flux:radio.group` bound to
`x-model="$flux.appearance"`. Flux's store already does the four things a
hand-rolled toggle gets wrong: writes `.dark` on `<html>`, persists the choice,
honours the OS setting under "System", and keeps listening for OS changes after
load rather than freezing at page render. `@fluxAppearance` must stay in BOTH
layouts for it.

It sits **in Account's sticky heading**, floated right as three icon-only
segments — the labels were the widest thing on the screen and said less than the
icons do. Account for the same reason Log out and Admin are there: below `sm`
there is no header, so a control that only exists in the desktop avatar dropdown
is unreachable on a phone.

That heading is sticky on the same offset as the scoreboard's chrome — `-mt-5`
to cancel the container's `py-5`, and `top-[var(--chrome-offset)]` for the
header's measured height — so it rests exactly where it sticks rather than
drifting on the first scroll.

The choice is per-BROWSER, not per-account — it is in localStorage, not on
`users`. Fine for now; syncing across devices would need a column and a write on
every toggle.

**`theme-color` has to be kept in step.** It was hardcoded `#09090b`, so picking
Light left a phone's address bar black. An `x-effect` in `<body>` re-tints it
from `$flux.dark`. It cannot go on the meta tag itself — Alpine only initialises
inside `<body>`, so `x-data` in `<head>` is never picked up.

## `wire:sort` takes a bare METHOD NAME, never a call expression

`wire:sort="reorder($item, $position)"` looks more explicit and sends NULLs.
Livewire's `contextualizeExpression()` rewrites every identifier that is not in
the element's own Alpine scope to `$wire.<ident>` — and the `$item`/`$position`
magics arrive as an evaluator OPTION, not as element scope, so they are
rewritten too. The call became `$wire.reorder($wire.$item, $wire.$position)`,
both `undefined`, and the server rejected a null team id with
"Argument #1 ($teamId) must be of type int, null given".

Correct is `wire:sort="reorder"`; Livewire passes the moved item and its new
**0-based** index itself. Two things this cost:

- **Only a real pointer drag reaches it.** SortableJS ignores synthetic
  pointer and mouse events, so no automated interaction test can reproduce
  it — `AlpineExpressionsTest` asserts the rendered ATTRIBUTE is a bare method
  name instead, which is the only layer a test can hold.
- The item id arrives as a STRING (`_x_sort_key` is whatever the attribute
  held), which PHP coerces for an `int` parameter. Fine for numeric ids, and
  worth knowing before typing a handler `string`.

## Reordering needs a FLIP, and it must use `animate()`

The followed-teams list puts the pinned team first, so pinning a lower row
reorders it. Order is not an animatable CSS property, so a Tailwind transition
cannot do this on its own — the list just snaps.

The fix is a FLIP: record each row's offset BEFORE the click goes out (capture
phase, so nothing has moved yet), then once Livewire has reordered the DOM, put
each row back where it was and let it travel to where it now belongs.

Two things that bit, both from Livewire's morph:

- **Consume the captured positions.** The MutationObserver fires more than once
  per update. A second pass measures a row that is already mid-flight, reads a
  delta of zero, and returns early — leaving the row frozen at its full offset.
- **Use `element.animate()`, not a transform cleared on the next frame.** The
  morph can replace a row between setting the transform and the frame that
  clears it, so the cleanup runs against a detached node and the transform is
  stranded in the inline style forever. `animate()` leaves no inline style at
  all, so there is nothing to strand.

Verify the END state, not the tween — animations do not advance in an automated
tab (`currentTime` stays 0). Call `getAnimations().forEach(a => a.finish())` and
assert the transforms are `none` and no `style` attribute survives.

## An Alpine expression that starts with a comment never runs

Alpine compiles a directive as `__self.result = <expr>` and only wraps it in an
IIFE when the expression STARTS with `let`/`const` (the regex is in the
vendored bundle). Home's swiper opened its `x-init` with a block comment, so
the heuristic missed it, `result = const io = …` was a SyntaxError, and the
whole directive silently never ran: no IntersectionObserver, `active` frozen at
0, dots that never tracked a swipe.

Nothing throws where you are looking — the feature is not broken, it is INERT.
Put any multi-statement body in an `x-data` METHOD, where declarations and
comments are both legal, and leave a plain call in `x-init`.
`AlpineExpressionsTest` sweeps every `x-init`/`x-effect` in the views for the
shape.

Keep the call on the element that owns the `x-ref` it needs. Alpine walks the
tree top-down, so a parent's `x-init` fires before its children register their
refs; on the element itself, `ref` is ordered before `init`.

## Verifying responsive layout

Chrome will not size a window below ~600px, so asking it to resize to 390px is
silently clamped and every media query below `sm` evaluates wrong. An iframe has
no such floor. A local-only harness renders the app at exact device widths:

    /__device?path=/scoreboard&w=390,768&h=800[&dark=1]

Registered inside an `app()->isLocal()` guard, so it does not exist in
production. Use it rather than trusting a resized window.

**The automated tab produces NO rendering frames at all** — measured:
`requestAnimationFrame` never fires and `IntersectionObserver` never delivers
an entry, which also means `scrollTo` with smooth behavior never moves. This
generalizes the FLIP lesson ("animations do not advance"). Verify
frame-driven behavior by driving the reactive END state — set the Alpine
property directly and assert what it toggles — and scroll with
`behavior: 'instant'`; the trigger itself only fires on a real device.

## Standalone has no browser chrome — every dead end needs a built-in exit

Installed to a home screen, the app runs with no back button, no address bar
and no reload control. Three consequences, each of which shipped as its own
fix; undoing any of them re-opens a trap.

**Escape hatches.** The auth layout floats a depth-aware Back control over
every auth screen (login, register, both password flows, verify, confirm) —
the same idiom as the game scorebug's Back: `window.cfbAppDepth > 1` walks our
own history, anything else lands on Home, because a cold launch straight onto
`/login` has nothing behind it and `history.back()` would exit the app. The
depth counter lives in `partials/head` beside the standalone stamp, not in a
layout: defined by only one layout it reset to `undefined` whenever a cold
load landed on the other, and Back fell back to Home even with real history
behind it. The `errors/` pages (404 · 403 · 419 · 500 · 503) exist for the
same reason — the framework defaults are chrome-less dead ends. They follow
the offline page's contract: self-contained (an error page that can itself
error collapses to the bare framework screen, so `Brand` reads are
`rescue()`d down to the shipped constants), static PG copy (Voice reads the
session, and the session may be the broken part), and every page ends in a
way out. 419 matters most here: a session that sat on a home screen for days,
then submitted the plain logout POST, used to strand the reader on Laravel's
"Page Expired". `PwaTest` pins all of it.

**Pull-to-refresh** (`components/pull-to-refresh`, app layout only — a stray
pull on an auth form would eat a half-typed password). Polling keeps a live
game honest on its own; the gesture exists because every native app trained
the hand to pull anyway, and a pull that does nothing reads as a frozen app.
It engages only in standalone — BOTH signals, media query plus
`navigator.standalone` — because in a browser tab the browser's own
pull-to-refresh must keep winning. Every listener is passive and nothing is
`preventDefault()`ed, so scrolling never pays for it; on iOS the rubber band
stretches under the puck, which is where a native refresh control rides
anyway. An 8px axis lock leaves Home's swiper and the week scroller owning
horizontal drags, and a pull that starts inside a `dialog`, an open popover
or any inner scroller belongs to that surface, not the page. Release past
the threshold hands over a REAL `location.reload()` — fresh HTML, fresh
assets after a deploy, a fresh CSRF token — not a Livewire poke. The puck is
the WHOLE refresh experience: the boot-splash stamp skips `reload`
navigations, so the snap's accent spin hands over to the fresh page with no
launch curtain in between (user decision, 2026-08-23 — the curtain on every
pull read as the app relaunching).

**The zoom lock.** iOS auto-zooms any focused input under 16px, and in
standalone there is no chrome to un-zoom with: after adding a team from
Home's swiper the app sat slightly enlarged and scrolling sideways,
permanently. `maximum-scale=1, user-scalable=no` in the shared head retires
the focus zoom everywhere and pinch inside the installed app, while a browser
tab on iOS deliberately ignores `user-scalable=no` and keeps accessibility
pinch — exactly the split we want. `touch-action: manipulation` on `html` is
the other half: double-tap is two taps, never a zoom. Do not "fix" a zoom
complaint by loosening the meta; the complaint the lock answers is worse.

### The boot splash: stylesheet-shown before Alpine exists

Opening the installed app plays a ~2.9s branded curtain — the signup splash's
visual grammar (forced-dark `bg-zinc-950`, crossfading phrases, pulsing dots)
at launch length, dealing two shuffled cards off the `splash.boot.*` deck. It
is pure theater over an already-delivered document, and deliberate: instantly
is indistinguishable from abruptly.

**Two cards, not three** (2026-08-31, CFB-33). Three inside the same hold gave
each phrase 750ms, of which a 400ms crossfade was still resolving — the deck
was dealt faster than it could be read, and a joke nobody finishes is worse
than no joke. Two at 1400ms read, and shuffling two off a SIX-card deck is
what keeps a launch seen hundreds of times from going static. The hold is
allowed to move a little (2200 → 2900) but not to grow: a launch beat is not a
milestone, and a third card back costs seconds rather than splitting the ones
there are.

The same pass gave the curtain the flare it was missing. It wears the stacked
`brand.lockup` at `lg` rather than a bare mark — the pre-Alpine paint is what
reads as a native launch, so it is the frame that should say the app's name —
over `.cfb-boot-glow`, a Lager radial wash mixed through `color-mix` so App
Branding retints it with everything else. The glow rides a `-z-10` child:
the curtain's own `z-50` opens a stacking context, so it lands over
`bg-zinc-950` and under every in-flow child with no sibling needing
`relative`. The lockup's entrance is `motion-safe:animate-boot-rise`, a
`from`-only keyframe on the `cfb-entry-in` contract — a tab that renders no
frames, and a reader who asked for less motion, both simply see it arrive.
The phrase went `text-sm` → `text-lg` in a two-line `h-16` slot with each span
FILLING the slot: the R deck writes sentences ("Untangling whatever the
coordinator did to the headsets..."), and a slot that grew to fit one would
walk the lockup up the screen mid-beat.

The lifecycle is the part that cannot be rediscovered by reading one file.
A pre-paint head script stamps `data-boot` on the root ONLY when
`window.cfbAppDepth === undefined` (true on real document loads alone — the
depth counter defined lower in the head already exists on any navigate-hop
re-evaluation) AND a standalone signal is present. The stylesheet — never JS,
the install-banner lesson — displays `[data-boot-splash]` under that
attribute, so the curtain is up before Alpine boots; the component's `end()`
removes the attribute ~2.7s later, and an 8s CSS `cfb-boot-bail` animation is
the dead-man for a boot where JS never ran (standalone has no reload chrome,
so a curtain JS never clears must clear itself). The stamp also consults
`performance.getEntriesByType('navigation')[0]?.type`: a `reload` never
stamps — in standalone there is no reload chrome, so `reload` is a
near-exact proxy for pull-to-refresh, whose spinner puck is that gesture's
whole experience (user decision, 2026-08-23). Cold open, re-open, a
notification deep-link and the post-onboarding redirect all arrive as
`navigate`/`back_forward` and still stamp; the `?.` fails OPEN, so an engine
without the entry behaves as a launch. Hops can neither stamp nor inherit.
Measured while verifying: `Livewire.navigate` refreshes the `<html>`
element's attributes, so a mid-session stamp does not survive a hop — the
real flow never hits this (the opaque curtain blocks navigation while it
plays), but a console repro must drive `begin()` directly rather than
stamping and navigating. The splash renders LAST in `<body>` in both layouts:
an opaque background does not win a z-index tie, later DOM does, which is
what puts it over the tour scrim and the pull-to-refresh puck at the same
z-50. `BootSplashTest` pins the stamp, the CSS gate, the timing literals, the
two-card deal and the flare.

Beside `data-install-only` there is now the mirror, `data-standalone-only` —
chrome that only makes sense INSIDE the installed app (the push nudge, the
verified landing's in-app body). Inverted construction on purpose: it hides
when NEITHER standalone signal is present rather than forcing a display type
when one is, so a flex row and a block body wear the same attribute and keep
their own layout.
