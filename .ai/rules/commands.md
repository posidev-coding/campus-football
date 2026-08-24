---
paths:
  - 'app/Notifications/**, app/Jobs/**, app/Console/Commands/**'
---

# Commands

## The weekly loop: root the audience in memberships, and claim the noise separately
Two rules Phase 6 established (2026-08-22); long form in docs/operations.md "The weekly loop".

1. ANY pick'em audience roots in `group_members` joined through contests to slates — NEVER in `slate_entries` or `picks`. An entry row is created lazily on a member's FIRST pick (MakePick), so somebody who has picked nothing has no entry and no pick rows, is invisible to a query rooted in either, and is exactly who a pick reminder (and the "you missed it" results push) exists for. The obvious implementation reminds only the people who already played, silently, while looking correct. PickemRemindersTest fails the moment the audience is re-rooted.

2. `settled_at` claims the MONEY; `results_announced_at` claims the NOISE. Never make one column do both. AnnounceSlateResults takes its own claim BEFORE building the batch (a queue retry re-runs the whole fan-out and would mail everyone twice), and the separation is what makes `pickem:announce --slate=` a safe repair — the wallet never hears about it.

Also: SettleSlate's in-memory $slate is STALE after the claim (query-builder update, not a model save) — status still reads prelim, settled_at still null. Never guard on either below that line; dispatch the id and let the job re-read.

Anchor player reminders on Slate::nextKickoff(), never slateDeadline() (that is the commissioner's forfeit clock) and never firstKickoff() (which stops being anybody's deadline once the noon games start, while the late card is still pickable).

Never resolve Pennant per user inside a sweep — mirror config('cfb.pickem_open'), the way pickem:preflight does; the database driver persists a row per resolve.
