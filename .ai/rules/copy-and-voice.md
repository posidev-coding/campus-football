---
paths:
  - 'resources/views/livewire/**'
  - 'resources/views/mail/**'
  - app/Support/Voice.php
  - 'app/Notifications/**'
  - app/Enums/ContentRating.php
  - app/Enums/TiebreakerMetric.php
  - app/Support/Cadence.php
---

# Copy and voice

Long-form reference: `docs/product.md`.

## Write all three ContentRating registers when you write the screen
PG (Mild), PG-13 (Locker Room, the default), R (Anything Goes). Copy lives in
one map in `App\Support\Voice` so all three variants sit side by side — which is
how you catch PG being written as a punishment. This is a product requirement,
not a coat of paint.

## LOUD surfaces vs PURE surfaces
LOUD — Account, Pick'em, Gamification, Groups, Notifications: anything about
YOU, your picks, your record, your rivals.
PURE — Scores and League (standings, rankings, stats, leaders, teams, players,
recruiting, news): someone checking a score wants the score, and a joke between
a reader and a fact makes the data look less trustworthy.
The line is not serious-vs-silly, it is WHOSE content it is. Chrome that frames
a factual screen (an empty state, an onboarding hint) may still have a voice.

## Roast the pick, the team, the record — never the person
This is what keeps it funny instead of a liability, and what keeps the mobile
build inside its App Store age rating.

## Never let the joke eat the instruction
If a user cannot tell what a control does after reading the funny version, the
funny version is wrong. Affordances stay plain: field labels, section headings,
format rules ("lowercase letters, numbers and underscores"), search placeholders.

## Fall DOWN the ladder, never up
`ContentRating::includes()` encodes this: an R user may see PG copy; a PG user
must never see PG-13. A line defining only `pg` is safe to add; a line defining
only `r` never reaches anyone who did not ask for it. Unknown key returns `''`.

## American spelling, everywhere
Favorite, color, center, canceled. UI copy, comments, PHPDoc, variable and
method names, tests. `game_odds.favorite_team_id` is the betting favorite and is
the column a stray "favourite" tends to sit next to.

## Example teams in copy: the reader's own, never a canned school
When copy needs a school as an example, use the reader's own first followed
team (the `tour.search.body_team` pattern: `:prefix`/`:team` replacements,
with a names-nobody fallback line for zero-team users) — a hardcoded example
school is somebody's rival. Georgia specifically must NEVER appear in
example, joke, or tour copy (the pilot audience is Tennessee alumni); it may
only reach a screen as the reader's own followed team or as live data. Where
personalization is not plumbed (Search's empty state), the static example is
Tennessee. `GuidedTourTest` sweeps tour lines for the word.

## Say TRENDS, not "form"
"Form" is the soccer word and reads as borrowed. Plural nouns read better here:
"Records, trends, next games", not "record, form, next game".

## No screen shows a visible heading except Scores
The section strip already names every other screen, so an `h1` says the same
word twice — it stays `sr-only`. Scores is the exception because it has no strip.

## A poll's guard can be the @if that renders it — and .visible is sometimes wrong
The verify callout's `wire:poll.15s` has no computed guard: the component's own `@if (unverified)` IS the "something to poll" — the row and its poll cease to exist on the verified render. `.visible` is OMITTED there on purpose, a deliberate deviation from the house shape: dismissal `display:none`s the row via x-show, and the flip must still reach a reader who waved the nudge away. Don't "fix" either by adding a guard or the modifier. The notice screen's hot `wire:poll.3s` is licensed by `mount()` redirecting verified visitors — the screen only exists while there is something to poll.

## Push permission is gesture-only, and the subscription IS the consent
The permission prompt is spent the moment it shows (denied only returns via OS settings), so every ask lives inside a real tap on a surface that says what the notifications are for — Account's device switch or Home's standalone-only nudge — never on load. There is deliberately NO push consent column: a push_subscriptions row can only exist through a grant on a device, so `whereHas('pushSubscriptions')` is the send gate and no server flag can drift from the browser's own state. `notificationclick` focusing/opening the app is the ONLY true deep link an iOS PWA has — data.url every push. sw.js's VERSION bump contract stays caching-scoped; push handlers don't touch it.

## Tiebreakers are per-week QUESTIONS, not one hardcoded criterion
The paper league rotated its tiebreaker criterion weekly ("passing yards for Auburn", "combined points, UT and LSU") and evaluated by hand; TiebreakerMetric automates it. A designation is game + metric + (team, when the metric is one-sided) on the slates row; entrants answer with one integer scaled by metric->maxPrediction(). Settlement resolves the actual from OUR data: points metrics off the games row at final, yardage metrics from box-score lines — a metric whose data has not synced resolves to NULL and settlement falls back to a shared win, never an invented number.

## The league clock lives on Cadence, resolves in ET, and is admin-configurable
Slate deadline (default Tue 23:59:59 ET) and official-final (default Sun 12:00 ET) resolve against a WEEK's own Saturday via App\Support\Cadence, overridable from the Pick'em Settings Filament page (pickem_settings, one nullable-override row — the brand pattern). Everything is Eastern wall time: travelTo() in tests speaks UTC, and 01:00 UTC Wednesday is still Tuesday night in Knoxville — a cadence test that forgets this passes the wrong branch. The slate window is Game::inSlateWindow() (Saturday, ET hour >= 12) — the time-of-day half CANNOT be asked in SQL (DST shifts the UTC boundary mid-season), so it is a per-game PHP check layered on the kickoff_day scope. pickem:publish-boards sweeps hourly past the deadline and publishes the standard slate through AutoPublishStandardSlate → PublishSlate::force (same validation, no actor gates).
