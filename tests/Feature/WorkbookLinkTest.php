<?php

use App\Actions\LinkWorkbookItems;
use App\Enums\WorkbookLinkType;
use App\Enums\WorkbookStatus;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use App\Models\WorkbookLink;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/*
 * One directed row, never a mirrored pair.
 *
 * A mirror doubles every write and every delete, and the first caller that does
 * one half leaves a half-link no unique index can even describe as broken. The
 * price of the single-row design is two canonicalization rules that must hold
 * for every caller — which is what this file holds.
 */

beforeEach(function () {
    Process::preventStrayProcesses();
    Process::fake(['git rev-parse *' => Process::result('main')]);
});

function issuePair(): array
{
    return [
        WorkbookItem::factory()->create(['key' => 'the-first', 'title' => 'The first one']),
        WorkbookItem::factory()->create(['key' => 'the-second', 'title' => 'The second one']),
    ];
}

/** NOT `link()` — that is a PHP built-in, and redeclaring it is a fatal. */
function relate(WorkbookItem $from, WorkbookItem $to, WorkbookLinkType $relation): ?WorkbookLink
{
    return app(LinkWorkbookItems::class)->handle($from, $to, $relation);
}

describe('the two canonicalization rules', function () {
    it('never stores an inverse, and renders it anyway', function () {
        // `A blocked_by B` is written as `B blocks A`. The inverse is a pure
        // function of the type, so storing it would carry no information.
        [$a, $b] = issuePair();

        relate($a, $b, WorkbookLinkType::BlockedBy);

        $row = WorkbookLink::sole();

        expect($row->from_item_id)->toBe($b->id)
            ->and($row->to_item_id)->toBe($a->id)
            ->and($row->relation)->toBe(WorkbookLinkType::Blocks)
            // ...and A still reads as blocked by B.
            ->and($a->fresh()->renderedLinks)->toBe([[
                'relation' => 'blocked_by',
                'label' => 'Blocked by',
                'reference' => $b->reference,
                'title' => 'The second one',
                'status' => 'inbox',
                'done' => false,
            ]]);
    });

    it('stores relates_to one way only, whichever way it was asked', function () {
        /*
         * The detail a mirrored design hides. `relates_to` is symmetric, so
         * without the lower-id rule `A relates_to B` and `B relates_to A` are
         * two rows the unique index happily accepts, and the list renders the
         * same fact twice.
         */
        [$a, $b] = issuePair();

        relate($a, $b, WorkbookLinkType::RelatesTo);
        relate($b, $a, WorkbookLinkType::RelatesTo);

        expect(WorkbookLink::count())->toBe(1)
            ->and(WorkbookLink::sole()->from_item_id)->toBe(min($a->id, $b->id))
            ->and($a->fresh()->renderedLinks)->toHaveCount(1)
            ->and($b->fresh()->renderedLinks)->toHaveCount(1);
    });

    it('reads the same from both ends', function () {
        [$a, $b] = issuePair();

        relate($a, $b, WorkbookLinkType::Duplicates);

        expect($a->fresh()->renderedLinks[0]['relation'])->toBe('duplicates')
            ->and($b->fresh()->renderedLinks[0]['relation'])->toBe('duplicated_by');
    });

    it('is inverted by a total function, with no case left out', function () {
        foreach (WorkbookLinkType::cases() as $case) {
            expect($case->inverse()->inverse())->toBe($case);
        }

        expect(WorkbookLinkType::RelatesTo->inverse())->toBe(WorkbookLinkType::RelatesTo)
            ->and(collect(WorkbookLinkType::cases())->filter(fn (WorkbookLinkType $c): bool => $c->isStorable())->values()->all())
            ->toBe([WorkbookLinkType::Blocks, WorkbookLinkType::RelatesTo, WorkbookLinkType::Duplicates]);
    });
});

describe('the two guards', function () {
    it('will not link an issue to itself', function () {
        $item = WorkbookItem::factory()->create();

        expect(relate($item, $item, WorkbookLinkType::Blocks))->toBeNull()
            ->and(WorkbookLink::count())->toBe(0);
    });

    it('will not let two issues block each other', function () {
        // Both ways is a deadlock, not a link. Deeper cycles are a human
        // problem — a recursive CTE on every write is cost this board does not earn.
        [$a, $b] = issuePair();

        relate($a, $b, WorkbookLinkType::Blocks);

        expect(relate($b, $a, WorkbookLinkType::Blocks))->toBeNull()
            ->and(WorkbookLink::count())->toBe(1);
    });

    it('is idempotent, and says so only once', function () {
        [$a, $b] = issuePair();

        relate($a, $b, WorkbookLinkType::Blocks);
        relate($a, $b, WorkbookLinkType::Blocks);

        expect(WorkbookLink::count())->toBe(1)
            ->and($a->fresh()->events()->where('kind', WorkbookEvent::LINKED)->count())->toBe(1);
    });

    it('goes when either end goes', function () {
        [$a, $b] = issuePair();
        relate($a, $b, WorkbookLinkType::Blocks);

        $b->delete();

        expect(WorkbookLink::count())->toBe(0);
    });
});

describe('being blocked', function () {
    it('is true only while the blocker is unfinished', function () {
        // A session reads this and stops. Working an issue whose blocker is
        // still open is how two branches fight over one file.
        [$blocked, $blocker] = issuePair();

        relate($blocked, $blocker, WorkbookLinkType::BlockedBy);

        expect($blocked->fresh()->isBlocked())->toBeTrue()
            ->and($blocker->fresh()->isBlocked())->toBeFalse();

        $blocker->forceFill(['status' => WorkbookStatus::Done])->save();

        expect($blocked->fresh()->isBlocked())->toBeFalse();
    });

    it('is not confused by a relates_to', function () {
        [$a, $b] = issuePair();

        relate($a, $b, WorkbookLinkType::RelatesTo);

        expect($a->fresh()->isBlocked())->toBeFalse();
    });
});

describe('linking from the command', function () {
    it('links two issues by any handle either of them answers to', function () {
        [$a, $b] = issuePair();

        $exit = Artisan::call('cfb:issue', [
            'action' => 'link',
            'issue' => $a->reference,
            '--to' => 'the-second',
            '--relation' => 'blocked_by',
        ]);

        expect($exit)->toBe(0)
            ->and(WorkbookLink::sole()->from_item_id)->toBe($b->id);
    });

    it('refuses a relation the vocabulary does not have', function () {
        [$a] = issuePair();

        expect(Artisan::call('cfb:issue', ['action' => 'link', 'issue' => $a->reference, '--to' => 'the-second', '--relation' => 'supersedes']))->toBe(1)
            ->and(WorkbookLink::count())->toBe(0);
    });

    it('refuses a --to that resolves to nothing', function () {
        [$a] = issuePair();

        expect(Artisan::call('cfb:issue', ['action' => 'link', 'issue' => $a->reference, '--to' => 'CFB-999999']))->toBe(1)
            ->and(Artisan::call('cfb:issue', ['action' => 'link', 'issue' => $a->reference]))->toBe(1);
    });

    it('carries both directions into the machine shape', function () {
        [$a, $b] = issuePair();
        relate($a, $b, WorkbookLinkType::BlockedBy);

        Artisan::call('cfb:issue', ['action' => 'show', 'issue' => $a->reference, '--json' => true]);
        $issue = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($issue['blocked'])->toBeTrue()
            ->and($issue['links'][0]['relation'])->toBe('blocked_by')
            ->and($issue['links'][0]['reference'])->toBe($b->reference);
    });
});
