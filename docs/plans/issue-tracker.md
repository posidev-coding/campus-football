# The workbook becomes an issue tracker Claude Code can work

> **Handoff note for a fresh session.** This plan is self-contained. It builds
> on the AI layer (`docs/plans/ai-layer.md`, Phases 1–3), which is shipped and
> merged — the workbook, the Kanban and the two `/ops` doors all exist. Nothing
> here waits on anything.
>
> Every design decision recorded below is **user-approved**. Implement phase by
> phase and do not re-ask anything written down. Findings carry `file:line`
> anchors verified 2026-08-28 — trust them, but re-read each file before editing
> it.
>
> Read `@.ai/rules/index.md` and every rule file whose globs cover the paths in
> scope before writing code. The ones most likely to bite here are
> `filament.md` (the array-state trap, the panel's own Tailwind, the `wire:sort`
> attribute order), `support.md` (writes with side effects go through
> `app/Actions`; a throttle window is a spelled-out constant), `data-model.md`,
> `http.md` (the `/ops` surfaces), `console-commands.md` and `tests.md`.
>
> Branch: `ai-layer` is level with `main` and open for this work. One commit per
> phase; the suite stays green at every step.

## Context

The workbook works. The maintenance advisor files findings, they land on a
board, a human triages them in two Filament surfaces. What it cannot do is get
any of that work **done**:

- There is no way to name an item in conversation. "The N+1 one" is not a handle.
- There is no handle to start work under — no branch, no claim, no record that
  anyone picked it up.
- There is no trail. A card moves and nothing remembers why, or who moved it.
- Every transition is a human dragging a card, including the ones a machine
  just earned.

This closes that loop. An item becomes an **issue** with a short reference
(`CFB-12`), its own git branch, an activity trail, and two ways to hand it to a
Claude Code session — a copied one-liner for a local session, or a claim a cloud
routine can take. The session cuts the branch, plans, builds, tests, pushes,
opens a pull request, and moves the card as it goes. **The human merges.**

### What does not change

The advisor's contract. `workbook_items.key` stays the semantic slug
(`picks-n-plus-one`) and stays the whole idempotency story — a sequential number
cannot be re-derived from a finding next Monday, so the reference is
**additive**, never a replacement. `WorkbookItem::propose()` stays the one
doorway and keeps its dismissal guard.

---

## Settled decisions

These were decided with the user before this plan was written. Do not
re-litigate them; implement them.

### 1. The reference is derived, not stored

`CFB-{id}`, prefix from `config('cfb.issue_prefix')`.

A stored `number` column would have to be minted at exactly the moment
`propose()` decides "this is new" — inside a read-then-write
(`app/Models/WorkbookItem.php:79`, then `:92`). Two advisor passes overlapping,
or an advisor pass overlapping a human `CreateAction`, and you either race the
counter or you take a lock on the one write path that must stay fast.
`workbook_items.id` is already an InnoDB auto-increment; MySQL has solved this
durably and we inherit it for free. No backfill migration, no second source of
truth, and `findByReference()` is a primary-key lookup needing no new index.

**The obligation that follows:** the reference gets externalized into the branch
name (`CFB-12-picks-n-plus-one`), which lives in git forever and outlives the
row. So `branch` is the durable copy of the reference — it is **`unique`** and
it is **never rewritten** once stored. Say that in the migration comment.

What this costs, honestly: references do not survive a table rebuild or a
cross-environment copy. There is no such workflow here (`cfb:migrate` reseeds
ESPN reference data, not the workbook). If it ever exists, the retreat is one
migration — add `number`, backfill from `id`, read `number ?? id`.

### 2. Both hand-off paths get built

- **Local.** A copy action on the card and the table puts `/work CFB-12` on the
  clipboard. A committed skill at `.claude/skills/work/SKILL.md` does the rest.
  A local session has the database, artisan and git, so its interface is
  `cfb:issue …` commands — **not HTTP**.
- **Cloud.** A scheduled routine has no database, so the same operations also
  need `/ops/issues` endpoints, and an issue must be **claimable** so two
  routines cannot take the same one.

Both skins sit on one shared layer, the house pattern: `CoverageReport` feeds
`cfb:doctor` and the DataCoverage widget; `TelemetrySnapshot` feeds
`cfb:telemetry` and `/ops/telemetry`. A terminal and a routine must not be able
to disagree about the board.

### 3. Every issue gets its own branch

Named from the reference and the advisor's key: `CFB-12-picks-n-plus-one`.
Stored on the row when work starts, so a later title edit cannot move it.

**Commit subjects stay in this repo's prose voice** — `git log --oneline -20` is
the reference. No `CFB-12:` prefix on commits. The branch carries the audit
trail; that is a decision, not an omission.

### 4. A work session stops at the pull request

Plan, implement, run the affected tests then the suite,
`vendor/bin/pint --dirty --format agent`, `npm run build` if Blade changed,
commit on the issue branch, push, open a PR, move the card to **In review**.
The human merges.

### 5. `InReview` is a new status

Between `InProgress` and `Done`. Claude finishing is not the same as merged, and
without this column the card either lies or never moves.

### 6. An agent can never reach Done

