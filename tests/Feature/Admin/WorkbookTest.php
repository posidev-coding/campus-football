<?php

use App\Enums\WorkbookCategory;
use App\Enums\WorkbookEffort;
use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use App\Models\WorkbookItem;

/*
 * The workbook, and the one fact this file exists to hold: the advisor
 * re-reads real telemetry every week and will propose the same finding every
 * single run. `key` is the idempotency — the same shape as GrantWalletEntry's
 * keyed wallet entries — and a dismissed item is never resurrected.
 */

/** What the advisor would file. */
function proposal(array $overrides = []): array
{
    return [
        'title' => 'The picks screen N+1s on slate.games.team',
        'body' => 'Measured over the last week of slow queries.',
        'category' => WorkbookCategory::Performance,
        'severity' => WorkbookSeverity::High,
        'evidence' => ['hits' => 214],
        'prompt' => 'Add the eager load and prove it with a query-count test.',
        ...$overrides,
    ];
}

describe('filing', function () {
    it('files a new item at the end of the inbox', function () {
        WorkbookItem::propose('picks-n-plus-one', proposal());
        $item = WorkbookItem::propose('slow-search', proposal(['title' => 'Search is slow']));

        expect(WorkbookItem::count())->toBe(2)
            ->and($item->status)->toBe(WorkbookStatus::Inbox)
            ->and($item->position)->toBe(2)
            ->and($item->source)->toBe(WorkbookItem::SOURCE_ADVISOR);
    });

    it('refreshes rather than duplicating when the advisor runs again', function () {
        // The whole reason `key` is unique. A weekly routine with no
        // idempotency is a board of five hundred copies of one finding.
        $this->travelTo('2026-09-01 09:00:00');
        WorkbookItem::propose('picks-n-plus-one', proposal());

        $this->travelTo('2026-09-08 09:00:00');
        WorkbookItem::propose('picks-n-plus-one', proposal(['evidence' => ['hits' => 900]]));

        $item = WorkbookItem::sole();

        expect(WorkbookItem::count())->toBe(1)
            ->and($item->evidence)->toBe(['hits' => 900])
            ->and($item->last_seen_at->toDateString())->toBe('2026-09-08');
    });

    it('never moves first_seen_at, which is the most useful number on the card', function () {
        // "How long has this been true" is the question a re-propose would
        // quietly reset to today.
        $this->travelTo('2026-09-01 09:00:00');
        WorkbookItem::propose('picks-n-plus-one', proposal());

        $this->travelTo('2026-09-22 09:00:00');
        WorkbookItem::propose('picks-n-plus-one', proposal());

        expect(WorkbookItem::sole()->first_seen_at->toDateString())->toBe('2026-09-01');
    });
});

describe('a dismissal is an answer', function () {
    it('never resurrects a dismissed item', function () {
        // Dismissing is how a human says "we know, and no". An advisor that
        // reopens it next Monday makes the board a treadmill.
        WorkbookItem::propose('wont-fix', proposal());
        WorkbookItem::sole()->update(['status' => WorkbookStatus::Dismissed]);

        WorkbookItem::propose('wont-fix', proposal(['severity' => WorkbookSeverity::Critical]));

        $item = WorkbookItem::sole();

        expect($item->status)->toBe(WorkbookStatus::Dismissed)
            // ...and the human's severity stands too. Nothing but the clock moved.
            ->and($item->severity)->toBe(WorkbookSeverity::High);
    });

    it('still records that the finding recurred', function () {
        // The decision stands; the fact that it is still true is worth having.
        $this->travelTo('2026-09-01 09:00:00');
        WorkbookItem::propose('wont-fix', proposal());
        WorkbookItem::sole()->update(['status' => WorkbookStatus::Dismissed]);

        $this->travelTo('2026-09-08 09:00:00');
        WorkbookItem::propose('wont-fix', proposal());

        expect(WorkbookItem::sole()->last_seen_at->toDateString())->toBe('2026-09-08');
    });
});

