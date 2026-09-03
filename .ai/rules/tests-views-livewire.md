---
paths:
  - 'tests/**,resources/views/livewire/onboarding.blade.php'
---

# Tests Views Livewire

## Alpine transitions never finish in the automated tab — polyfill requestAnimationFrame before reading an x-show end state
Alpine's x-transition steps from its start frame to its end frame inside requestAnimationFrame, which the automated browser tab never fires while the pane is hidden. So an `x-show` overlay with `x-transition` reports its Alpine state as open while the element stays `display: none; opacity: 0` forever — which looked exactly like "the onboarding wizard vanishes after Continue" during CFB-48 and was nothing of the kind. Before driving a transitioned element's end state, set `window.requestAnimationFrame = cb => setTimeout(() => cb(performance.now()), 16)` on the page, then assert computed display/opacity and the element's rect, not `Alpine.$data(...).open`. Same family as the "verify the END state, not the animation" rule; this is the Alpine-specific mechanism. Also: reading a pane from the DOM (`wire:key`) proves the server advanced, not that the reader can see it — check the rect of the button they would have to tap. Clear `localStorage['cfb.signup']` between wizard walks: the draft's `step: 'rating'` makes resume() do a live set, and one Continue then reads as two steps.
