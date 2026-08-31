---
paths:
  - 'resources/views/components/boot-splash.blade.php,resources/css/app.css'
---

# Components Css

## The boot splash deals TWO cards at 1400ms, and never grows to fit a third
Amends the "boot splash is stylesheet-shown pre-Alpine" rule's numbers (CFB-33, 2026-08-31): the deck is TWO cards at 1400ms each and end() un-stamps at ~2.9s, not three at 750ms over ~2.7s. Three inside the old hold gave each phrase 750ms of which a 400ms crossfade was still resolving, so the deck was dealt faster than it could be read. Shuffling two off the SIX-card pool is what keeps a launch seen hundreds of times from going static — a third card back costs seconds, it does not split the ones there are. Everything else in that rule stands: the pre-paint stamp, the CSS gate, the 8s cfb-boot-bail dead-man.

The flare is stylesheet-side on purpose, because the curtain is up before Alpine and must look finished in that state. `.cfb-boot-glow` is a Lager radial wash through `color-mix`, so App Branding retints it; it rides a `-z-10` child, since the curtain's own `z-50` opens a stacking context and that puts the glow over `bg-zinc-950` and under every in-flow child with no sibling needing `relative`. `animate-boot-rise` is `from`-only on the `cfb-entry-in` contract and worn with `motion-safe:` — nothing here may be load-bearing for visibility. The phrase slot is a fixed two-line `h-16` with each span FILLING it: the R deck writes sentences ("Untangling whatever the coordinator did to the headsets..."), and a slot that grew to fit one would walk the lockup up the screen mid-beat. BootSplashTest pins all of it, and a break-back run reds 3 of 11.
