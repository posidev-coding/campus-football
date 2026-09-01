# Tallboy — in-app currency icon

Original marks. They use the visual cues of the cold-mountain-lager CATEGORY —
deep blue, a snow-line range on a label band, cream — and copy no brewery's
trade dress, wordmark, crest or label layout. Do not add one later: the app
stores reject look-alikes, and this is the one asset a rights holder would see.

The name is the art's own name now. The mark was always a tallboy; only the
label said latte, and that mismatch is why the band had to work so hard.

## Designed at 18px, then scaled up — not the other way round

18px beside the balance in `x-wallet-chips` is the ONLY size this renders at.
On a 1x screen that is **7×18 device pixels**: about seven columns to say "can"
in. Everything below follows from that, and a redraw that starts at 256px and
shrinks will fail the same way the first one did.

**The viewBox is the can, and `width`/`height` are REQUIRED.** An `<img>` gives
an SVG carrying only a `viewBox` a SQUARE intrinsic size, so a 42×100 viewBox
letterboxes back to 18×18 with the can stranded in 42% of it — which is exactly
what the retired mark did, and it is invisible in the markup. Both attributes
are declared on every cut. Nothing here uses a `clipPath` either: the base rim
carries its own rounded corners, so ImageMagick and the browser rasterize the
same picture and the PNGs cannot drift from the SVGs.

## tallboy-* — the full cut, 24px and up
Unmistakably a tallboy through four cues, not more detail:
  - a lid seam WIDER than the neck it sits on (36 vs 34 of a 42-wide body)
  - a short neck-in and a quick shoulder — a long one reads as a bottle
  - a base rim line
  - a cylinder reflection down the left wall, INTERRUPTED by the label band
    (the interruption is what reads as wrapped metal rather than a flat box)

light   can #1E5C8F · reflection #3E88C0 · base #123F63 · band #F5F2EA
dark    can #A8CDE8 · reflection #D6E9F6 · base #6FA3C8 · band #0F3A5C

## tallboy-*-16 — the chip cut, below 24px
The reflection, the base rim and the range all drop: at 7px wide they are mud.
What is left has to carry the mark on its own, so the silhouette is deliberately
EXAGGERATED where the full cut is honest — the top narrows to 30 of 42 and holds
that width for two whole pixel rows before the shoulder, because an 86% neck-in
is half a pixel and antialiases into a fringe rather than a shape. The band is
12 units rather than 15 for the same reason: it reads as a stripe on a can, not
as a can cut in half.

The two cuts are the same mark; only the chip cut's top is stylized, and it is
never rendered large enough for the difference to show.

## Both
- Sizes: 16 / 20 / 24 / 32 / 48 / 64 / 128 / 256 px TALL, both modes. Widths are
  42% of that, because the aspect ratio is the can. The 16 and 20px PNGs are
  rasterized from the chip cut, the rest from the full cut.
- PNGs are generated from these SVGs with Imagick (2400 DPI, Lanczos down), so
  regenerate them in the same commit as any redraw or they will quietly disagree.
- Intended slot: beside a balance figure at 18px — light and dark swapped the
  way x-team-logo swaps its two marks (`dark:hidden` / `hidden dark:block`).
