---
paths:
  - tests/**
---

# Testing

Every change must be programmatically tested. Do not write verification scripts
or use tinker in place of a test. Do not delete tests without approval.

## Pin any date a shared fixture renders
`GameFactory` defaults `kickoff_at` to a random window, so a `beforeEach` game
landed on an upcoming Saturday about one run in seven and was counted by a
sibling test asserting exactly one slate-eligible game. It passed under
`--filter` and failed in the full suite, because the faker sequence differs.

## Pin the unpinned columns too, not just dates
`TeamFactory` mints a random `alt_color`, which drives the `TeamPalette` ladder
and changes which hex strings a page renders from run to run. `abbreviation` is
derived from the faker city. An `assertDontSee` over a random fixture is one
coin flip from a red suite.

## Pin a time-dependent fixture to the TIGHTEST window
A game log fetched "an hour ago" is fresh Sunday to Friday and stale on
Saturday, where the poll window is 15 minutes — so it failed one day a week.

## The automated tab produces NO rendering frames
`requestAnimationFrame` never fires, `IntersectionObserver` delivers no entries,
and smooth `scrollTo` never moves. Drive the reactive END state and assert what
it toggles; call `getAnimations().forEach(a => a.finish())` and assert the
transforms are gone. The scroll trigger itself only fires on a real device.

## Sequential calls are not concurrent viewers
A test that calls a service three times in a row and asserts one request proves
nothing about concurrency, and pins the bug in place. Assert through the JOB,
where the guarantee actually lives.

## Verify a default-season or wrong-default fix by breaking it back
That class of test passes for the wrong reason more often than not — the
fixture has to place "now" inside a season that is scheduled but unplayed, and
that needs real games. Revert the fix and confirm the test actually fails.

## Test through the layer a test can hold
SortableJS ignores synthetic pointer events, so no interaction test can
reproduce a `wire:sort` bug — assert the rendered ATTRIBUTE instead. Widget
content is not in its page's HTML — target the widget class.

## Storage::fake() cannot test a URL
It replaces the disk definition with a local one, taking the configured public
URL with it. Configure a bucket-shaped disk with dummy credentials instead.

## Flush static memoization between tests
`TeamGlance` memoizes on top of the cache in a static property that outlives the
application; `tests/Pest.php` flushes it in `beforeEach`, year memo included.

## Livewire's asset injector leaks across the test process
Once any Livewire::test() has run in the Pest process, Livewire injects `<script src="/livewire/livewire.js">` into every later full-page HTML response — including plain Route::view pages that render no component. A test asserting a page has no script tags therefore reports the PREVIOUS test, passes under --filter, and fails in the full suite. Assert what the page must contain (inline `<style>`, no `/build/` reference) rather than the absence of scripts; a real request to a component-free page gets no injection.

## No feature test can catch a missing eager load
`Model::preventLazyLoading()` is on in testing (the static reads true), but the PER-INSTANCE `$preventsLazyLoading` flag on a model retrieved during a test is false, so an unloaded relation resolves silently and only throws in dev/production. A `<x-game-card>` in a new rail panel shipped a 500 on /rankings through a fully green suite. Two consequences: a fixture whose FK is null (GameFactory leaves `venue_id` null) hides it twice over, and a render assertion proves nothing. Guard this class of bug with a SOURCE sweep asserting the query loads what the view reads, the way RailTest does.

## withoutLazyLoading() applies to the NEXT component only
A `#[Lazy]` Livewire component (every Pulse dashboard card is one) renders only a skeleton under `Livewire::test()` — the page returns 200, the card's `render()` never runs, and the test is green over code that fatals in a browser. Same family as "widget content is not in its page's HTML".

`Livewire::withoutLazyLoading()` fixes it, but it applies to the NEXT component only. Called once in a `beforeEach`, the second render silently falls back to the `animate-pulse` placeholder — and for anything cached, the SECOND render is the one that matters, because the first returns the closure's own value and never round-trips the store. Call it before EVERY render, and render twice.

This is how the Pulse dashboard shipped broken through a green suite: `assertOk()` on `/pulse` passed while all nine cards fataled on `__PHP_Incomplete_Class`.
