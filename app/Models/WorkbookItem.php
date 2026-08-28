<?php

namespace App\Models;

use App\Enums\WorkbookCategory;
use App\Enums\WorkbookEffort;
use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use Database\Factories\WorkbookItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * One piece of proposed work. See the migration for why `key` is the whole
 * idempotency story, and why the reference is derived rather than stored.
 *
 * Two names, and they are not redundant. `key` is the advisor's semantic slug
 * (`picks-n-plus-one`) and it is what a re-propose finds; `reference`
 * (`CFB-12`) is what a human says out loud and hands to a session. A sequential
 * number cannot be re-derived from a finding next Monday, so the reference is
 * ADDITIVE — it never replaces the key.
 */
#[Fillable([
    'key', 'title', 'body', 'category', 'severity', 'status',
    'evidence', 'prompt', 'source', 'first_seen_at', 'last_seen_at', 'position',
    // Effort and labels are the ONLY new columns a form may write. Branch, PR,
    // the lifecycle stamps and the claim are the action layer's, through
    // forceFill() — the same reason `admin` is absent from User's list. A
    // mass-assignable `claimed_by` is a claim anyone can forge through a form.
    'effort', 'labels',
])]
class WorkbookItem extends Model
{
    /** @use HasFactory<WorkbookItemFactory> */
    use HasFactory;

    public const SOURCE_ADVISOR = 'advisor';

    public const SOURCE_HUMAN = 'human';

    /**
     * What the advisor recomputes every pass, and therefore owns outright.
     *
     * `first_seen_at` and `last_seen_at` are absent deliberately: they are the
     * clock, and `propose()` writes them itself.
     *
     * @var list<string>
     */
    public const ADVISOR_OWNED = ['title', 'body', 'category', 'severity', 'evidence', 'prompt', 'source'];

    /**
     * What a human decided. A weekly routine cannot reach any of it.
     *
     * @var list<string>
     */
    public const HUMAN_OWNED = [
        'status', 'position', 'effort', 'labels', 'branch', 'pr_url',
        'ready_at', 'started_at', 'completed_at',
        'claimed_at', 'claimed_by', 'claim_expires_at',
    ];

    /** Free-form does not mean unbounded — a card with forty labels reads as none. */
    public const MAX_LABELS = 10;

    public const LABEL_MAX_LENGTH = 30;

    /**
     * The branch name's ceiling. Well under the column's 120, because the name
     * is read in a terminal and typed by a human.
     */
    public const BRANCH_MAX_LENGTH = 60;

    /**
     * The handle. Derived, so it costs nothing to carry everywhere the model
     * is serialized — and the array form is what `cfb:issue --json` and
     * `/ops/issues` both hand to a session.
     *
     * @var list<string>
     */
    protected $appends = ['reference'];

