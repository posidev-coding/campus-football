---
paths:
  - 'app/Actions/RecordUxEvent.php,app/Support/TelemetrySnapshot.php,app/Enums/UxSignal.php'
---

# Enums

## A new funnel signal reads zero before it ships — read `funnel` against `funnel_since`
The snapshot's `funnel` is a seven-day total, but a signal added this week has counted only since it deployed. `onboarding_credentials_reached` merged 2026-08-31, deployed into a window whose `onboarding_opened` was already at 163, and read 0 — filed as CFB-48, "the wizard loses everybody", when a browser walk at 375px reached the credentials pane on every path (cold load, wire:navigate hop, returning draft) and moved the counter each time. Neither half was broken; the instrument had no denominator in days. The rollup now writes a row for EVERY UxSignal case on each finished day, zero included (a true count on a day the code was counting, never a backfill — docs/product.md rules that out), and the snapshot's `funnel_since` reports the earliest row per signal, or today when there is none. Compare a total against its own date, never against the window; do not file a zero on a signal whose `since` is inside the window. Adding a signal costs nothing extra — the zero rows come from the enum.
