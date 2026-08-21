---
paths:
  - resources/views/**
  - resources/css/**
---

# Blade, layout and chrome

Long-form reference: `docs/ui-system.md` (system) and `docs/screens.md` (per screen).

## Rebuild assets after touching Blade
Tailwind 4 only emits utilities it finds in source. A new class silently does
nothing until `npm run build` — and it fails looking like a design bug.

## Mobile-first, always
Design at 390px, then widen. Every breakpoint above base is ADDITIVE: it may add
a column, a rail or a label, but must never be the only place something is
reachable. Below `sm` there is no top bar, so anything added to the desktop
header needs a phone route too.

## Verify at real device widths, not a resized window
Chrome will not size below ~600px, so every query below `sm` evaluates wrong.
Use `/__device?path=/scoreboard&w=390,768&h=800[&dark=1]`.

## truncate cannot clip a box that is free to grow
A flex/grid item keeps its min-content width, which for nowrap text is the whole
string — so it grows instead of clipping and the document scrolls sideways,
which reads as the nav coming apart. Add `min-w-0` to the item; in a TABLE cell
use `w-full max-w-0`, which `min-w-0` does not fix. Check by scrolling the
document, not by eye: `scrollTo({left:999}); window.scrollX === 0`.

## An opaque background does not win a z-index tie
`position: relative` with `z-index: auto` opens no stacking context, so children
tie in the root context and later DOM wins. The ladder: 40 app chrome, 30 screen
chrome, 20 day headings, 10 card contents.

## backdrop-filter, transform and sticky are containing blocks for fixed children
A `fixed inset-0` panel inside any of them resolves against the parent, not the
viewport — full-screen search once opened as a 390x32 strip. Note `sticky`
always creates a stacking context even at `z-index: auto`; `relative` does not.

## A sticky block must have nothing to travel through
Cancel the container's padding (`-mt-5`), move inner spacing inside the sticky
element, and remember the header is `h-14` PLUS a 1px border —
`sm:top-[calc(var(--spacing)*14+1px)]`. Measure offsets into a CSS variable on
`document.documentElement`, never on the component root (Livewire's morph
strips inline styles it did not render).

## Screen chrome speaks one vocabulary
Build from `filter-menu` / `scope-filter` / `season-menu`, `plate`,
`gutter-tabs`, `filter-bar`, `week-scroller`. No `<flux:select>` in a screen.
Nothing scrolls horizontally except the week scroller, the section nav and
Home's swiper. Navigation is chips; the underline is the in-content idiom.
`ChromeConsistencyTest` sweeps for violations.

## Say the PLACE, not the mascot, in dense lists
`x-team-link label="location"` / `Team::placeName()`. Include `location` (and
the route key) in every constrained eager load, or teams silently fall back to
the display name.

## A team logo never sits on the team's color
Logos ride a neutral puck. Text on an accent is COMPUTED by `TeamPalette`, never
assumed — and verifying that a color was APPLIED is not verifying it is
READABLE. Dark mode un-brands: the palette is a light-mode concern.

## Prefer Bootstrap Icons, passed as a child
`icon="..."` resolves against Flux's own set and falls back silently. Pass
`<flux:icon.pin-angle />` as a child instead. `variant` controls SIZE only.

## Sticky chrome offsets ride the measured header and the top safe-area inset
The layout header pads `env(safe-area-inset-top)` (the standalone status-bar veil), and `:root` defines `--header-offset` = h-14 + 1px border + that inset. SUPERSEDED for the offset itself — see `.ai/rules/css.md`: screen chrome sticks at `top-[var(--chrome-offset)]`, the header's MEASURED height, because `--header-offset` is the app bar alone and left chrome buried under the section strip in every Picks and League screen. Never a hardcoded `top-0` or `top-14` sum, and never the summed `--header-offset`. In a browser tab every env() is 0, so no browser test can see a regression; the class strings are pinned in BrandingTest/ScoreboardTest/SearchTest instead. Chrome selling the install wears `data-install-only`, removed inside the installed app by stylesheet on BOTH standalone signals: the `display-mode: standalone` media query (manifest-driven installs) and `:root[data-standalone]` (iOS meta-driven web clips report `browser` in the media query and only set `navigator.standalone`, which a pre-paint head script stamps onto the root). Never hide it with x-show alone — that flashes the pitch before Alpine boots.

## Standalone strips browser chrome — every dead end keeps a built-in exit
The installed app has no back button, address bar or reload, so exits are product surface, not decoration. The auth layout's Back rides `window.cfbAppDepth` (defined in partials/head, shared so a cold load on EITHER layout counts); every errors/* page is self-contained (Brand reads rescue()d to constants, static PG copy) and ends in a home link or reload. Pull-to-refresh (components/pull-to-refresh) engages only on BOTH standalone signals, stays passive/never preventDefault(), axis-locks away from the swiper, and hands over a real location.reload(). The viewport meta is zoom-locked (`maximum-scale=1, user-scalable=no` + `touch-action: manipulation`) because iOS focus auto-zoom left standalone permanently enlarged and side-scrolling — never loosen it to answer a zoom complaint. PwaTest pins all of this; long-form why in docs/ui-system.md.

## The boot splash is stylesheet-shown pre-Alpine and must self-clear without JS
`data-boot` is stamped by a pre-paint head script ONLY when `window.cfbAppDepth === undefined` (real document loads — the depth counter already exists on any hop re-evaluation) and a standalone signal is present; the stylesheet displays `[data-boot-splash]` under it, `end()` un-stamps at ~2.7s, and the 8s `cfb-boot-bail` CSS animation is the dead-man for dead JS. Never gate the curtain on x-show/x-cloak alone, and never rely on a mid-session stamp surviving `Livewire.navigate` — it refreshes `<html>` attributes (a console repro drives `begin()` directly). `data-standalone-only` is the deliberate mirror of `data-install-only` and HIDES when neither signal is present rather than forcing a display type, so any layout can wear it. BootSplashTest pins the timings.
