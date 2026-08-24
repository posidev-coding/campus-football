<?php

use App\Actions\MoveWorkbookItem;
use App\Enums\WorkbookCategory;
use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use App\Filament\Pages\Branding;
use App\Filament\Pages\PickemSettings;
use App\Filament\Pages\SyncHealth;
use App\Filament\Pages\Workbook;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Workbook\Pages\ManageWorkbook;
use App\Filament\Resources\Workbook\WorkbookResource;
use App\Filament\Widgets\RecentSyncFailures;
use App\Filament\Widgets\SyncSpend;
use App\Models\FeedRun;
use App\Models\User;
use App\Models\WorkbookItem;
use App\Support\SyncSchedule;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The two Filament surfaces over one model.
 *
 * The board's drag CANNOT be driven by a test — SortableJS ignores synthetic
 * pointer events — so it is held from both ends instead: the rendered
 * attributes on one side, and MoveWorkbookItem's outcome on the other.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

/** Three items in one column, in order. */
function column(WorkbookStatus $status, int $count = 3): array
{
    return collect(range(1, $count))
        ->map(fn (int $i): WorkbookItem => WorkbookItem::factory()->create([
            'status' => $status,
            'position' => $i,
            'title' => "Item {$i}",
        ]))
        ->all();
}

describe('the board', function () {
    it('renders a column per status, and never Dismissed', function () {
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox, 'title' => 'On the board']);
        WorkbookItem::factory()->dismissed()->create(['title' => 'Answered already']);

        Livewire::actingAs($this->admin)->test(Workbook::class)
            ->assertOk()
            ->assertSee('Inbox')
            ->assertSee('Planned')
            ->assertSee('In progress')
            ->assertSee('Done')
            ->assertSee('On the board')
            ->assertDontSee('Answered already');
    });

    it('renders the three attributes the drag depends on', function () {
        // The only layer a test can hold. A bare method name (Livewire
        // rewrites `move($item)` magics to undefined), a group-id per column
        // (which is how a cross-column drop says where it landed), and
        // Alpine's group attribute rather than Livewire's.
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);

        $html = Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        expect($html)
            ->toContain('wire:sort="move"')
            ->toContain('wire:sort:group-id="inbox"')
            ->toContain('wire:sort:group-id="in_progress"')
            ->toContain('x-sort:group="workbook"')
            ->toContain('wire:sort:item=')
            // ...and NOT Livewire's own group attribute, which would make its
            // attribute loop `return` before binding wire:sort at all.
            ->not->toContain('wire:sort:group="');
    });

    it('builds the whole board in one query, not one per column', function () {
        foreach (WorkbookStatus::columns() as $status) {
            column($status, 2);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        // One read for the items. The rest is the panel's own session and
        // user lookups, so the ceiling is generous — the point is that it does
        // not scale with the number of columns.
        expect($queries)->toBeLessThan(8);
    });
});

describe('moving a card', function () {
    it('reorders within a column, one-based, from a zero-based index', function () {
        // Sortable reports newIndex, which is ZERO-based; stored positions are
        // one-based. This is where an off-by-one would live.
        [$a, $b, $c] = column(WorkbookStatus::Inbox);

        app(MoveWorkbookItem::class)->handle($c->id, WorkbookStatus::Inbox, 0);

        expect(WorkbookItem::query()->inColumn(WorkbookStatus::Inbox)->pluck('id')->all())
            ->toBe([$c->id, $a->id, $b->id])
            ->and($c->fresh()->position)->toBe(1);
    });

    it('changes status when it lands in another column', function () {
        [$a] = column(WorkbookStatus::Inbox);
        column(WorkbookStatus::Planned, 2);

        app(MoveWorkbookItem::class)->handle($a->id, WorkbookStatus::Planned, 1);

        expect($a->fresh()->status)->toBe(WorkbookStatus::Planned)
            ->and(WorkbookItem::query()->inColumn(WorkbookStatus::Planned)->pluck('id')->all()[1])
            ->toBe($a->id);
    });

    it('closes the gap in the column it left', function () {
        // Not cosmetic. Positions are what the next drop's index is measured
        // against, so a gap left behind lands the next card in the wrong slot.
        [$a, $b, $c] = column(WorkbookStatus::Inbox);

        app(MoveWorkbookItem::class)->handle($a->id, WorkbookStatus::Done, 0);

        expect($b->fresh()->position)->toBe(1)
            ->and($c->fresh()->position)->toBe(2);
    });

    it('clamps an index past the end instead of trusting it', function () {
        [$a, $b, $c] = column(WorkbookStatus::Inbox);

        app(MoveWorkbookItem::class)->handle($a->id, WorkbookStatus::Inbox, 99);

        expect(WorkbookItem::query()->inColumn(WorkbookStatus::Inbox)->pluck('id')->all())
            ->toBe([$b->id, $c->id, $a->id]);
    });

    it('is a quiet no-op on anything the client made up', function () {
        // Reachable from a public Livewire method, so the client can send
        // anything at all.
        [$a] = column(WorkbookStatus::Inbox, 1);

        Livewire::actingAs($this->admin)->test(Workbook::class)
            ->call('move', (string) $a->id, 0, 'not-a-column')
            ->call('move', '999999', 0, 'planned')
            ->assertOk();

        expect($a->fresh()->status)->toBe(WorkbookStatus::Inbox)
            ->and(WorkbookItem::count())->toBe(1);
    });

    it('accepts the string id the drag actually sends', function () {
        [$a] = column(WorkbookStatus::Inbox, 1);

        Livewire::actingAs($this->admin)->test(Workbook::class)
            ->call('move', (string) $a->id, 0, 'done')
            ->assertOk();

        expect($a->fresh()->status)->toBe(WorkbookStatus::Done);
    });
});