    /**
     * Mirrors the column defaults, and it is not redundant with them.
     *
     * A database default fills the ROW but not the in-memory model, so
     * `create([...])->status` read null until something refetched it — a
     * cast-to-enum property that is null on the object and correct in MySQL is
     * the kind of gap a Filament badge renders blank over.
     */
    protected $attributes = [
        'status' => WorkbookStatus::Inbox->value,
        'source' => self::SOURCE_ADVISOR,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'category' => WorkbookCategory::class,
            'severity' => WorkbookSeverity::class,
            'status' => WorkbookStatus::class,
            'effort' => WorkbookEffort::class,
            'evidence' => 'array',
            'labels' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ready_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
        ];
    }

    /**
     * File an item, or refresh the one already filed under this key.
     *
     * THE ONE DOORWAY for the advisor, so the three rules cannot be forgotten
     * by a new caller — the same reason every wallet write goes through
     * GrantWalletEntry:
     *
     *   1. `key` is unique, so a weekly routine re-proposing the same finding
     *      updates one row instead of filing a five-hundredth copy.
     *   2. **A dismissed item is never resurrected.** Dismissing is how a human
     *      says "we know, and no". `last_seen_at` still moves — the finding IS
     *      still true, and knowing it recurred is worth having — but nothing
     *      else is touched and the status stays dismissed.
     *   3. **The advisor owns the finding; a human owns the work.** Every pass
     *      overwrites all of `self::ADVISOR_OWNED`, because that is what it
     *      just recomputed from fresh telemetry and there is no version of this
     *      where last week's copy of the evidence wins. Nothing in
     *      `self::HUMAN_OWNED` is reachable from here AT ALL — not the status,
     *      not the effort, not the branch, not the claim — and the `Arr::only`
     *      below is what makes that true for callers this file has never met,
     *      rather than true only for as long as one controller's validator
     *      happens to pass six fields.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function propose(string $key, array $attributes): self
    {
        $attributes = Arr::only($attributes, self::ADVISOR_OWNED);

        $existing = static::query()->where('key', $key)->first();

        if ($existing?->status === WorkbookStatus::Dismissed) {
            // Touched, not reopened. The recurrence is a fact worth recording
            // even though the decision stands.
            $existing->forceFill(['last_seen_at' => now()])->save();

            return $existing;
        }

        if ($existing === null) {
            return static::create([
                ...$attributes,
                'key' => $key,
                // Always the inbox. Where a card sits is a human's answer, and
                // the advisor is the volume, not the authority.
                'status' => WorkbookStatus::Inbox,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'position' => static::nextPosition(WorkbookStatus::Inbox),
            ]);
        }

        // first_seen_at is deliberately NOT in this list: it answers "how long
        // has this been true", which is the most useful number on the card and
        // the one a re-propose would quietly reset to today.
        $existing->fill([...$attributes, 'last_seen_at' => now()])->save();

        return $existing;
    }

    /**
     * `CFB-12` — the handle a human types and a session is handed.
     *
     * Derived from the primary key rather than stored: minting a number would
     * have to happen inside `propose()`'s read-then-write, and two overlapping
     * advisor passes would either race the counter or lock the one write path
     * that has to stay fast. InnoDB's auto-increment already solved this.
     */
    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => static::prefix().'-'.$this->getKey());
    }

    /**
     * Parse `CFB-12` back to a row.
     *
     * It REFUSES a prefix that is not ours, so `ACME-12` pasted in from another
     * project resolves to nothing rather than quietly resolving to our twelfth
     * item. The case does not matter — `cfb-12` is the same thing typed in a
     * hurry.
     */
    public static function findByReference(string $reference): ?self
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9]{0,9})-(\d+)$/', trim($reference), $matches) !== 1) {
            return null;
        }

        if (mb_strtolower($matches[1]) !== mb_strtolower(static::prefix())) {
            return null;
        }

        return static::query()->find((int) $matches[2]);
    }

    /**
     * Every handle anyone actually types: `CFB-12`, a bare `12`, or the
     * advisor's own key.
     *
     * One resolver for the command and the HTTP skin both, so a terminal and a
     * routine can never disagree about what `CFB-12` means.
     */
    public static function resolve(string $handle): ?self
    {
        $handle = trim($handle);

        if ($handle === '') {
            return null;
        }

        if (($byReference = static::findByReference($handle)) !== null) {
            return $byReference;
        }

        if (ctype_digit($handle)) {
            return static::query()->find((int) $handle);
        }

        return static::query()->where('key', $handle)->first();
    }

    /**
     * `CFB-12-picks-n-plus-one` — the branch this issue is worked on.
     *
     * The reference comes FIRST so branches sort and grep by issue, and the
     * advisor's key comes second so `git branch` is readable. Minted from the
     * key rather than the title, so a later rename cannot move a branch that
     * already exists in git.
     *
     * A human-filed key arrives as `human-{slug}-260828113000`; both ends of
     * that are noise in a branch name and both are stripped. The truncation is
     * the only real `git check-ref-format` risk — a name may not end in a
     * hyphen — so it is trimmed after cutting, not before.
     */
    public function branchName(): string
    {
        $slug = preg_replace('/^human-/', '', (string) $this->key);
        $slug = Str::slug(preg_replace('/-\d{12}$/', '', (string) $slug));

        return rtrim(mb_substr(trim($this->reference.'-'.$slug, '-'), 0, self::BRANCH_MAX_LENGTH), '-');
    }

    /**
     * Free-form on the way in, bounded on the way through.
     *
     * Lowercase slugs, deduped, capped — and EMPTY IS NULL, never `[]`. "No
     * labels" is no data, and a caller reading an empty array as an answer is
     * the same mistake as writing a default for missing data.
     *
     * Every label read goes through the `array` cast and every write goes
     * through here, which is what keeps the JSON-column decision reversible: a
     * pivot table later is one accessor's worth of change.
     */
    protected function labels(): Attribute
    {
        return Attribute::set(function (mixed $value): array {
            $labels = collect(Arr::wrap($value))
                ->map(fn (mixed $label): string => rtrim(mb_substr(Str::slug((string) $label), 0, self::LABEL_MAX_LENGTH), '-'))
                ->filter()
                ->unique()
                ->take(self::MAX_LABELS)
                ->values()
                ->all();

            return ['labels' => $labels === [] ? null : json_encode($labels)];
        });
    }

    /** The end of a column. Positions only ever compare against siblings. */
    public static function nextPosition(WorkbookStatus|string $status): int
    {
        $value = $status instanceof WorkbookStatus ? $status->value : $status;

        return (int) static::query()->where('status', $value)->max('position') + 1;
    }

    /** One column of the board, in order. */
    public function scopeInColumn(Builder $query, WorkbookStatus $status): Builder
    {
        return $query->where('status', $status->value)->orderBy('position')->orderBy('id');
    }

    /** Everything a human has not already answered. In review is still open. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [WorkbookStatus::Done->value, WorkbookStatus::Dismissed->value]);
    }

    /** The configured reference prefix, read once so the fallback lives in one place. */
    private static function prefix(): string
    {
        return (string) config('cfb.issue_prefix', 'CFB');
    }
}