Its terminal transition is In review. If a session could close its own work, In
review is decorative and the trail shows sessions marking themselves complete.
Merging earns Done. `cfb:issue done` refuses an `agent:`/`cloud:` actor, and
`POST /ops/issues/{issue}/done` does not exist.

### 7. Also wanted: activity trail, effort, links, labels

All four, specified per-phase below.

---

## Two things to fix on the way past

**The `propose()` docblock is currently false.** `app/Models/WorkbookItem.php:71-73`
says *"A human's edits to title, body or severity survive a re-propose in one
direction only."* They do not — line 105 is
`$existing->fill([...$attributes, 'last_seen_at' => now()])->save()` and
`$attributes` carries title, body, category and severity straight from
`app/Http/Controllers/Ops/WorkbookController.php:79-84`. All four are overwritten
every pass. That paragraph is teaching the next reader a false invariant and is
rewritten in Phase 1.

**The ownership boundary is implicit.** It works today only because the
controller's validator happens to pass six fields. With effort, labels, branch
and links on the row that is not survivable. Phase 1 makes it explicit, in the
model, where the dismissal guard already lives:

```php
/** What the advisor recomputes every pass, and therefore owns outright. */
public const ADVISOR_OWNED = ['title', 'body', 'category', 'severity', 'evidence', 'prompt', 'source'];

/** What a human decided. A weekly routine cannot reach any of it. */
public const HUMAN_OWNED = [
    'status', 'position', 'effort', 'labels', 'branch', 'pr_url',
    'ready_at', 'started_at', 'completed_at',
    'claimed_at', 'claimed_by', 'claim_expires_at',
];
```

Both paths in `propose()` filter through `Arr::only($attributes, self::ADVISOR_OWNED)`.
`$attributes['status'] ?? WorkbookStatus::Inbox` at line 90 becomes dead and is
replaced with the plain enum case — `WorkbookController.php:78` is the only
production caller and it never sends a status.

The rewritten docblock states the truth: **the advisor owns the finding, a human
owns the work.**

> `title` is the one genuinely contested field — a human renaming a card and the
> advisor renaming it back next Monday is annoying. Do **not** build
> title-pinning in this plan. The advisor writing a better title from fresh
> evidence is usually what you want. If it becomes a problem the answer is one
> nullable `title_pinned_at` and one `if`, as its own commit with its own test.

---

## The PR prerequisite is already satisfied

`gh` 2.98.0 is installed and authenticated as `posidev-coding` with the `repo`
scope. **It resolves the custom SSH alias by itself** — `origin` is
`git@posidev:posidev-coding/campus-football.git` and `~/.ssh/config` maps
`Host posidev` → `github.com`, and a bare `gh repo view` inside this repository
answers `posidev-coding/campus-football` with **no `remote.origin.gh-resolved`
entry in `.git/config`**. Verified 2026-08-28.

So Phase 7 needs no `--repo` flag and no `gh repo set-default`:
`gh pr create --fill --base main` is enough. The skill still probes with
`command -v gh` and still falls back to pushing and storing the compare URL
(`https://github.com/posidev-coding/campus-football/compare/main...CFB-12-slug?expand=1`)
— not for this machine, but because a cloud session working an issue may not
have `gh`, and a skill that assumes a binary is a skill that dies silently.

---

## Phase 1 — The reference, the fields, the ownership boundary

**Config.** `config/cfb.php`, beside `ops_token` (`:152`):

```php
'issue_prefix' => env('CFB_ISSUE_PREFIX', 'CFB'),
```

**Migration** on `workbook_items`:

| Column | Type | Null | Why |
| --- | --- | --- | --- |
| `effort` | `string(1)` | yes | `s`/`m`/`l`. String, not a MySQL enum — the reason is stated at `database/migrations/2026_08_24_233427_create_workbook_items_table.php:37-39`. Null means **not sized**; never a default. |
| `labels` | `json` | yes | See below. Null means no labels, never `[]`. |
| `branch` | `string(120)` | yes | **`->unique()`.** A nullable unique permits many NULLs, which is right, and it is what makes branch→issue inference unambiguous. Never rewritten. |
| `pr_url` | `string(255)` | yes | Filled at review. |
| `ready_at` | `timestamp` | yes | See below — not the same thing as `status = planned`. |
| `started_at` | `timestamp` | yes | First entry into In progress. |
| `completed_at` | `timestamp` | yes | Entry into Done. Distinct from `updated_at`, which any edit moves. |
| `claimed_at` | `timestamp` | yes | The claim. |
| `claimed_by` | `string(80)` | yes | A ROLE and instance — `human`, `advisor`, `agent:local`, `cloud:nightly`. **Never a user id or an email**; see Trap 8. |
| `claim_expires_at` | `timestamp` | yes | The lease. Without it a dead routine parks an issue forever. |

Index `['status', 'ready_at']` — the claim query filters on `status` and narrows
on `ready_at`; the existing `(status, position)` index cannot serve
`ready_at is not null`. Nothing on `claimed_at` alone; it is only ever read for
one already-located row.

**`ready_at` is worth its own column.** Planned means *we intend to do this.*
Ready means *the brief is complete enough that an agent can start without asking
a human a question.* Conflating them means a half-written card gets claimed by a
cloud routine at 3am.

