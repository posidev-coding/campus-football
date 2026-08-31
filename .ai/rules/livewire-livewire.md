---
paths:
  - 'resources/views/livewire/**,app/Livewire/**'
---

# Livewire Livewire

## A #[Renderless] method suppresses the render for the whole commit
Livewire batches calls made in the same tick into ONE commit, and #[Renderless] skips the render for that commit — not just for its own call. So `$wire.begin(); $wire.set('step', 'rating')` beside a Renderless begin() moves the property and repaints NOTHING.

Chain instead: `$wire.begin().then(() => $wire.set('step', 'rating'))`, which puts the set in its own commit.

The same asymmetry bites deferred sets. `$wire.set(field, value, false)` DOES repaint a property that is BOUND to an element (wire:model); a property that only selects a server-rendered branch (a wizard's `step`) is bound to nothing, so a deferred set changes state and paints nothing. Shipped exactly that way in onboarding.blade.php: a returning guest saw the name pane while the server was already on 'rating', and one Continue validated the wrong step's rules and skipped a screen. Both look identical in a Livewire component test — only a browser at a real width shows it.
