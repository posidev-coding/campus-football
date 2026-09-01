---
paths:
  - 'resources/views/livewire/tour.blade.php,app/Support/Tours.php,resources/views/livewire/pickem-home.blade.php'
---

# Support Views Livewire

## Two guided walks, one component — and the Picks walk needs its OWN column
`livewire/tour.blade.php` runs BOTH walks: `home` (first-run, closes on the install) and `picks` (the Tallboy economy). They differ in exactly three things — the step list, the copy those keys resolve, and the column complete() stamps. Never fork the component: the spotlight geometry is the part with the scars on it (the deferred first measure, the capture-phase scroll listener, the no-rAF rule).

TWO COLUMNS, NOT ONE. `users.picks_first_seen_at` is the ECONOMY's fact — it switches the Tallboy economy on and is what the weekly top-off hangs off. `users.picks_tour_completed_at` is the WALK's. Fold them together and a replay from Account re-triggers the first-visit grant, or a reader who waved the coach marks away looks to the economy like somebody who never arrived. PicksTourTest reds on a break-back of either.

The step lists live on `App\Support\Tours` because the component is an anonymous class inside an SFC — nothing outside can name a constant on it, and a step list nothing can read is a step list nothing can check. Blade renders the copy blocks by index and Alpine walks the spotlights by index; both now read ONE `$steps` (`keys: @js($steps)`), which is what makes a second walk safe. Adding a stop means a `[data-tour]` target AND three registers of `tour.{key}.*`, or the card renders a hole with Next under it.

One emitter per signal: the Picks walk counts `UxSignal::PicksTourDismissed`, never a second `tour_dismissed`.