**`#[Fillable]`** (`app/Models/WorkbookItem.php:18-21`) gains **only** `effort`
and `labels`. Branch, PR, lifecycle and claim columns stay out and are written
by the action layer through `forceFill()` — the same reason `admin` is absent
from `User`'s. A mass-assignable `claimed_by` is a claim anyone can forge
through a form.

**Casts:** `effort => WorkbookEffort::class`, `labels => 'array'`, the five new
timestamps `=> 'datetime'`.

**`app/Enums/WorkbookEffort.php`** — `Small = 's'`, `Medium = 'm'`, `Large = 'l'`,
with `label()`, `color()`, `options()`. Three levels, and the docblock must say
why that does not contradict `WorkbookSeverity`'s *"an odd-numbered scale grows a
middle everything drifts into"*: a medium **size** is a real answer, a medium
**priority** is a place to hide. Without that sentence the next reader will
"fix" it to four and be right by the letter of the existing rule.

**`app/Enums/WorkbookStatus.php`** — add `InReview = 'in_review'` between
`InProgress` and `Done`. `label()` → "In review", `color()` → `primary`.
`columns()` becomes five. `open()` needs no change: it excludes only Done and
Dismissed, so In review is correctly open.

**`app/Models/WorkbookItem.php`:**

```php
protected function reference(): Attribute            // 'CFB-12', appended to the array form
public static function findByReference(string $r): ?self
public static function resolve(string $handle): ?self   // reference | bare id | advisor key
public function branchName(): string
protected function labels(): Attribute               // the normalizing mutator
```

- `findByReference()` parses `/^([A-Za-z][A-Za-z0-9]{0,9})-(\d+)$/` and
  **refuses a prefix that is not the configured one**, so `ACME-12` pasted from
  another project resolves to null rather than to our CFB-12.
- `resolve()` is what the command and the controller both call, so
  `cfb:issue show picks-n-plus-one` works too.
- `branchName()` puts the reference first (so branches sort and grep by issue)
  and the advisor key second (so `git branch` is readable). It strips the
  `human-` prefix and trailing `-ymdHis` that `ManageWorkbook.php:27` mints,
  limits to 60 characters and `trim('-')`s — the truncation leaving a trailing
  hyphen is the only real `git check-ref-format` risk, since the key regex at
  `WorkbookController.php:50` already guarantees `[a-z0-9-]`.
- The labels mutator lowercases, `Str::slug`s, dedupes, caps at 10 labels of 30
  characters, and returns **null when empty, never `[]`**.

**Labels are a JSON column, not a pivot.** The two queries are "show them on a
card" and "filter the table to one"; `whereJsonContains` over hundreds of rows
needs no index. A pivot costs two tables, two models, two factories, an
orphan-cleanup story and a governance decision about who may mint a label — for
a field the user described as *free-form*. `evidence` already sets the
JSON-on-the-row precedent. The honest cost is no referential integrity, no
rename-in-one-place and no index; all three are fine at this size and all three
are reversible **provided every label read goes through one accessor**, which is
why the accessor is built now.

**Tests** (`tests/Feature/Admin/WorkbookTest.php`):

- Update the two bounded-vocabulary assertions (`:110-119` the board shape,
  `:132-141` the exact enum key lists) for the fifth status.
- New: the reference derives and honors the config prefix; a foreign prefix
  resolves to null; `resolve()` takes all three handles; the branch name is
  minted correctly, strips `human-…-260828113000` to the readable middle, is
  git-safe, and is **stable across a title edit**; labels normalize and empty
  becomes null.
- New, and the important one: **the ownership regression test.** A re-propose
  overwrites all six advisor fields and touches none of the human ones *even
  when the caller sends them*. This is what stops the next caller widening the
  door.

## Phase 2 — The activity trail, and one doorway

**The problem, precisely.** Five things write `status` today:

| Writer | Where | Records an event? |
| --- | --- | --- |
| The drag | `app/Actions/MoveWorkbookItem.php:31` | would |
| Bulk actions | `app/Filament/Resources/Workbook/WorkbookResource.php:215` | **no** |
| The edit form | `WorkbookResource.php:78`, saved by Filament | **no** |
| `CreateAction` | `app/Filament/Resources/Workbook/Pages/ManageWorkbook.php:23` | **no** |
| `propose()` | `app/Models/WorkbookItem.php:92` | **no** |

Four of five would leave a hole. A trail with holes is worse than no trail,
because it reads as a complete record.

**Migration `workbook_events`:**

```php
$table->id();
// foreignId() IS correct here: workbook_items.id is bigint from ->id(). The
// data-model rule against it covers the ESPN-keyed tables, whose ids are
// mediumint/int — teams, games, venues.
$table->foreignId('workbook_item_id')->constrained()->cascadeOnDelete();
$table->string('kind', 20);      // filed|readied|moved|claimed|released|started|pr_opened|commented|sized|labeled|linked
$table->string('from_status', 20)->nullable();
$table->string('to_status', 20)->nullable();
$table->string('actor', 80);
$table->text('note')->nullable();
$table->json('context')->nullable();   // {branch, pr_url, relation, labels_added}
$table->timestamp('created_at')->useCurrent();
$table->index(['workbook_item_id', 'created_at']);
```

