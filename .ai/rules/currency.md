---
paths:
  - 'public/brand/currency/**'
---

# Currency

## An SVG with only a viewBox gets a SQUARE intrinsic size from &lt;img&gt;
The currency mark renders at exactly one size — 18px beside the balance in x-wallet-chips, which on a 1x screen is seven device pixels across. Design at 18px and scale UP; the retired art was drawn at 256 and shrunk, and none of its four cues survived.

THE SILENT ONE: an <img> hands an SVG carrying only a `viewBox` a SQUARE intrinsic size, so a 42x100 viewBox letterboxes into 18x18 with the can stranded in 42% of it and the rest transparent. Measured in Chrome: naturalWidth 150x150 without width/height, 42x100 with them. Declare BOTH attributes on every cut.

No clipPath in these files. ImageMagick's SVG renderer ignores it and the browser honours it, so a clipPath makes the generated PNG family a near miss instead of the same picture; the base rim carries its own rounded corners instead. Regenerate the PNGs from the SVGs in the same commit as any redraw.

TallboyMarkTest pins all of it, and stripping width/height from one cut reds it.