describe('the table', function () {
    it('sorts worst first, not alphabetically', function () {
        // `severity` holds the enum's string value, so an alphabetical sort is
        // critical-high-low-medium, which puts Low above Medium and reads as a
        // bug in the board rather than a sort.
        $low = WorkbookItem::factory()->create(['severity' => WorkbookSeverity::Low]);
        $critical = WorkbookItem::factory()->create(['severity' => WorkbookSeverity::Critical]);
        $medium = WorkbookItem::factory()->create(['severity' => WorkbookSeverity::Medium]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$critical, $medium, $low], inOrder: true);
    });

    it('filters by all three axes', function () {
        $bug = WorkbookItem::factory()->create(['category' => WorkbookCategory::Bug]);
        $perf = WorkbookItem::factory()->create(['category' => WorkbookCategory::Performance]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->filterTable('category', ['bug'])
            ->assertCanSeeTableRecords([$bug])
            ->assertCanNotSeeTableRecords([$perf]);
    });

    it('answers several items at once, which the board cannot', function () {
        $items = column(WorkbookStatus::Inbox);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callTableBulkAction('move_dismissed', $items);

        expect(WorkbookItem::query()->where('status', WorkbookStatus::Dismissed->value)->count())->toBe(3);
    });

    it('counts open work on the sidebar', function () {
        WorkbookItem::factory()->count(2)->create(['status' => WorkbookStatus::Inbox]);
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Done]);
        WorkbookItem::factory()->dismissed()->create();

        expect(WorkbookResource::getNavigationBadge())->toBe('2');
    });
});

describe('the sidebar', function () {
    it('groups the panel instead of one flat list', function () {
        expect(WorkbookResource::getNavigationGroup())->toBe('Work')
            ->and(Workbook::getNavigationGroup())->toBe('Work')
            ->and(SyncHealth::getNavigationGroup())->toBe('Operations')
            ->and(Branding::getNavigationGroup())->toBe('Configuration')
            ->and(PickemSettings::getNavigationGroup())->toBe('Configuration')
            ->and(TeamResource::getNavigationGroup())->toBe('Configuration');
    });
});

describe('the advisor ledger', function () {
    it('says so plainly before the advisor has ever run', function () {
        Livewire::actingAs($this->admin)
            ->test(SyncSpend::class)
            ->assertOk()
            ->assertSee('Advisor')
            ->assertSee('Never run');
    });

    it('reads the advisor run out of the same ledger everything else writes to', function () {
        // No second store and no second widget: the advisor is a Claude Code
        // routine with no database access, so its runs arrive over the /ops
        // surface — but they land in feed_runs under the same three statuses.
        WorkbookItem::factory()->count(3)->create(['status' => WorkbookStatus::Inbox]);

        $run = FeedRun::begin(FeedRun::ADVISOR, null);
        $run->complete(records: 3, requests: 0, durationMs: 4_200);

        Livewire::actingAs($this->admin)
            ->test(SyncSpend::class)
            ->assertOk()
            ->assertSee('3 items open');
    });

    it('shows a failed advisor pass in the failures table for free', function () {
        // The reason it reuses feed_runs rather than a table of its own.
        FeedRun::begin(FeedRun::ADVISOR, null)->fail('the telemetry endpoint timed out', 0, 900);

        Livewire::actingAs($this->admin)
            ->test(RecentSyncFailures::class)
            ->assertOk()
            ->assertSee('advisor:review')
            ->assertSee('the telemetry endpoint timed out');
    });

    it('stays off the schedule report, whose cron it cannot see', function () {
        // SyncSchedule introspects OUR scheduler; the advisor's cron lives in
        // Claude Code's cloud. A row there would report an overdue flag
        // nothing can compute.
        $names = collect(app(SyncSchedule::class)->tasks())->pluck('name');

        expect($names)->not->toContain(FeedRun::ADVISOR);
    });
});

describe('the schedule report', function () {
    it('leaves no command that writes a feed run marked untracked', function () {
        // A grey "untracked" row means "this writes no ledger entry", which is
        // a real and useful state — model:prune and the news fan-out are
        // genuinely untracked. A command that DOES write one and is missing a
        // ledgerKey() case renders the same grey and the row simply lies.
        $untracked = collect(app(SyncSchedule::class)->tasks())
            ->where('tracked', null)
            ->pluck('name')
            ->all();

        expect($untracked)->toBe(['cfb:news:followed', 'cfb:news:followed:offseason']);
    });
});
