---
paths:
  - 'app/Filament/**'
---

# App Filament

## A sidebar badge is a queue somebody empties, not a row count
A Filament resource earns a navigation badge only if all three hold: the thing it counts has a completion stamp (Feedback's `handled_at`), a person can clear items by hand (a "Mark handled" record action), and the resource OPENS on the set the badge counts — Feedback's Waiting filter is `->default(false)`, so tapping the badge lands on exactly those rows. A badge that is not clickable-through is decoration, and decoration everywhere means nobody reads the one that matters.

The badge must be `null` on an empty queue, never `'0'` — a zero invents a chore. `PanelPolishTest` asserts on which resources OVERRIDE `getNavigationBadge()`, plus null-at-empty, plus that each counts the waiting pile rather than the whole table; assert on the override, never on the current value, or a quiet database passes it for the wrong reason.

Two badges across seventeen navigable resources (Workbook, Feedback) is the current state and the bar for a third is high. Settled 2026-09-05 on CFB-57, after PR #130 added the second one and reddened the test for a month.