- **`kind`, not `event` or `type`** — `client_errors` already uses `kind`, so it
  matches the house.
- **No `updated_at`.** An event is immutable: `const UPDATED_AT = null;`.
- **No prune.** `FeedRun` prunes at 14 days because it writes a row a minute all
  Saturday; this writes maybe eight rows per issue, ever, and it *is* the audit.
  Prunable here would delete the thing the table exists for.
- The index leads with `workbook_item_id` because the only query is one item's
  trail; `id` is the in-query tiebreak, since two events written inside one
  transaction share a second.

**`app/Actions/RecordWorkbookEvent.php`** — the only class that inserts into
`workbook_events`, the same shape as `GrantWalletEntry` being the only wallet
writer.

**`app/Actions/MoveWorkbookItem.php`** grows optional parameters:

```php
public function handle(
    int $itemId,
    WorkbookStatus $status,
    ?int $position = null,          // Sortable's 0-based newIndex; NULL = append
    string $actor = WorkbookEvent::ACTOR_HUMAN,
    ?string $note = null,
): ?WorkbookItem
```

Inside the existing transaction (`:41`), the splice-and-renumber is unchanged.
Added: stamp `started_at` on the first entry into In progress and `completed_at`
on Done; clear the three claim columns on Done, Inbox or Planned; and **write an
event only when `$from !== $status`** — a reorder inside a column is not
activity, and a board where every nudge writes a row is a trail nobody opens.
Say that in the docblock; it is the one non-obvious decision here.

**The three call sites:**

- `app/Filament/Pages/Workbook.php:88` — pass `actor:`. Nothing else changes.
- `WorkbookResource::moveTo()` (`:209-217`) — replace the direct
  `$item->update([...])` with the action, `position: null`. Appending in
  selection order is exactly what `null` means, so behavior is identical and
  `WorkbookBoardTest.php:201-209` stays green.
- `EditAction` — Filament saves through `$record->update($data)`, which bypasses
  the action. Use `->using()`: pull `status` out of the data, `update()` the
  rest, then hand the status to `MoveWorkbookItem`. This keeps
  `WorkbookBoardTest.php:366-380` verbatim. **Also add** a record
  `Action::make('move')` (Select + note `Textarea`) — a form save has nowhere to
  put a note, and the note is what makes the trail worth reading.

**The `filed` event is a model hook, not a call site.** `CreateAction` does not
go through `propose()` and neither does the factory:

```php
static::created(fn (self $item) => app(RecordWorkbookEvent::class)
    ->handle($item, WorkbookEvent::FILED, actor: $item->source));
```

The actor derives from `source`, so nothing needs plumbing and no create site can
forget. `propose()`'s re-file path writes **nothing** — recurrence is already
carried by `last_seen_at`, and a weekly "still true" row would bury the eight
rows that matter.

**Tests:** bulk move and edit-form save each produce events (the proof they go
through the doorway); a reorder inside a column produces none; `propose()` writes
exactly one `filed` on create and none on a re-file. The four existing
`MoveWorkbookItem` position tests (`WorkbookBoardTest.php:107-148`) compile and
pass unchanged — the new parameters are optional.

## Phase 3 — The claim, and the command surface

**`app/Actions/ClaimWorkbookItem.php`.** Do **not** write
`if ($item->claimed_at === null) { $item->update([...]); }` — two routines a
millisecond apart both read null. Use a conditional UPDATE and check the
affected row count, the shape `.ai/rules/support.md` already documents for
pick'em settlement:

```php
public const LEASE_MINUTES = 90;

$taken = WorkbookItem::query()
    ->whereKey($item->id)
    ->where(fn (Builder $q) => $q
        ->whereNull('claimed_at')
        // A lapsed lease is free. Self-healing: no reaper cron, and a routine
        // that dies mid-run frees the issue within the hour.
        ->orWhere('claim_expires_at', '<', now()))
    ->update([
        'claimed_at' => now(),
        'claimed_by' => $by,
        'claim_expires_at' => now()->addMinutes(self::LEASE_MINUTES),
    ]);

if ($taken === 0) { return null; }   // 409 at the HTTP skin, non-zero exit at the command
```

`UPDATE … WHERE claimed_at IS NULL` is atomic in InnoDB: the row lock serializes
the writers and the loser's `WHERE` no longer matches. No transaction, no
`SELECT … FOR UPDATE`, no advisory lock.

`next(string $by, array $labels = [])` walks up to 5 ready candidates —
`status = planned AND ready_at IS NOT NULL AND (claimed_at IS NULL OR
claim_expires_at < now())`, ordered by the severity `FIELD()` then `position` —
so a lost race costs one extra query, never a failure. Not `FOR UPDATE SKIP
LOCKED`: that works, but it needs an explicit transaction spanning the HTTP
request, which is a far bigger hammer than a board of dozens earns.

**`app/Actions/StartWorkbookItem.php`** — claim, mint and store the branch, stamp
`started_at`, move to In progress, write a `started` event carrying the branch in
`context`. One transaction.

**`app/Support/IssueBoard.php`** — the shared read. `one()`, `ready()`, `list()`
return the arrays that both the command's `--json` and the HTTP response serve.
`one()` carries reference, key, title, body, prompt, category, severity, effort,
labels, status, branch, pr_url, first_seen_at, the claim, links in **both**
directions already inverted, and the last N trail entries.

