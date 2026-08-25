<?php

use App\Enums\WorkbookCategory;
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
            WorkbookStatus::Done,
        ]);
    });

    it('counts open work as everything nobody has answered', function () {
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);
        WorkbookItem::factory()->create(['status' => WorkbookStatus::InProgress]);
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Done]);
        WorkbookItem::factory()->dismissed()->create();

        expect(WorkbookItem::query()->open()->count())->toBe(2);
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
            ->toBe(['inbox', 'planned', 'in_progress', 'done', 'dismissed']);
    });

    it('has no middle for severity to drift into', function () {
        expect(WorkbookSeverity::cases())->toHaveCount(4);
    });
});
