<?php

namespace App\Actions;

use App\Enums\WorkbookStatus;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Take an issue, so two sessions cannot work the same one.
 *
 * The guarantee lives in a WHERE clause, not in a read-then-write. Two routines
 * a millisecond apart both read `claimed_at === null` and both write; a
 * conditional UPDATE and a check of the affected row count cannot — InnoDB's row
 * lock serializes the writers, and the loser's WHERE no longer matches. Same
 * shape as pick'em settlement's `whereNull('settled_at')` claim. No transaction,
 * no `SELECT … FOR UPDATE`, no advisory lock.
 *
 * A LAPSED LEASE IS FREE, and that is what makes this self-healing: a routine
 * that dies mid-run frees its issue within the hour, so there is no reaper cron
 * to write and nothing to park an issue forever.
 */
class ClaimWorkbookItem
{
    /**
     * How long a claim holds. Spelled out, because a window computed from a
     * Carbon diff is the trap `.ai/rules/support.md` records — it fails OPEN.
     *
     * Ninety minutes is a session that plans, builds, tests and opens a PR,
     * with room to be slow. Longer parks a dead routine's issue past the point
     * anyone would wait; shorter and a working session loses its own claim.
     */
    public const LEASE_MINUTES = 90;

    /**
     * How far down the ready queue `next()` will walk before giving up. A lost
     * race costs one more query, never a failure.
     */
    public const CANDIDATES = 5;

    /**
     * The atomic half: take the lease, or do not.
     *
     * Separate from `handle()` because `StartWorkbookItem` takes the same lease
     * and announces itself differently — a `claimed` row immediately followed
     * by a `started` row is two lines saying one thing.
     */
    public function take(WorkbookItem $item, string $by): bool
    {
        $by = mb_substr($by, 0, WorkbookEvent::ACTOR_MAX_LENGTH);

        WorkbookItem::query()
            ->whereKey($item->id)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('claimed_at')
                ->orWhere('claim_expires_at', '<', now())
                // The holder renewing its own lease. `cfb:issue start` run
                // twice on the issue you already hold must not refuse.
                ->orWhere('claimed_by', $by))
            ->update([
                'claimed_at' => now(),
                'claimed_by' => $by,
                'claim_expires_at' => now()->addMinutes(self::LEASE_MINUTES),
            ]);

        /*
         * The affected-row count is NOT the answer, and this is the trap.
         * MySQL reports rows CHANGED, not rows matched — so a holder renewing
         * its own lease inside the same second writes identical values,
         * updates zero rows, and would read as refused. `cfb:issue start` run
         * twice in a row hit exactly that.
         *
         * Reading back the winner's name is unambiguous in every case: the
         * winner and the renewing holder both see themselves, and a loser
         * whose WHERE no longer matched sees the holder. The atomicity is
         * still entirely in the WHERE clause above — the row lock serializes
         * the writers, and this read only interprets the outcome.
         */
        return WorkbookItem::query()->whereKey($item->id)->value('claimed_by') === $by;
    }

    /** Take it and say so on the trail. Null means somebody else holds it. */
    public function handle(WorkbookItem $item, string $by): ?WorkbookItem
    {
        $heldBy = $item->claimed_by;

        if (! $this->take($item, $by)) {
            return null;
        }

        $item->refresh();

        // Renewing your own lease is not news. Only a change of hands is.
        if ($heldBy !== $item->claimed_by) {
            app(RecordWorkbookEvent::class)->handle(
                $item, WorkbookEvent::CLAIMED, actor: $by,
                context: ['expires_at' => $item->claim_expires_at?->toIso8601String()],
            );
        }

        return $item;
    }

    /**
     * The next issue an agent may start, claimed in the same breath.
     *
     * Ready is not the same fact as planned: planned means we intend to do
     * this, ready means the brief is complete enough to start without asking a
     * human a question. Only `ready_at` gets claimed at 3am.
     *
     * Walks up to CANDIDATES rather than `FOR UPDATE SKIP LOCKED` — that works,
     * but it needs an explicit transaction spanning the HTTP request, a far
     * bigger hammer than a board of dozens earns.
     *
     * @param  list<string>  $labels  Only issues carrying one of these.
     */
    public function next(string $by, array $labels = []): ?WorkbookItem
    {
        $wanted = array_values(array_filter(array_map(fn (string $l): string => Str::slug($l), $labels)));

        $candidates = WorkbookItem::query()
            ->where('status', WorkbookStatus::Planned->value)
            ->whereNotNull('ready_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('claimed_at')
                ->orWhere('claim_expires_at', '<', now()))
            ->when($wanted !== [], fn (Builder $query): Builder => $query
                ->where(function (Builder $query) use ($wanted): void {
                    foreach ($wanted as $label) {
                        $query->orWhereJsonContains('labels', $label);
                    }
                }))
            // Worst first. `FIELD()` because the column holds the enum's string
            // value, and alphabetically that is critical-high-low-medium.
            ->orderByRaw("field(severity, 'critical', 'high', 'medium', 'low')")
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::CANDIDATES)
            ->get();

        foreach ($candidates as $candidate) {
            if (($claimed = $this->handle($candidate, $by)) !== null) {
                return $claimed;
            }
        }

        return null;
    }

    /**
     * Hand it back.
     *
     * Only the holder may, and an unheld issue is already released — so this
     * never STEALS. A human taking an issue off a dead routine waits for the
     * lease, or moves the card, which releases it through `MoveWorkbookItem`.
     */
    public function release(WorkbookItem $item, string $by, ?string $note = null): ?WorkbookItem
    {
        if ($item->claimed_at === null) {
            return $item;
        }

        $released = WorkbookItem::query()
            ->whereKey($item->id)
            ->where('claimed_by', $by)
            ->update(['claimed_at' => null, 'claimed_by' => null, 'claim_expires_at' => null]);

        if ($released === 0) {
            return null;
        }

        app(RecordWorkbookEvent::class)->handle($item, WorkbookEvent::RELEASED, actor: $by, note: $note);

        return $item->refresh();
    }
}
