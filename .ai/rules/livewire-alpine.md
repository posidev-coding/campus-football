---
paths:
  - app/Livewire/**
  - resources/views/livewire/**
  - resources/views/components/**
---

# Livewire and Alpine

Long-form reference: `docs/screens.md` and `docs/ui-system.md`.

## Lazy loading is disabled — a missing eager load is a 500
And a constrained eager load must include the route key plus every column the
view reads. `with('team:id,name')` omits `slug` and `location`: the first fails
`route('team', $team)` with "missing required parameter", the second silently
renders the wrong name. A fixture with no rows never reaches the render path.

## An Alpine expression that starts with a comment never runs
Alpine only wraps a directive in an IIFE when it STARTS with `let`/`const`, so a
leading comment makes the whole body a SyntaxError — the feature is not broken,
it is INERT, and nothing throws where you are looking. Put multi-statement
bodies in an `x-data` METHOD and leave a plain call in `x-init`.
`AlpineExpressionsTest` sweeps for the shape.

## wire:sort takes a bare METHOD NAME
`wire:sort="reorder"`, never `reorder($item, $position)` — Livewire rewrites
those magics to `$wire.$item`/`$wire.$position`, both undefined. It passes the
moved item and its new **0-based** index itself, and the id arrives as a STRING.

## Reordering needs a FLIP, and it must use element.animate()
Order is not animatable. Record offsets in the capture phase, then animate from
the old position. Use `animate()` rather than a transform cleared next frame —
the morph can replace the row and strand the inline style forever. Consume the
captured positions; the MutationObserver fires more than once per update.

## Every repeated step or row needs its own wire:key
Without one the morph reuses the node — an onboarding keystroke landed on the
previous field because step two inherited step one's binding.

## Normalize a #[Url] property in BOTH mount() and its updated hook
`#[Url]` hydrates from the querystring without firing the update hook, so a
bookmarked `?sort=nonsense` reaches the query builder as a column name.

## Livewire's <!--[if BLOCK]--> markers ride inside a slot's string
Casting a slot to a string and echoing it through `{{ }}` escapes them into
visible text. Strip first; a test must assert the absence of the ESCAPED form.

## Read expensive relations only when the active tab shows them
Gate the computed on `$tab`. `GamePageTest` asserts the recap tab issues zero
`game_drives` reads.

## Poll only while there is something to poll
`wire:poll.30s.visible`, gated on a game actually being live, and reading only
our own database — never a feed.