**`app/Console/Commands/IssueCommand.php`:**

```
cfb:issue
    {action=show : show|start|ready|review|done|comment|claim|release|link}
    {issue? : CFB-12, a bare id, or the advisor key — omit to read it off the current branch}
    {--note= : one line for the activity trail}
    {--effort= : s, m or l}
    {--label=* : add a label; repeatable}
    {--pr= : the pull request URL (review)}
    {--to= : the other issue (link)}
    {--relation=relates_to : blocks|blocked_by|relates_to|duplicates|duplicated_by}
    {--as=agent:local : who is acting, recorded on the trail}
    {--json : the machine shape instead of a terminal read}
```

**`app/Console/Commands/IssuesCommand.php`:**

```
cfb:issues
    {--status=*} {--severity=*} {--label=*} {--effort=*}
    {--ready : only issues marked ready for an agent to start}
    {--mine : only what --as currently holds}
    {--as=agent:local} {--limit=25} {--json}
```

Two commands, not seven and not one: seven classes would duplicate branch
inference seven times, and one class carrying `--pr`/`--to` for actions that
ignore them is muddy. Read versus write is also how `cfb:doctor` and
`cfb:telemetry` divide.

**Branch inference** when `{issue?}` is absent — `Illuminate\Support\Facades\Process`,
**never `shell_exec`**, so `Process::fake()` works:

1. `git rev-parse --abbrev-ref HEAD`.
2. Match the stored `branch` column — the authority, since a title edit cannot
   move it.
3. Fall back to parsing a leading `CFB-\d+` — covers a branch a human cut before
   `cfb:issue start` ran.
4. Neither matches → a message naming **both** attempts and a non-zero exit.
   Never guess.

**`start` prints `git switch -c CFB-12-…` and does not run it.** A command that
reaches into the working tree is one that will one day do it on the wrong
branch — `AdvisorSetupCommand`'s "prints, never writes" applied to git.
Re-running on an issue you hold re-prints the branch; running it on one somebody
else holds exits non-zero and does not steal the claim.

**Naming trap:** the data-model rule *"don't name a helper after a base-class
method"* rules out `option()`, `argument()`, `arguments()`, `call()`, `line()`,
`info()`, `ask()` and `choice()` on these classes. `issue()` and
`resolveIssue()` are safe.

**Tests** (`tests/Feature/IssueCommandTest.php`, new): `--json` is valid JSON and
nothing but (the `TelemetryTest.php:47-53` contract — a stray console line makes
it unparseable at the other end); branch inference in all three arms; `start` is
idempotent for the holder and refuses a thief without stealing the claim;
`Process` is never called with `switch`; a second `claim` on a held issue refuses
and does not overwrite `claimed_by`; a lapsed lease is re-claimable.

> Set `claimed_at` by hand before calling `claim()`. `.ai/rules/tests.md` is
> explicit that sequential calls are not concurrent writers — the guarantee lives
> in the `WHERE` clause, so that is what the test must exercise.

## Phase 4 — Links and labels

**Migration `workbook_links`:**

```php
$table->foreignId('from_item_id')->constrained('workbook_items')->cascadeOnDelete();
$table->foreignId('to_item_id')->constrained('workbook_items')->cascadeOnDelete();
$table->string('relation', 20);   // blocks | relates_to | duplicates — ONLY these three are storable
$table->timestamp('created_at')->useCurrent();
$table->unique(['from_item_id', 'to_item_id', 'relation']);
$table->index('to_item_id');      // the unique index LEADS with from_item_id, so it
                                  // cannot answer "what points at me"
```

**One directed row, never a mirrored pair.** A mirror doubles every write and
every delete, and the first caller that does one half leaves a half-link no
unique index can even describe as broken — which is the same argument
`.ai/rules/support.md` makes for `FollowTeam`. The mirror also carries zero
information: the inverse is a pure function of the type.

Two canonicalization rules, both enforced in `LinkWorkbookItems` and both tested:

1. **`blocked_by` and `duplicated_by` are never stored.** `A blocked_by B` is
   written as `B blocks A`.
2. **`relates_to` stores with `from_item_id < to_item_id`.** It is symmetric, so
   without this `A relates_to B` and `B relates_to A` are two rows the unique
   index happily accepts. This is the detail a mirrored design hides and a
   single-row design must say out loud.

Guards: no self-link; no reciprocal `blocks`. No deep cycle check — a recursive
CTE on every write is cost a board of dozens does not earn, and a three-hop cycle
is a human problem, not a data-integrity one.

`app/Enums/WorkbookLinkType.php` holds the whole mapping in one `match` on
`inverse()`. `relates_to` inverts to itself and, because of rule 2, can only
appear on one side, so nothing double-counts. The model gets `linksOut`
(`from_item_id`), `linksIn` (`to_item_id`) and a `renderedLinks` accessor that
flattens both into one list with the inverse already applied.

## Phase 5 — Filament

**Table** (`WorkbookResource::table()`, `:141`). A leading mono `reference`
column carries the entire clipboard feature:

