---
paths:
  - 'app/Actions/PublishSlate.php,app/Support/Cadence.php,app/Models/Slate.php,resources/views/livewire/group.blade.php,resources/views/livewire/pickem-home.blade.php'
---

# Views Livewire Views Livewire

## The practice window: counts_from decides exhibition, once, at publish
`pickem_settings.counts_from` (a DATE — an ESPN week can hold two Saturdays) is the first Saturday whose slates count; null is NO window, not a missing value, so an unconfigured league counts everything. `Cadence::isPractice()` compares plain Y-m-d strings: `slates.saturday` is a date column at UTC midnight and shifting either side through Eastern moves the boundary a day. The flag is stamped by `PublishSlate::force()` — the single door the commissioner's button, the deadline fallback and a room's spawn all publish through — and written ONCE, so moving the window later never rewrites a week people already played. THE LEDGER IS THREE JOINS that cannot call `Slate::counts()`: the clubhouse's seasonStandings, its seasonHasHistory gate, and the My Picks wins badge all add `where('slates.exhibition', false)`; change one and change them. Everything else treats a practice week as real (its own standings and winner, XP, History, the Monday payoff) and the settled notification says which it was. A query-count test that publishes must warm the clock before measuring — Cadence memoizes per request and firstOrCreate's row costs two queries to whichever run asks first.
