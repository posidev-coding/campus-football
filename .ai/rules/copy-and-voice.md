---
paths:
  - resources/views/livewire/**
  - resources/views/mail/**
  - app/Support/Voice.php
  - app/Notifications/**
  - app/Enums/ContentRating.php
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