```php
TextColumn::make('reference')
    ->fontFamily('mono')->size('xs')
    ->copyable()
    ->copyableState(fn (WorkbookItem $r): string => "/work {$r->reference}")
    ->copyMessage('Hand-off copied')
```

`Filament\Support\Concerns\CanBeCopied` is on both `TextColumn` and `TextEntry`
and Filament emits the `navigator.clipboard.writeText` handler itself;
`copyableState()` is why the cell can *read* `CFB-12` and *copy* `/work CFB-12`.
Needs a secure context — Herd and Cloud both have one.

`reference` has no column, so it needs explicit closures or it 1054s:

```php
->sortable(query: fn (Builder $q, string $direction) => $q->orderBy('id', $direction))
->searchable(query: fn (Builder $q, string $search) => $q->when(
    preg_match('/(\d+)\s*$/', $search, $m),
    fn ($q) => $q->orWhere('id', (int) $m[1])))
```

Add `effort` and `labels` badge columns, an `effort` `SelectFilter`, and a label
filter whose options are a distinct-in-PHP over the JSON column (correct at this
size, and the reason labels are a column rather than a pivot). Record actions
gain `move` and `ready` (`->visible(fn ($r) => $r->ready_at === null)`). The
`defaultSort` `FIELD()` closure at `:165-167` is untouched. **No bulk "move to In
review"** — In review without a PR is a lie.

**Infolist** (`:84`). The flat `->columns(2)` becomes
`Filament\Schemas\Components\Section`s or it is unreadable:

1. **The finding** — title, category, severity, effort, labels, first/last seen,
   body, evidence (keeping the `->state()` collapse at `:119` exactly as is),
   prompt (keeping `->copyable()` at `:132`).
2. **The work** — reference with `copyableState('/work …')`; branch mono +
   copyable; `pr_url` as `->url(...)->openUrlInNewTab()`; the claim, visible only
   when held.
3. **Links** and 4. **Activity** — `RepeatableEntry::make(...)->table([...])`,
   which takes `TableColumn`s and renders a compact table: exactly the shape of a
   link list and of a trail. `->visible(fn ($r) => $r->renderedLinks !== [])` so
   an empty list renders nothing rather than an empty box.

> **Not a relation manager.** Those render only on `ViewRecord`/`EditRecord`
> pages, and this resource is deliberately `ManageRecords` with modals
> (`ManageWorkbook.php:8-12`). Adding a `ViewRecord` page to host one
> restructures the resource for a single panel.
>
> **Not a custom blade timeline**, tempting as it is now that the panel compiles
> its own Tailwind — the blade would have to live under
> `resources/views/filament/**` or compile to *no CSS at all*, silently. Offer it
> as a follow-up.

**Form** (`:72`) — `Select::make('effort')`, `TagsInput::make('labels')`
(produces an array, a direct fit for the JSON column; normalize in the model
mutator, not the form), and `Textarea::make('move_note')->dehydrated(false)` for
`->using()` to read. Branch, PR and claim never appear on a form.

**Board** (`resources/views/filament/pages/workbook.blade.php`). Five columns, so
narrow the column from `w-80` to `w-72`. Cards gain the reference (mono, muted,
beside the title), an effort badge, label badges, a blocked indicator when any
`blocked_by` link is not Done, and a copy button — four lines of Alpine, since
Filament's `copyable()` is a table/infolist concern:

```blade
<button type="button" x-data
    x-on:click.stop="navigator.clipboard?.writeText(@js('/work '.$item->reference))">
```

`x-on:click.stop` matters: the card is handle-dragged, so a stray click will not
start a drag today, and stopping propagation keeps it that way if handle mode
ever changes.

`Workbook::getHeaderActions()` (`:93`) gains "Copy the next ready issue" — one tap
from the board to a session.

**Eager-load `linksIn` in `columns()`** (`:61-66`) and **re-derive** the query
ceiling in `WorkbookBoardTest.php:87-103` rather than raising it, with a comment
saying what each query is.

## Phase 6 — The ops issue API

Inside the existing group in `routes/ops.php:29-32`:

```
GET  /ops/issues                     signed — the fixed URL a routine stores
POST /ops/issues/next                claims and returns the next ready issue
POST /ops/issues/{issue}/claim
POST /ops/issues/{issue}/release
POST /ops/issues/{issue}/start
POST /ops/issues/{issue}/review
POST /ops/issues/{issue}/comment
```

`->where('issue', '[A-Za-z0-9][A-Za-z0-9-]{0,120}')` — the same bounded
vocabulary as the key regex at `WorkbookController.php:50`, stopping a
`../../etc/passwd` probe at the router rather than in the controller.

**No route-model binding.** No unique column holds `CFB-12`; resolve explicitly
through `WorkbookItem::resolve()` and 404 on miss, so the parser lives in one
place and a bare id or the advisor key also work.

**The signed-URL question resolves rather than being worked around.** A client
that must construct `/ops/issues/CFB-12` itself cannot use a signed URL — but it
does not need one, and the reasoning is already written at `routes/ops.php:44-50`:
`signed` protects a URL that is *handed* to a client and then lives in a config
file, a shell history and a log line. It was never doing authentication; the
token is. A URL the client *composes* gains nothing from a signature. So every
variable-path route here is a **write**, and writes were never signed. The only
signed route is the fixed-path list, exactly like `/ops/telemetry`.