describe('the board shape', function () {
    it('orders a column by position and never across columns', function () {
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned, 'position' => 2]);
        $first = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned, 'position' => 1]);
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Done, 'position' => 1]);

        $planned = WorkbookItem::query()->inColumn(WorkbookStatus::Planned)->get();

        expect($planned)->toHaveCount(2)
            ->and($planned->first()->id)->toBe($first->id);
    });

    it('leaves Dismissed off the board', function () {
        // An answer, not a stage. A column of things we decided against is a
        // column nobody reads; the table surface filters to it instead.
        expect(WorkbookStatus::columns())->toBe([
            WorkbookStatus::Inbox,
            WorkbookStatus::Planned,
            WorkbookStatus::InProgress,
            // In review earns a column: a session finishing is not the same
            // fact as the work being merged, and only a human merging earns Done.
            WorkbookStatus::InReview,
            WorkbookStatus::Done,
        ]);
    });

    it('counts open work as everything nobody has answered', function () {
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);
        WorkbookItem::factory()->create(['status' => WorkbookStatus::InProgress]);
        // Waiting on a human to merge is still open work, not an answer.
        WorkbookItem::factory()->create(['status' => WorkbookStatus::InReview]);
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Done]);
        WorkbookItem::factory()->dismissed()->create();

        expect(WorkbookItem::query()->open()->count())->toBe(3);
    });
});

describe('the vocabulary', function () {
    it('is bounded on all three axes', function () {
        // Free text grows a hundred near-synonyms in a month and makes the
        // board unfilterable — the UxSignal reasoning.
        expect(array_keys(WorkbookCategory::options()))
            ->toBe(['bug', 'feature', 'performance', 'ux', 'data', 'ops', 'tech-debt'])
            ->and(array_keys(WorkbookSeverity::options()))
            ->toBe(['critical', 'high', 'medium', 'low'])
            ->and(array_keys(WorkbookStatus::options()))
            ->toBe(['inbox', 'planned', 'in_progress', 'in_review', 'done', 'dismissed'])
            ->and(array_keys(WorkbookEffort::options()))
            ->toBe(['s', 'm', 'l']);
    });

    it('has no middle for severity to drift into, and a middle for effort', function () {
        // Not a contradiction. A medium SIZE is a real answer — most work is a
        // day — where a medium PRIORITY is a place to hide.
        expect(WorkbookSeverity::cases())->toHaveCount(4)
            ->and(WorkbookEffort::cases())->toHaveCount(3);
    });

    it('leaves an unsized item unsized', function () {
        // Null is a real answer here: nothing casts a guess to Medium, or the
        // ready queue fills with work whose cost nobody actually estimated.
        expect(WorkbookItem::factory()->create()->effort)->toBeNull();
    });
});

describe('the reference', function () {
    /*
     * `CFB-12` is derived from the primary key, never stored — so RefreshDatabase
     * resetting AUTO_INCREMENT makes CFB-1 a different item in every test.
     * Nothing below hardcodes a reference; every one is read off the row.
     */

    it('derives from the id, under the configured prefix', function () {
        $item = WorkbookItem::factory()->create();

        expect($item->reference)->toBe('CFB-'.$item->id)
            // Derived means it costs nothing to carry into the array form,
            // which is what `--json` and the ops endpoints hand to a session.
            ->and($item->toArray()['reference'])->toBe($item->reference);
    });

    it('honors a renamed prefix', function () {
        config()->set('cfb.issue_prefix', 'ACME');

        $item = WorkbookItem::factory()->create();

        expect($item->reference)->toBe('ACME-'.$item->id)
            ->and(WorkbookItem::findByReference('ACME-'.$item->id)?->id)->toBe($item->id);
    });

    it('refuses a reference minted somewhere else', function () {
        // The whole point of parsing the prefix rather than the digits: a
        // reference pasted in from another project must resolve to NOTHING,
        // not to our twelfth item.
        $item = WorkbookItem::factory()->create();

        expect(WorkbookItem::findByReference('ACME-'.$item->id))->toBeNull()
            ->and(WorkbookItem::findByReference('CFB'.$item->id))->toBeNull()
            ->and(WorkbookItem::findByReference('CFB-'))->toBeNull()
            ->and(WorkbookItem::findByReference('nonsense'))->toBeNull();
    });

    it('does not care how the prefix was typed', function () {
        $item = WorkbookItem::factory()->create();

        expect(WorkbookItem::findByReference('cfb-'.$item->id)?->id)->toBe($item->id);
    });

    it('resolves every handle anyone actually types', function () {
        // One resolver for the command and the HTTP skin both, so a terminal
        // and a routine can never disagree about what CFB-12 means.
        $item = WorkbookItem::factory()->create(['key' => 'picks-n-plus-one']);

        expect(WorkbookItem::resolve($item->reference)?->id)->toBe($item->id)
            ->and(WorkbookItem::resolve((string) $item->id)?->id)->toBe($item->id)
            ->and(WorkbookItem::resolve('picks-n-plus-one')?->id)->toBe($item->id)
            ->and(WorkbookItem::resolve('  '.$item->reference.'  ')?->id)->toBe($item->id)
            ->and(WorkbookItem::resolve('no-such-key'))->toBeNull()
            ->and(WorkbookItem::resolve(''))->toBeNull();
    });
});

