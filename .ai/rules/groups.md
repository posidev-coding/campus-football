---
paths:
  - 'public/brand/groups/**'
---

# Groups

## A clubhouse mark is source art the app never names — design it at 32px, ship the PNG the uploader accepts
`public/brand/groups/` holds the SVG source and the 512px PNG for one specific group's icon (Goalpost Salvage Co. first, 2026-09-05). Nothing in the app references these files: a group's icon is `groups.icon`, a path on the upload disk that the commissioner writes through the group screen (SetGroupIcon), so the PNG is what gets uploaded and the SVG is how it gets redrawn. The only gate it meets is `ImageUpload::rules()` — JPG/PNG/GIF/WebP, MAX_KB, the 64px floor — and GoalpostSalvageIconTest runs the shipped file through that exact rule, never a copy of it.

x-group-icon renders an upload at 20, 32, 36 and 44px behind object-cover with the container's own rounding, so: square canvas, square corners (the component rounds them), three cues at most, no lettering — type is mud below 96px — and design at 32px then scale up, the tallboy lesson. A goalpost with its stem underwater read as a bucket; the whole fork has to show. The PNG is Chromium's headless shell screenshotting the SVG alone in a zero-margin 512×512 page (Imagick would do; there is no clipPath to disagree on). Regenerate it in the same commit as any redraw: the test samples flat regions of the PNG for the SVG's fills and reds when the two disagree. The design reasoning lives in the SVG's own comment, not in a README.