`next` is a POST because it **takes the claim** — which also collapses
list-then-claim into one call, and with it the race between them.

Write this in the route comment, plainly: a leaked write URL is worthless without
the token, and a token holder can compose any URL anyway. What it costs is that
the routes become enumerable by a token holder — who already reaches
`/ops/workbook`, so it is not a new grant. The mitigation that matters is
**scope**, not signing.

**Responses.** `200 {"result":"claimed","issue":{…}}`; **`204`** when nothing is
ready (a routine should branch on the status code, not parse a body); **`409`
`{"result":"held","by":"cloud:other","expires_at":"…"}`** — the double-assign
refusal, which is what a routine backs off on, where a `200` with
`claimed:false` invites it to carry on. The envelope key is `result`, not
`status`, because the issue object keeps its own `status` field, consistent with
`workbook.open[].status` in the snapshot.

`pr_url` validates `['required','url','max:255','starts_with:https://']`.
Consider a host allowlist (`config('cfb.repo_host')`): the panel renders it as a
link an admin will click, and an unconstrained URL on an admin screen is a
phishing surface for free.

**What these endpoints must NOT do** — write this as the controller docblock, in
the voice of `WorkbookController.php:18-31`, because what it cannot do is the
specification:

- **No create.** Filing is `/ops/workbook`, keyed and idempotent. An issue
  endpoint that could create would be a second, unkeyed door onto the same table.
- **No dismiss.** Dismissing is a human saying "we know, and no", and it is the
  one status a machine may never write.
- **No edit of title, body, category, severity, evidence or prompt.** Those are
  `propose()`'s. A working agent rewriting its own brief is how a board stops
  being trustworthy.
- **No `position`, no delete, no `done`.**
- **No arbitrary status.** The routes are named after transitions rather than a
  `PATCH {status:…}`, so the reachable set is exactly
  `planned → in_progress → in_review`. That is the reason they are separate
  routes.

**No `FeedRun` row, deliberately.** A claim is a call, not a pass, and
`FeedRun::ADVISOR` describes a whole advisor run. `workbook_events` is already
the ledger. A "did the work routine run last night" line on Sync Health would
need its own command constant and a bookend call — separate, small work.

**`TelemetrySnapshot::workbook()`** (`app/Support/TelemetrySnapshot.php:91-119`)
gains `reference`, `effort` and `labels` on `open[]`, so the advisor can quote a
card in the language a human uses. **The 11 top-level keys do not change** —
`TelemetryTest.php:41-45` and `OpsEndpointTest.php:115-118` pin them, while the
workbook item assertions at `:203-208` use `toHaveKey`, so extending there is
safe by design. Say that in the new test's comment so it reads as a decision.

**`.claude/skills/maintenance-advisor/SKILL.md`** — its "The fields" section
(`:93-116`) learns that `effort`, `labels`, `branch`, `pr_url` and the claim
fields are human-owned and ignored, and its status vocabulary learns
`in_review`, which is **open**, not answered, so it must not be re-filed.

## Phase 7 — The `/work` skill

`.claude/skills/work/SKILL.md`, in the shape of the advisor's frontmatter so it
activates on `/work CFB-12` and on "pick up the next ready issue".

The steps, in order:

1. **Read the issue, never from memory** — `php artisan cfb:issue show CFB-12 --json`.
   The `prompt` field is the advisor's scaffold and it *is* the brief. If `body`
   and `prompt` are both empty, stop and say so: an issue with no brief is not
   ready, whatever `ready_at` says.
2. **Refuse if it is not yours.** `cfb:issue start` takes the claim; a non-zero
   exit means another session has it. Do not force a claim.
3. **Refuse if it is blocked.** `show` renders "blocked by". If any blocker is
   not Done, stop and name it.
4. **Inherit the guardrails; do not restate them.** Read `CLAUDE.md`, then
   `@.ai/rules/index.md`, match the globs against every path you are about to
   touch, read every matching rule file, and `grep -rin '<keyword>' .ai/rules`
   for what a path match misses.
5. **Branch before the first edit.** Run exactly the `git switch -c` line
   `start` printed. Never work on `main`. **Never rename the branch** — the row
   stores it, and renaming breaks every later `cfb:issue` inference.
6. **Say the plan back, on the trail** — `cfb:issue comment --note=…`, so the
   human can read it without opening a session.
7. **Verify in CLAUDE.md's order** — filtered tests, then the suite;
   `vendor/bin/pint --dirty --format agent` if any PHP changed, never `--test`;
   `npm run build` if any Blade changed; `/__device?path=…&w=390,768` for
   anything visual.
8. **Commit in the house prose voice.** No `CFB-12:` prefix.
9. **Push, then open the PR** — `git push -u origin <branch>`, then
   `command -v gh` and `gh pr create --fill --base main`, falling back to
   printing the compare URL.
10. **Move the card last** — `cfb:issue review CFB-12 --pr=<url>`. In review,
    claim released, human merges.