describe('the branch name', function () {
    it('leads with the reference and reads as the finding', function () {
        // Reference first so branches sort and grep by issue; the advisor's
        // key second so `git branch` is readable.
        $item = WorkbookItem::factory()->create(['key' => 'picks-n-plus-one']);

        expect($item->branchName())->toBe($item->reference.'-picks-n-plus-one');
    });

    it('survives a rename, because it is minted from the key', function () {
        // The branch is the DURABLE copy of the reference — it lives in git
        // forever and outlives the row, so a later title edit cannot move it.
        $item = WorkbookItem::factory()->create(['key' => 'picks-n-plus-one']);
        $before = $item->branchName();

        $item->update(['title' => 'Something a human decided to call it instead']);

        expect($item->fresh()->branchName())->toBe($before);
    });

    it('strips both ends of a human-filed key', function () {
        // ManageWorkbook mints `human-{slug}-{ymdHis}`. Both ends are noise in
        // a branch name; the readable middle is the whole point of it.
        $item = WorkbookItem::factory()->create(['key' => 'human-the-picks-screen-n-1s-260828113000']);

        expect($item->branchName())->toBe($item->reference.'-the-picks-screen-n-1s');
    });

    it('is still a legal branch when nothing readable is left', function () {
        // `git check-ref-format` refuses a name ending in a hyphen, and a human
        // filing a card whose title slugs to nothing gets exactly here —
        // ManageWorkbook mints `human-{slug}-{ymdHis}`, so an empty slug leaves
        // the double hyphen below.
        $item = WorkbookItem::factory()->create(['key' => 'human--260828113000']);

        expect($item->branchName())->toBe($item->reference);
    });

    it('never ends in a hyphen when the cut lands on one', function () {
        // The truncation is the ONLY real check-ref-format risk, so the cut is
        // placed exactly on a hyphen rather than left to the id's length.
        $item = WorkbookItem::factory()->create();
        $lead = mb_strlen($item->reference) + 1;
        $item->update(['key' => str_repeat('a', WorkbookItem::BRANCH_MAX_LENGTH - $lead - 1).'-tail']);

        $branch = $item->fresh()->branchName();

        expect($branch)->toEndWith('a')
            ->and(mb_strlen($branch))->toBe(WorkbookItem::BRANCH_MAX_LENGTH - 1)
            ->and($branch)->toMatch('/^[A-Za-z0-9][A-Za-z0-9-]*[A-Za-z0-9]$/');
    });
});

describe('labels', function () {
    it('normalizes free-form input into one vocabulary', function () {
        // Free-form was the requirement; a board where "Slow Query", "slow
        // query" and "slow-query" are three different filters was not.
        $item = WorkbookItem::factory()->create([
            'labels' => ['Slow Query', 'slow query', ' N+1 ', 'slow-query'],
        ]);

        // `Str::slug` drops the `+` rather than separating on it, so "N+1"
        // normalizes to `n1`. That is the price of one vocabulary, and it is
        // the same price for every caller.
        expect($item->fresh()->labels)->toBe(['slow-query', 'n1']);
    });

    it('is null when there is nothing to say, never an empty array', function () {
        // The house rule: `null` means no data. A caller skips it; it never
        // reads `[]` as an answer.
        $item = WorkbookItem::factory()->create(['labels' => ['   ', '']]);

        expect($item->fresh()->labels)->toBeNull()
            ->and(WorkbookItem::factory()->create(['labels' => []])->fresh()->labels)->toBeNull()
            ->and(WorkbookItem::factory()->create()->labels)->toBeNull();
    });

    it('is bounded, because a card with forty labels reads as none', function () {
        $item = WorkbookItem::factory()->create([
            'labels' => [...array_map(fn (int $i): string => "label-{$i}", range(1, 20)), str_repeat('x', 60)],
        ]);

        expect($item->fresh()->labels)->toHaveCount(WorkbookItem::MAX_LABELS)
            ->and(WorkbookItem::factory()->create(['labels' => [str_repeat('x', 60)]])->fresh()->labels)
            ->toBe([str_repeat('x', WorkbookItem::LABEL_MAX_LENGTH)]);
    });
});

