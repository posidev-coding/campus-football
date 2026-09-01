---
paths:
  - 'resources/views/**, resources/css/**'
---

# Css

## Sticky screen chrome offsets by --chrome-offset, the MEASURED header
SUPERSEDES the older "stick at top-[env(safe-area-inset-top)] / sm:top-[var(--header-offset)]" rule, which was wrong in any area carrying a section strip. --header-offset is the app BAR alone (h-14 + 1px + inset); the sticky <header> also contains the section strip, so chrome offsetting by it slid UNDER the strip and vanished on the first scroll — measured 2026-08-21 at 41px of overlap at 390 and 40px at 768, burying a 40px band whole on every Picks and League screen. Scores has no strip, which is why the scoreboard (where these offsets were worked out and pinned) was always fine and the bug hid.

The layout header publishes its own offsetHeight as --chrome-offset on document.documentElement (ResizeObserver + resize; on the ROOT, never the component node — a Livewire morph strips inline styles it did not render). Use ONE class at every width: top-[var(--chrome-offset)]. Never sum the strip back in — it wraps, and restyles at lg. The :root declarations are the pre-JS fallback only.

No browser test can catch a regression here (in a tab every env() is 0 and the numbers coincide on Scores), so BrandingTest pins it two ways: the header must publish the variable, and a source sweep fails if any Blade sticks against --header-offset again.

## A dropdown that NAVIGATES is still an x-filter-menu
An item may carry `href`; `filter-menu/item` renders it as a navigating flux:menu.item with wire:navigate, keyed and bolded exactly like a setting row, with `note` riding as the menu item's suffix ("3 open"). Never a second dropdown species. `x-group-switcher` is the one caller (2026-09-01): pure navigation off the host's Seats computed, no Livewire state. `variant="hero"` is the clubhouse title — currentColor like `accent`, no ring (a ring around a title reads as a button), label clamped to two lines instead of truncated. The switcher is also the one piece of chrome allowed ABOVE a plate (docs/ui-system.md rule 8).