**What it must refuse:** merging, `gh pr merge`, pushing to `main`; moving
anything to Done; dismissing; editing title, body, category, severity, evidence
or prompt (the advisor's, and a session arguing with its own brief is how a board
stops being trustworthy); deleting a test; changing dependencies; adding a base
folder; rewriting `CLAUDE.md` or `.ai/rules/` by hand (use Boost's `record-rule`);
touching `.env` or printing `OPS_TOKEN`; working two issues on one branch.

**And the important one: if the work turns out bigger than the card, STOP.**
Comment what you found, release the claim, say so. Do not silently expand scope.

## Phase 8 (optional) — PR merged → Done

The last manual step. `POST /ops/github` with an HMAC signature check
(`GITHUB_WEBHOOK_SECRET`), reading `pull_request.merged`, matching `head.ref`
against the stored `branch`, moving that issue to Done with actor `github`.
Needs a webhook configured on the repository. **Cut this phase** if moving the
card yourself after a merge is fine.

---

## Traps

1. **Array state.** `labels` carries an `array` cast, so Filament renders it as a
   LIST and calls the formatter **once per element** — one badge per label for
   free, but a `fn (?array $state)` hint is a TypeError the moment a modal opens,
   with the row rendering perfectly behind it. Same family as `evidence` at
   `WorkbookResource.php:113-135`; recorded in `.ai/rules/filament.md`.
2. **`null` is not `0` in `MoveWorkbookItem`.** Position `0` is the TOP of a
   column; `null` means append. `$position ?? 0` silently reverses the bulk
   action's ordering and no existing test would catch it. Write
   `if ($position === null)`.
3. **The panel's own Tailwind** scans only `app/Filament/**` and
   `resources/views/filament/**`. A partial anywhere else renders unstyled,
   silently. Any new class needs `npm run build`.
4. **Modals are the panel's blind spot.** Every new Action, infolist entry and
   form field ships rendered by nothing unless driven with
   `mountAction`/`callAction` — the existing tests already do this at
   `WorkbookBoardTest.php:317` and `:350`.
5. **`FIELD()` for any status sort.** Alphabetically that is `dismissed, done,
   in_progress, in_review, inbox, planned`, which reads as a data bug.
6. **`wire:sort` attribute order.** Adding attributes to the card is safe;
   replacing `x-sort:group` with `wire:sort:group` makes the drag silently do
   nothing, and `wire:sort="move"` must stay a bare method name.
7. **The board's query ceiling** (`WorkbookBoardTest.php:87-103`). A blocked badge
   that lazy-loads is an N+1 across every card, and no feature test can catch a
   missing eager load because `preventsLazyLoading`'s per-instance flag is false
   under test. Eager-load and re-derive the ceiling.
8. **`actor` versus the no-identity guarantee.** `TelemetryTest.php:85-90` and
   `OpsEndpointTest.php:122-126` assert the snapshot carries no user identifiers
   at all. If `actor` ever held an email or a name, and events ever reached
   `TelemetrySnapshot`, those two assertions are the only thing between an
   admin's address and a third-party routine. **Store a role**, keep
   `workbook_events` out of the snapshot entirely, and **add an assertion proving
   it**. If the panel ever wants the real name, that is a nullable
   `actor_user_id` that is never serialized — with a test.
9. **`RefreshDatabase` resets AUTO_INCREMENT**, so `CFB-1` is a different item in
   every test. Never assert a hardcoded reference; derive it from
   `$item->reference`.
10. **Clipboard is untestable** — `navigator.clipboard` needs a secure context and
    is absent in the automated tab. Assert the rendered `copyableState`, per
    `.ai/rules/tests.md`'s "test through the layer a test can hold".
11. **`Process` facade, never `shell_exec`**, or branch inference cannot be faked.
12. **`workbook_items.key` is a MySQL keyword** — any raw select touching it must
    backtick. None of the new column names are reserved in MySQL 8, but avoid
    `rank`, `groups`, `system`, `row`, `lead` and `of` if the schema grows again.

## Verification

Work is not done until these pass, in order:

1. `php artisan test --compact --filter=Workbook`, then `--filter=Ops`,
   `--filter=Telemetry`, `--filter=Issue`, then the full suite. The existing
   workbook assertions are the regression net; the only ones that may change are
   the two bounded-vocabulary lists and the board's query ceiling, and each change
   needs a stated reason.
2. **Break each guard back** and confirm the matching test fails before restoring:
   - the claim's `WHERE` clause removed → two claims both succeed
   - the ownership filter removed → a re-propose clobbers `effort`
   - `moveTo()` writing directly again → no event
   - `null` position read as `0` → the bulk order reverses
3. `vendor/bin/pint --dirty --format agent` — never `--test`.
4. `npm run build`.
5. `/__device?path=/admin/workbook&w=390,768&h=900` — five columns at 390px, the
   copy button reachable, cards legible.
6. **End to end, for real.** File a human item, mark it ready, copy
   `/work CFB-n`, run it in a session, and confirm the branch exists, the PR
   opens, the card lands in In review, and the trail reads like a record of what
   actually happened.

## Scope

Eight commits, one per phase. If the headline is wanted sooner, **Phases 1, 5 and
7 alone** deliver `CFB-12`, the copy button and a session that works an issue on
its own branch; the trail, links, labels and the cloud claim are what the rest
adds.
