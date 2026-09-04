---
paths:
  - 'resources/views/components/layouts/**,resources/js/app.js,app/Providers/Filament/**,resources/views/filament/**'
---

# Views Filament

## "$flux is not defined" beside "fluxModal is not defined" means flux.js did not run before Alpine started
Flux registers `$flux`, `fluxModal` and every other Alpine hook on `alpine:init` and nowhere else, so both ReferenceErrors at once (the layout's theme-color effect and the search palette throw on the same tick) mean one thing: Livewire started Alpine and flux.js had not executed. Two ways in. (1) The bundle failed to load — a resource error fires on the element and never bubbles, so app.js reports it from a CAPTURE-phase listener as "Failed to load script <url>"; read the next window's errors.client for that line beside the pair before chasing screens (CFB-46 was filed against Home, then the Lobby, for an asset). (2) A wire:navigate hop FROM a Livewire page without Flux (the Filament admin, Pulse) INTO one with it: Livewire skips a navigate-once body script only when the previous body carried the same tag, so flux.js would run after alpine:init has already fired and register nothing. The admin panel therefore has no ->spa() and crosses on plain anchors; FluxBootTest pins that, and that @fluxScripts precedes Livewire's injected tag on both layouts. Do not guard call sites with typeof checks. A cold load, a navigate hop and Back/Forward were all verified clean at 390 and 1024 on 2026-09-03.
