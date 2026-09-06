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

## Never git stash, and never share a test database, while worktrees are active
Two collisions that look like bugs in your code and are not. Both cost real time on 2026-09-05 with parallel sessions running.

THE STASH STACK IS GLOBAL TO THE REPOSITORY, not per-worktree. `git stash` → run something → `git stash pop` will apply ANOTHER worktree's entry into your tree if they pushed one meanwhile, and two sessions did exactly that to each other. To compare against a clean base, commit to your own branch first, or copy files aside — never stash.

THE TEST DATABASE IS SHARED unless you override it. `phpunit.xml` points every run at `campusfootball_test`, and `RefreshDatabase` drops and re-migrates it, so a second run rebuilds the schema under the first. It surfaces as a flood of "Base table or view not found" and "Unknown column" errors — 85 of them in one run — which read as real failures and are not. Give each worktree its own: `DB_DATABASE=campusfootball_test_<name> php artisan test` (the env var beats phpunit.xml). A database name is an unquoted identifier, so normalize hyphens to underscores or CREATE DATABASE throws.

Symptom to recognize in both: a failure set that changes between runs of identical code, or one that names tables and columns rather than behavior.

## Pinning a fixture to an ABSOLUTE instant is not pinning it — freeze the clock instead
Read with "Pin any date a shared fixture renders" above, which this completes. An absolute fixture date is only pinned while the wall clock is behind it.

`PickemFixtures::pickemGame()` defaulted kickoff to `2026-09-05 19:30:00`. At 19:30 UTC that day the clock passed it and nineteen tests that had never travelled began reading their upcoming game as kicked — seven in GroupPageTest, three in PickemHomeTest, two each in HomeTest and PickemPulseTest, one each in ModeChangeTest and PickemPreflightTest, two erroring outright by indexing an empty collection. They failed in isolation as well as in the full suite, and no later day would have brought them back.

The fixture dates themselves are correct and must stay: `splitPickemWeek()` reproduces ESPN's real 2026 opening week (one week row spanning 8/22 → 9/8, games on two Saturdays) and a relative kickoff cannot express that. What was missing was a defined NOW to read them against. `tests/Pest.php` now travels every Feature test to `SUITE_NOW` (2026-09-02 12:00) in `beforeEach`; an explicit `travelTo()` still wins because `beforeEach` runs first.

So: a shared fixture may pin an absolute date only if the suite also pins the clock. If you add a fixture whose correctness depends on being before or after some instant, assert that relation in FactoryFixturesTest rather than trusting the calendar.

## An assertion must name something only its subject can render
A needle matched against a whole rendered document is satisfied by ANY component that prints it, not just the one under test — so the test can pass with its subject broken. Two instances on 2026-09-05, one numeric and one plain English:

`SearchTest`'s coach page compared `strpos($html,'2025')` against `strpos($html,'2024')` for tenure order. `TeamFactory` mints `color`/`alt_color` as six random hex digits, so `202412` is an ordinary draw that renders into the palette — two full-suite runs on one commit disagreed (2025 at 29981 against a 2024 at 14194, 15KB above the tenure list). It had also never guarded ordering: with a stray '2025' high in the page it passed with the rows either way round.

`GroupPageTest` asserted `assertSee('Kicked off')` to prove a room's join door had closed, and passed because `pick-card` prints that phrase on any kicked game. It would have kept passing with the fix reverted.

So: assert on something only the subject renders — a `wire:key`, a `data-` attribute, a container's inner HTML, or a Voice line no other surface uses. Scope the search to the row (`substr($html, strpos($html, 'wire:key="sr-team-61"'))`) rather than lengthening the needle. Safe needles are structural or genuinely unique; `Final`, `Live`, `Kicked off`, a year, a bare number are not. A needle that IS the subject — the dates in a date-ordering test — is fine.

No static sweep can catch this, because whether a string is unique to its subject is a judgment. The check that does: BREAK the behaviour the test names and confirm it reds. One that stays green is passing for the wrong reason, which is how both of these were found.
