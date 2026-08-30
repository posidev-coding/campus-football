---
name: work
description: "Work one Campus Football issue end to end: read it with cfb:issue, claim it, cut its branch, build it, test it, open a pull request and move the card to In review. Use when handed a reference like /work CFB-12, when asked to pick up the next ready issue, or when told to work an item off the workbook board. Do not use for filing findings — that is the maintenance advisor — and never for merging."
---

# Work an issue

You have been handed one issue off the workbook board. You plan it, build it,
test it, push it and open a pull request. **You stop there.** The human merges.

Everything below is order-dependent. The refusals at the end are not advice.

## 1. Read the issue, never from memory

```bash
php artisan cfb:issue show CFB-12 --json
```

Without a reference, take the next one nobody is holding:

```bash
php artisan cfb:issues --ready
php artisan cfb:issue start CFB-12 --as=agent:local
```

The `prompt` field is the advisor's scaffold and it **is** the brief; `body` is
what it found. If both are empty, **stop and say so.** An issue with no brief is
not ready, whatever `ready_at` says — working from a title is how a session
builds the wrong thing confidently.

**Which board these commands reach is `CFB_BOARD_URL`.** Unset, they read this
checkout's own table — which is not where the advisor files, so a card handed to
you may simply not be there. Set, every verb here goes to that deployment over
HTTP instead. There is no fallback in either direction on purpose: if a command
refuses and names a board it could not reach, **the write did not happen**, and
retrying against the local table is not the repair. Say so and hand the text
back.

## 2. Refuse if it is not yours

`cfb:issue start` takes the claim. A non-zero exit means another session holds
it. **Do not force a claim, do not work it anyway.** Say who holds it and stop.

## 3. Refuse if it is blocked

`show` renders `blocked` and the links. If any blocker is not Done, **stop and
name it.** Two branches fighting over one file is worse than a day of waiting.

## 4. Inherit the guardrails; do not restate them

Read `CLAUDE.md`. Then open `@.ai/rules/index.md`, match its globs against every
path you are about to touch, and read every rule file that matches. Then
`grep -rin '<keyword>' .ai/rules` for what a path match misses. **Each rule
there is a bug that already shipped and failed silently.**

## 5. Branch before the first edit

`start` printed the line. Run exactly it:

```bash
git switch -c CFB-12-picks-n-plus-one
```

**Never work on `main`. Never rename the branch** — the row stores it, and a
rename breaks every later `cfb:issue` inference. One issue per branch.

## 6. Say the plan back, on the trail

```bash
php artisan cfb:issue comment CFB-12 --note='Adding the eager load to pickem-home, then a query-count test.'
```

So a human can read what you are about to do without opening a session. If it
refuses, it prints the note straight back — that text exists nowhere else, so
**put it in your reply** rather than moving on.

## 7. Verify in CLAUDE.md's order

1. `php artisan test --compact --filter=SomeTest` on what you touched, then the
   whole suite.
2. `vendor/bin/pint --dirty --format agent` if any PHP changed. **Never
   `--test`.**
3. `npm run build` if any Blade changed, or new Tailwind utilities are missing
   at runtime and it looks like a design bug.
4. `/__device?path=/scoreboard&w=390,768&h=800` for anything visual. Chrome will
   not size a window below ~600px; use the harness, not a resized window.
5. Where the bug was a wrong default, **break the fix back** and confirm the new
   test actually fails. That class of test passes for the wrong reason more
   often than not.

A new behavior needs a new or updated test. This is not optional, and no test
is deleted without asking.

## 8. Commit in the house prose voice

`git log --oneline -20` is the reference. **No `CFB-12:` prefix on commits** —
the branch carries the audit trail, and that is a decision rather than an
omission.

## 9. Push, then open the pull request

```bash
git push -u origin CFB-12-picks-n-plus-one
command -v gh && gh pr create --fill --base main
```

`gh` resolves this repository by itself — `origin` is a custom SSH alias and a
bare `gh repo view` still answers correctly, so no `--repo` flag and no
`gh repo set-default`. If `gh` is missing, push anyway and use the compare URL:

```
https://github.com/posidev-coding/campus-football/compare/main...CFB-12-picks-n-plus-one?expand=1
```

## 10. Move the card last

```bash
php artisan cfb:issue review CFB-12 --pr=https://github.com/posidev-coding/campus-football/pull/9
```

In review, claim released, human merges. **That is the end of your job.**

## What you must refuse

- **Merging.** No `gh pr merge`, no pushing to `main`, no fast-forwarding
  anything. Ever.
- **Moving anything to Done.** Your terminal transition is In review. `done`
  refuses you anyway, and trying is worth a comment on the trail, not a retry.
- **Dismissing.** That is a human saying "we know, and no".
- **Editing `title`, `body`, `category`, `severity`, `evidence` or `prompt`.**
  Those are the advisor's, and a session arguing with its own brief is how a
  board stops being trustworthy. `--effort` and `--label` are yours to add.
- **Deleting a test.** Ask.
- **Changing dependencies**, or adding a base folder under `app/`.
- **Rewriting `CLAUDE.md` or `.ai/rules/` by hand.** Use Boost's `record-rule`.
- **Touching `.env`, or printing `OPS_TOKEN`.**
- **Working two issues on one branch.** Two issues, two branches.

## And the important one

**If the work turns out bigger than the card, STOP.**

```bash
php artisan cfb:issue comment CFB-12 --note='This needs the CfbCalendar refactor first — bigger than the card.'
php artisan cfb:issue release CFB-12
```

Say so plainly in your reply. Do not silently expand scope: a card that quietly
became three days of work is how a board stops predicting anything.
