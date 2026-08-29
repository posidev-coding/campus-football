---
paths:
  - 'app/Models/Slate.php,resources/views/livewire/group.blade.php,resources/views/livewire/pickem-home.blade.php,app/Support/**'
---

# Views Livewire Support

## Read a slate by its SATURDAY — Slate::onCard(), never where('week_id')
An ESPN week can hold two Saturdays (2026 Week 1 = 8/29 and 9/5) and `slates` is unique on (contest_id, saturday), so ONE contest legitimately owns two rows in one week — any group that carried a Week 0 draft does. Every write path already keys on Cadence::activeSaturday(); the reads did not, and `where('week_id')` with no ORDER returned whichever row the engine chose: the clubhouse's `->first()` took the older draft while My Picks' `keyBy('contest_id')` kept the last (the published card), so two screens disagreed about the same week and the clubhouse said "no slate yet" over a live card. Use `Slate::query()->onCard($week)` in every read; it filters week_id AND the active Saturday, and a week with no Saturday at all matches NOTHING rather than falling back to "any slate this week" — an unidentifiable card is not a card. Verified by breaking it back: without the Saturday filter the clubhouse returns the stale draft's id.