describe('the ownership boundary', function () {
    it('has no field on both sides of the line', function () {
        expect(array_intersect(WorkbookItem::ADVISOR_OWNED, WorkbookItem::HUMAN_OWNED))->toBe([]);
    });

    it('lets a re-propose rewrite the finding and never the work', function () {
        /*
         * The regression test the whole boundary exists for. It works today
         * only because one controller's validator happens to pass six fields —
         * so this sends the human columns back through propose() on purpose,
         * which is the caller the `Arr::only` filter is defending against.
         *
         * Everything sent below is MASS ASSIGNABLE, deliberately: those are the
         * ones that would be clobbered SILENTLY in production, where
         * `preventSilentlyDiscardingAttributes` is off. The guarded columns are
         * held by the test underneath this one instead, so neither line of
         * defense can hide a hole in the other.
         */
        $item = WorkbookItem::propose('picks-n-plus-one', proposal());

        // A human sizes it, labels it, plans it; a session takes it and cuts a branch.
        $item->forceFill([
            'status' => WorkbookStatus::InProgress,
            'position' => 7,
            'effort' => WorkbookEffort::Large,
            'labels' => ['performance'],
            'branch' => $item->branchName(),
            'pr_url' => 'https://github.com/posidev-coding/campus-football/pull/9',
            'ready_at' => now(),
            'started_at' => now(),
            'claimed_at' => now(),
            'claimed_by' => 'agent:local',
            'claim_expires_at' => now()->addHour(),
        ])->save();

        WorkbookItem::propose('picks-n-plus-one', proposal([
            'title' => 'Rewritten from fresh telemetry',
            'severity' => WorkbookSeverity::Critical,
            'evidence' => ['hits' => 900],
            // Everything from here down is the advisor reaching for work it
            // does not own. None of it lands.
            'status' => WorkbookStatus::Inbox,
            'position' => 0,
            'effort' => WorkbookEffort::Small,
            'labels' => ['nonsense'],
        ]));

        $fresh = $item->fresh();

        expect($fresh->title)->toBe('Rewritten from fresh telemetry')
            ->and($fresh->severity)->toBe(WorkbookSeverity::Critical)
            ->and($fresh->evidence)->toBe(['hits' => 900])
            ->and($fresh->status)->toBe(WorkbookStatus::InProgress)
            ->and($fresh->position)->toBe(7)
            ->and($fresh->effort)->toBe(WorkbookEffort::Large)
            ->and($fresh->labels)->toBe(['performance'])
            // Untouched by anything in this test, and the point of the branch
            // being the durable copy of the reference.
            ->and($fresh->branch)->toBe($item->branchName())
            ->and($fresh->pr_url)->toBe('https://github.com/posidev-coding/campus-football/pull/9')
            ->and($fresh->claimed_by)->toBe('agent:local');
    });

    it('keeps the branch and the claim off every mass-assignment path', function () {
        // The second line, and the reason the list above is only the fillable
        // half: a mass-assignable `claimed_by` is a claim anyone can forge
        // through a form, the same reason `admin` is absent from User's list.
        $fillable = (new WorkbookItem)->getFillable();

        foreach (['branch', 'pr_url', 'ready_at', 'started_at', 'completed_at', 'claimed_at', 'claimed_by', 'claim_expires_at'] as $guarded) {
            expect($fillable)->not->toContain($guarded);
        }

        // ...and the two a human really does edit on a form are reachable.
        expect($fillable)->toContain('effort')->toContain('labels');
    });

    it('files to the inbox whatever a caller asks for', function () {
        // Where a card sits is a human's answer. `status` used to be read off
        // the payload here; nothing production ever sent one, and now nothing can.
        $item = WorkbookItem::propose('picks-n-plus-one', proposal(['status' => WorkbookStatus::Done]));

        expect($item->status)->toBe(WorkbookStatus::Inbox);
    });
});
