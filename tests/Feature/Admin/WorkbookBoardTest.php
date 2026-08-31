<?php

use App\Actions\ClaimWorkbookItem;
use App\Actions\LinkWorkbookItems;
use App\Actions\MoveWorkbookItem;
use App\Actions\StartWorkbookItem;
use App\Enums\WorkbookCategory;
use App\Enums\WorkbookEffort;
use App\Enums\WorkbookLinkType;
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
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use App\Support\SyncSchedule;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    it('marks effort without a badge, so it cannot be read as severity', function () {
        /*
         * Found by looking, which is the only way it could have been. Every
         * badge rendered correctly in isolation; the defect was two of them
         * side by side. A card has no column header, so `Large` sat next to
         * `High` in the same amber, and `Medium` effort collided with `Medium`
         * severity on the word AND the color — three vocabularies in one row
         * with nothing to tell them apart.
         *
         * Effort is a muted mono marker on the card now. It stays a full badge
         * on the table and the infolist, where a labelled column disambiguates.
         */
        WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox,
            'severity' => WorkbookSeverity::High,
            'effort' => WorkbookEffort::Large,
        ]);

        $html = Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        expect($html)->toContain('data-effort="l"')
            // Severity keeps its badge...
            ->toContain('High')
            // ...and the word that collided with it is nowhere on the board.
            ->not->toContain('Large');
    });

    it('says nothing about effort on a card nobody has sized', function () {
        // Null means NOT SIZED. No marker, no dash, no zero.
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox, 'effort' => null]);

        expect(Livewire::actingAs($this->admin)->test(Workbook::class)->html())
            ->not->toContain('data-effort');
    });

    it('builds the whole board in one query, not one per column', function () {
        foreach (WorkbookStatus::columns() as $status) {
            column($status, 2);
        }

        // ...and a link, so the blocked badge's nested load actually fires.
        // Without one, `linksIn.from` never runs and the ceiling below is
        // measured on a board that does not exercise the badge at all.
        app(LinkWorkbookItems::class)->handle(
            WorkbookItem::query()->firstOrFail(),
            WorkbookItem::query()->latest('id')->firstOrFail(),
            WorkbookLinkType::BlockedBy,
        );

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        /*
         * FIVE, and the number is derived rather than raised:
         *
         *   1. the items, one read for the whole board
         *   2. `linksIn`, eager — a blocked badge that lazy-loads is an N+1
         *      across every card, and no feature test can catch a missing
         *      eager load
         *   3. `linksIn.from`, the blocker whose status the badge reads
         *   4. the header action's next-ready lookup
         *   5. the label filter's vocabulary, one pluck for the whole board
         *
         * None of them scale with the number of columns or the number of
         * cards, which is the property this test exists to hold — and it is
         * why a card's session hand-off is a modal: composing the block reads
         * the trail and the links, which must never run once per card here.
         */
        expect($queries)->toBe(5);
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

describe('the board controls', function () {
    it('narrows every column by severity, category, effort and label', function () {
        WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox, 'title' => 'The critical one',
            'severity' => WorkbookSeverity::Critical, 'category' => WorkbookCategory::Bug,
            'effort' => WorkbookEffort::Small, 'labels' => ['frontend'],
        ]);
        WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox, 'title' => 'The low one',
            'severity' => WorkbookSeverity::Low, 'category' => WorkbookCategory::Ux,
            'effort' => WorkbookEffort::Large, 'labels' => ['performance'],
        ]);

        $board = Livewire::actingAs($this->admin)->test(Workbook::class);

        $board->set('severity', 'critical')->assertSee('The critical one')->assertDontSee('The low one');
        $board->set('severity', '')->set('category', 'ux')->assertSee('The low one')->assertDontSee('The critical one');
        $board->set('category', '')->set('effort', 's')->assertSee('The critical one')->assertDontSee('The low one');
        $board->set('effort', '')->set('label', 'performance')->assertSee('The low one')->assertDontSee('The critical one');
    });

    it('shrugs off a nonsense control instead of filtering to an empty board', function () {
        // `#[Url]` hydrates without firing the update hook, so a bookmarked
        // `?severity=nonsense` reaches the query on first load — normalized in
        // mount, it means "everything" rather than "nothing".
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox, 'title' => 'Still here']);

        Livewire::actingAs($this->admin)
            ->withQueryParams(['severity' => 'nonsense', 'group' => 'garbage'])
            ->test(Workbook::class)
            ->assertSet('severity', '')
            ->assertSet('group', '')
            ->assertSee('Still here');
    });

    it('groups a column in the vocabulary\'s order, skipping empty buckets', function () {
        WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox, 'title' => 'The low one', 'severity' => WorkbookSeverity::Low,
        ]);
        WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox, 'title' => 'The critical one', 'severity' => WorkbookSeverity::Critical,
        ]);

        // Worst first — position put the low card above the critical one, so
        // in-order assertions prove the buckets reordered the column.
        $board = Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->set('group', 'severity')
            ->assertSeeInOrder(['Critical', 'The critical one', 'Low', 'The low one']);

        // High and Medium hold no cards; a heading over nothing is noise. By
        // the heading's key, because the word `Medium` legitimately sits in
        // the severity select above the board.
        expect($board->html())
            ->toContain('wire:key="workbook-inbox-critical"')
            ->not->toContain('wire:key="workbook-inbox-medium"');
    });

    it('puts the unsized bucket last, named as the answer it is', function () {
        WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox, 'title' => 'Sized', 'effort' => WorkbookEffort::Small,
        ]);
        WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox, 'title' => 'Nobody estimated this', 'effort' => null,
        ]);

        Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->set('group', 'effort')
            ->assertSeeInOrder(['Sized', 'Not sized', 'Nobody estimated this']);
    });

    it('withholds the drag while the board is narrowed or grouped', function () {
        /*
         * A filtered column hides cards, so Sortable's index counts visible
         * ones while positions are measured against the whole column — the
         * drop lands somewhere the eye did not put it. A grouped column is not
         * in position order at all. The handle disappears rather than lying.
         */
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);

        $board = Livewire::actingAs($this->admin)->test(Workbook::class);

        expect($board->html())->toContain('wire:sort=')->toContain('wire:sort:handle');

        $narrowed = $board->set('severity', 'high')->html();

        expect($narrowed)->not->toContain('wire:sort=')->not->toContain('wire:sort:handle');

        $grouped = $board->set('severity', '')->set('group', 'severity')->html();

        expect($grouped)->not->toContain('wire:sort=')
            ->and($grouped)->toContain('Drag is paused');
    });

    it('refuses a stale drop server-side while narrowed', function () {
        // The blade withholds the attributes, but a DOM from before the filter
        // landed can still fire — and its index counts the cards it could see.
        [$first, $second] = column(WorkbookStatus::Planned, 2);

        Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->set('severity', 'high')
            ->call('move', (string) $second->id, 0, WorkbookStatus::Done->value);

        expect($second->fresh()->status)->toBe(WorkbookStatus::Planned);
    });
});

describe('working a card from the board', function () {
    it('starts a card through the same transition as the table', function () {
        $item = WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox,
            'key' => 'picks-n-plus-one',
        ]);

        Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->callAction('start', arguments: ['item' => $item->id]);

        $fresh = $item->fresh();

        expect($fresh->status)->toBe(WorkbookStatus::InProgress)
            ->and($fresh->branch)->toBe($item->branchName())
            ->and($fresh->claimed_by)->toBe(WorkbookEvent::ACTOR_HUMAN)
            ->and($fresh->events()->pluck('kind')->all())->toContain(WorkbookEvent::STARTED);
    });

    it('never steals a claim from a card either', function () {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned]);
        app(ClaimWorkbookItem::class)->handle($item, 'cloud:nightly');

        Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->callAction('start', arguments: ['item' => $item->id])
            ->assertNotified("{$item->reference} is already held");

        expect($item->fresh()->claimed_by)->toBe('cloud:nightly');
    });

    it('offers Start only on cards a session could actually take', function () {
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox, 'title' => 'Startable']);
        $working = WorkbookItem::factory()->create(['status' => WorkbookStatus::InProgress, 'title' => 'Working']);

        $html = Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        expect(substr_count($html, "mountAction('start'"))->toBe(1)
            ->and($html)->not->toContain("mountAction('start', { item: {$working->id} })");
    });

    it('swaps the card\'s clipboard from /work to the hand-off once started', function () {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned, 'key' => 'picks-n-plus-one']);

        $before = Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        expect($before)->toContain('Copy /work '.$item->reference)
            ->not->toContain('Session hand-off for');

        app(StartWorkbookItem::class)->handle($item, WorkbookEvent::ACTOR_HUMAN);

        $after = Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        expect($after)->toContain('Session hand-off for '.$item->reference)
            ->not->toContain('Copy /work '.$item->reference);
    });

    it('serves the whole hand-off from the card\'s modal', function () {
        // A modal rather than an inline copy on purpose: composing the block
        // reads the trail and the links, which must cost queries on a click,
        // never once per card on render — the board's query ceiling holds that.
        $item = WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Planned,
            'key' => 'picks-n-plus-one',
            'body' => 'The rail panel renders game cards without the team eager-loaded.',
        ]);

        app(StartWorkbookItem::class)->handle($item, WorkbookEvent::ACTOR_HUMAN);

        Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->mountAction('handoff', arguments: ['item' => $item->id])
            ->assertMountedActionModalSee("/work {$item->reference}")
            ->assertMountedActionModalSee('git switch -c '.$item->fresh()->branch);
    });

    it('opens the detail view from a click on the card itself', function () {
        // The card is a summary, and the thing a reader wants after a summary
        // is the rest of it. Mounted from the whole <article>, not a button
        // on it — asserted at the rendered attribute AND driven, because the
        // attribute proves the doorway and the mount proves what is behind it.
        $item = WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Planned,
            'title' => 'The picks screen N+1s',
            'body' => 'The rail panel renders game cards without the team eager-loaded.',
            'prompt' => 'Add the eager load to pickem-home and prove it with a query-count test.',
        ]);

        $page = Livewire::actingAs($this->admin)->test(Workbook::class);

        expect($page->html())->toContain("mountAction('view', { item: {$item->id} })")
            // Keyboard, because an <article> is not focusable or activatable
            // on its own and a board nobody can tab through is a regression.
            ->toContain('role="button"')
            ->toContain('tabindex="0"');

        $page->mountAction('view', arguments: ['item' => $item->id])
            ->assertMountedActionModalSee($item->reference)
            ->assertMountedActionModalSee('The rail panel renders game cards')
            ->assertMountedActionModalSee('Add the eager load');
    });

    it('serves the board card exactly the table\'s detail view', function () {
        // One schema, two modals. Two renderings of one item that disagree is
        // how a board stops being trusted, so both surfaces read the same
        // method rather than each keeping a copy.
        $item = WorkbookItem::factory()->create([
            'evidence' => ['hits' => 214],
            'prompt' => 'Add the eager load to pickem-home.',
        ]);

        Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->mountAction('view', arguments: ['item' => $item->id])
            ->assertMountedActionModalSee('214')
            ->assertMountedActionModalSee('Add the eager load');
    });

    it('keeps the drag handle and every card button out of the modal\'s way', function () {
        // The handle is the one that bites: without `.stop`, letting go of a
        // card you had only nudged fires a click that opens a modal over the
        // board. The buttons already stopped propagation for the drag; the
        // handle never had to until the card itself became clickable.
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned]);
        app(StartWorkbookItem::class)->handle($item, WorkbookEvent::ACTOR_HUMAN);

        $html = Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        expect($html)->toContain('x-on:click.stop')
            ->toContain("wire:click.stop=\"mountAction('review', { item: {$item->id} })\"")
            ->toContain("wire:click.stop=\"mountAction('handoff', { item: {$item->id} })\"");

        // The handle's own stop, read off the handle rather than the page:
        // `toContain` over the whole board would be satisfied by the copy
        // button's identical attribute three elements away.
        $handle = str($html)->after('wire:sort:handle')->before('>')->toString();

        expect($handle)->toContain('x-on:click.stop');
    });

    it('does not fall over on a card whose item has since been deleted', function () {
        // The record resolves BEFORE the modal mounts, and ViewAction fills the
        // schema from a non-nullable `Model $record` — so a stale board would
        // TypeError on the click rather than shrug. Disabled unmounts cleanly.
        $item = WorkbookItem::factory()->create();
        $id = $item->id;
        $item->delete();

        Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->mountAction('view', arguments: ['item' => $id])
            ->assertActionNotMounted('view')
            ->assertOk();
    });

    it('ships a copy button whose handler is real JavaScript', function () {
        /*
         * Shipped inert, and nothing anywhere said so. `@js()` inside a
         * COMPONENT TAG's attribute is not template text — the tag compiler
         * captures it verbatim, so the browser received the literal string
         * `@js($handoff)`, Alpine failed to parse it, and the button did
         * nothing with no console error. (A plain <button> compiles it fine,
         * which is why the card's own copy button always worked and this one
         * did not.) Same family as "an Alpine expression that starts with a
         * comment never runs": INERT, not broken.
         *
         * Asserted at the rendered attribute, per "test through the layer a
         * test can hold" — `navigator.clipboard` needs a secure context and
         * does not exist in the automated tab at all.
         */
        // Rendered directly, because the modal body is NOT in the page
        // component's own html() — the partial IS the layer this bug lives in.
        $html = view('filament.pages.partials.workbook-handoff', [
            'handoff' => "/work CFB-1\ngit switch -c CFB-1-picks",
        ])->render();

        expect($html)->toContain('navigator.clipboard?.writeText(')
            // The payload really rode along, rather than an empty call.
            ->toContain('git switch -c CFB-1-picks')
            // The tell. An uncompiled directive reaches the browser as text.
            ->not->toContain('@js(');
    });

    it('keeps every Blade directive out of a component tag\'s attributes', function () {
        // The general shape of the bug above, swept once rather than
        // rediscovered per view: a directive in a `<x-…>` attribute never
        // compiles, and it fails silently every time.
        $offenders = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->filter(fn ($file): bool => preg_match('/<x-[^>]*@(js|json)\(/s', file_get_contents($file->getPathname())) === 1)
            ->map(fn ($file): string => $file->getRelativePathname())
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });
});

describe('starting from the panel', function () {
    it('starts a card the way cfb:issue start does', function () {
        // The same transition through the same action — claim, branch, column,
        // one `started` row — so the panel and the terminal cannot drift.
        $item = WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox,
            'key' => 'picks-n-plus-one',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make('start')->table($item));

        $fresh = $item->fresh();

        expect($fresh->status)->toBe(WorkbookStatus::InProgress)
            ->and($fresh->branch)->toBe($item->branchName())
            ->and($fresh->claimed_by)->toBe(WorkbookEvent::ACTOR_HUMAN)
            ->and($fresh->events()->pluck('kind')->all())->toContain(WorkbookEvent::STARTED);
    });

    it('never steals a claim another session holds', function () {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned]);
        app(ClaimWorkbookItem::class)->handle($item, 'cloud:nightly');

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make('start')->table($item))
            ->assertNotified("{$item->reference} is already held");

        expect($item->fresh()->claimed_by)->toBe('cloud:nightly')
            ->and($item->fresh()->status)->toBe(WorkbookStatus::Planned);
    });

    it('offers Start only where starting makes sense', function () {
        // In review is a session finishing, Done and Dismissed are answers —
        // a Start button on any of them is an invitation to fork the work.
        $inbox = WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);
        $done = WorkbookItem::factory()->create(['status' => WorkbookStatus::Done]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->assertActionVisible(TestAction::make('start')->table($inbox))
            ->assertActionHidden(TestAction::make('start')->table($done));
    });

    it('carries a copyable session hand-off once started', function () {
        // The infolist only runs when the modal mounts, so the entry is driven
        // rather than assumed — the panel's blind spot.
        $item = WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Inbox,
            'key' => 'picks-n-plus-one',
        ]);

        app(StartWorkbookItem::class)->handle($item, WorkbookEvent::ACTOR_HUMAN);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($item))
            ->assertMountedActionModalSee('Session hand-off')
            ->assertMountedActionModalSee("/work {$item->reference}");
    });

    it('says to start first while there is no branch to hand off', function () {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($item))
            ->assertMountedActionModalSee('Start the issue first');
    });

    it('hands over the whole brief, so a session with no board access can work it', function () {
        // The paste target is a laptop session working the production board,
        // which it deliberately cannot reach — the ops token never lives
        // there. The copy must therefore carry everything `cfb:issue show
        // --json` would have answered, not a reference to look up.
        $item = WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Planned,
            'key' => 'picks-n-plus-one',
            'body' => 'The rail panel renders game cards without the team eager-loaded.',
            'prompt' => 'Add the eager load and prove it with a query-count test.',
        ]);

        app(StartWorkbookItem::class)->handle($item, WorkbookEvent::ACTOR_HUMAN);

        $handoff = WorkbookResource::handoff($item->fresh());

        expect($handoff)->toContain("/work {$item->reference}")
            ->toContain('cfb:issue show --json')
            ->toContain($item->body)
            ->toContain($item->prompt)
            ->toContain('git switch -c '.$item->fresh()->branch);
    });
});

describe('handing a card to review', function () {
    /*
     * The transition the panel could not perform until this existed.
     *
     * Moving a card to In review and REVIEWING one are not the same thing: the
     * drag and the Move modal both go through `MoveWorkbookItem`, which sets
     * the column and nothing else, so a card handed on that way kept its claim
     * and carried no pull request. The merge webhook then closed it from
     * wherever it sat and the record was gone for good. So every assertion
     * here is about what a MOVE would NOT have done.
     */
    $pr = 'https://github.com/posidev-coding/campus-football/pull/33';

    /** A card somebody has started: claim held, branch stored. */
    function startedCard(): WorkbookItem
    {
        $item = WorkbookItem::factory()->create([
            'status' => WorkbookStatus::Planned,
            'key' => 'picks-n-plus-one',
        ]);

        app(StartWorkbookItem::class)->handle($item, WorkbookEvent::ACTOR_HUMAN);

        return $item->fresh();
    }

    it('records the pull request and RELEASES the claim', function () use ($pr) {
        $item = startedCard();

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make('review')->table($item), ['pr_url' => $pr]);

        $fresh = $item->fresh();

        expect($fresh->pr_url)->toBe($pr)
            ->and($fresh->status)->toBe(WorkbookStatus::InReview)
            /*
             * The half a column check cannot see. `MoveWorkbookItem` clears a
             * claim on Done, Inbox and Planned only — In review is deliberately
             * not on that list — so a test asserting the column alone passes
             * over a card still held until its lease lapses.
             */
            ->and($fresh->claimed_at)->toBeNull()
            ->and($fresh->claimed_by)->toBeNull()
            ->and($fresh->claim_expires_at)->toBeNull();
    });

    it('writes exactly one pr_opened row, carrying the URL', function () use ($pr) {
        $item = startedCard();

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make('review')->table($item), [
                'pr_url' => $pr,
                'note' => 'Tests green, pint clean.',
            ]);

        $opened = $item->events()->where('kind', WorkbookEvent::PR_OPENED)->get();

        expect($opened)->toHaveCount(1)
            ->and($opened->first()->context['pr_url'])->toBe($pr)
            ->and($opened->first()->note)->toBe('Tests green, pint clean.')
            ->and($opened->first()->actor)->toBe(WorkbookEvent::ACTOR_HUMAN);
    });

    it('refuses anything that is not a pull request URL, and writes nothing', function () {
        // The same three rules `cfb:issue review --pr=` applies, off the same
        // two constants — a URL one doorway takes is one the other takes.
        $item = startedCard();

        foreach (['', 'github.com/posidev-coding/campus-football/pull/33', 'https://'.str_repeat('x', 250)] as $malformed) {
            Livewire::actingAs($this->admin)
                ->test(ManageWorkbook::class)
                ->callAction(TestAction::make('review')->table($item), ['pr_url' => $malformed])
                ->assertHasActionErrors(['pr_url']);
        }

        $fresh = $item->fresh();

        expect($fresh->pr_url)->toBeNull()
            ->and($fresh->status)->toBe(WorkbookStatus::InProgress)
            ->and($fresh->claimed_by)->toBe(WorkbookEvent::ACTOR_HUMAN)
            ->and($fresh->events()->where('kind', WorkbookEvent::PR_OPENED)->count())->toBe(0);
    });

    it('never records a pull request on a card somebody else holds', function () use ($pr) {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned]);
        app(StartWorkbookItem::class)->handle($item, 'cloud:nightly');

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make('review')->table($item->fresh()), ['pr_url' => $pr])
            ->assertNotified("{$item->reference} is held by somebody else");

        $fresh = $item->fresh();

        expect($fresh->pr_url)->toBeNull()
            ->and($fresh->status)->toBe(WorkbookStatus::InProgress)
            ->and($fresh->claimed_by)->toBe('cloud:nightly')
            ->and($fresh->events()->where('kind', WorkbookEvent::PR_OPENED)->count())->toBe(0);
    });

    it('offers Review only on a card that has a branch', function () {
        // A card nobody ever started has no pull request to point at.
        $started = startedCard();
        $never = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->assertActionVisible(TestAction::make('review')->table($started))
            ->assertActionHidden(TestAction::make('review')->table($never));
    });

    it('prefills the URL already on the card, so it doubles as a correction', function () use ($pr) {
        $item = startedCard();
        $item->forceFill(['pr_url' => $pr])->save();

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make('review')->table($item->fresh()))
            ->assertActionDataSet(['pr_url' => $pr]);
    });

    it('hands a card on from the board through the same doorway', function () use ($pr) {
        // The card action and the row action share one transition and one set
        // of words, the way Start already does — two surfaces cannot phrase
        // the same outcome differently.
        $item = startedCard();

        Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->callAction('review', ['pr_url' => $pr], arguments: ['item' => $item->id])
            ->assertNotified("{$item->reference} is in review");

        $fresh = $item->fresh();

        expect($fresh->pr_url)->toBe($pr)
            ->and($fresh->status)->toBe(WorkbookStatus::InReview)
            ->and($fresh->claimed_by)->toBeNull()
            ->and($fresh->events()->where('kind', WorkbookEvent::PR_OPENED)->count())->toBe(1);
    });

    it('prefills the board card\'s field from the card it was mounted on', function () use ($pr) {
        $item = startedCard();
        $item->forceFill(['pr_url' => $pr])->save();

        Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->mountAction('review', arguments: ['item' => $item->id])
            ->assertActionDataSet(['pr_url' => $pr]);
    });

    it('puts the review button only on cards that have a branch', function () {
        $started = startedCard();
        $never = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned, 'title' => 'Never started']);

        $html = Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        expect(substr_count($html, "mountAction('review'"))->toBe(1)
            ->and($html)->toContain("mountAction('review', { item: {$started->id} })")
            ->and($html)->not->toContain("mountAction('review', { item: {$never->id} })");
    });
});

describe('the sidebar', function () {
    it('groups the panel instead of one flat list', function () {
        expect(WorkbookResource::getNavigationGroup())->toBe('Work')
            ->and(Workbook::getNavigationGroup())->toBe('Work')
            ->and(SyncHealth::getNavigationGroup())->toBe('Operations')
            ->and(Branding::getNavigationGroup())->toBe('Configuration')
            ->and(PickemSettings::getNavigationGroup())->toBe('Configuration')
            // Teams moved out of Configuration when Team Branding was absorbed
            // into a full Team resource — the branding curation is one tab of
            // a team now, not a settings page.
            ->and(TeamResource::getNavigationGroup())->toBe('College Football');
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
        /*
         * A grey "untracked" row means "this writes no ledger entry", which is
         * a real and useful state — model:prune and the news fan-out are
         * genuinely untracked. A command that DOES write one and is missing a
         * ledgerKey() case renders the same grey and the row simply lies.
         *
         * The three pick'em sweeps joined this list when `pickem:` became a
         * reported prefix. They call no trackRun, so untracked is the honest
         * state for them — a task reporting a run it never recorded would be
         * worse than one reporting nothing. Giving them real rows means giving
         * them trackRun first, and this pin is what will notice if one gains a
         * key without a ledgerKey() line to read it.
         */
        $untracked = collect(app(SyncSchedule::class)->tasks())
            ->where('tracked', null)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        expect($untracked)->toBe([
            'cfb:news:followed',
            'cfb:news:followed:offseason',
            'pickem:open-lobbies',
            'pickem:publish-slates',
            'pickem:settle',
        ]);
    });

    it('reports all four pickem sweeps, which the cfb prefix used to drop', function () {
        /*
         * PickemPreflight checks the four are REGISTERED; nothing answered
         * whether one actually RAN, because a display name of null drops a
         * task from tasks() before any overdue calculation happens. A dead
         * worker, a season gate evaluating wrong or a stuck overlap mutex left
         * the preflight green and this report silent — over the loop the whole
         * product turns on.
         */
        $names = collect(app(SyncSchedule::class)->tasks())->pluck('name');

        foreach (['pickem:publish-slates', 'pickem:remind', 'pickem:settle', 'pickem:open-lobbies'] as $sweep) {
            expect($names->contains(fn (string $name) => str_contains($name, $sweep)))
                ->toBeTrue("{$sweep} must reach the schedule report.");
        }

        // And the allowlist is still an allowlist: the ledger's own
        // housekeeping stays off a report about the ledger.
        expect($names->contains(fn (string $name) => str_contains($name, 'model:prune')))->toBeFalse();
    });

    it('reads a recorded pick-reminders run back onto its row', function () {
        // The half a display name alone does not buy: ledgerKey() has to map
        // the sweep to the key SendPickRemindersCommand writes under, or the
        // row renders grey over a ledger that has the answer in it.
        $run = FeedRun::begin('pick-reminders', null);
        $run->complete(0, 0, 12);

        $task = collect(app(SyncSchedule::class)->tasks())
            ->first(fn (array $task) => str_contains($task['name'], 'pickem:remind'));

        expect($task['tracked'])->toBe('pick-reminders')
            ->and($task['run']?->id)->toBe($run->id);
    });
});

describe('the detail modal', function () {
    /*
     * The surface a table test cannot reach. `assertCanSeeTableRecords` proves
     * the ROWS render; the infolist only runs when somebody opens the View
     * modal, so it shipped unrendered by anything — the same gap that let the
     * Pulse dashboard's lazy cards ship broken through a green suite.
     */
    it('renders the evidence and the prompt without falling over', function () {
        // Evidence is an `array` cast, and Filament renders an array state as
        // a LIST — calling formatStateUsing once per element, with the element,
        // not the array. A nested map arrives as an int and a `?array` hint is
        // a TypeError at exactly the moment somebody opens the item.
        $item = WorkbookItem::factory()->create([
            'title' => 'The picks screen N+1s',
            'evidence' => [
                'hits' => 214,
                'worst_ms' => 2_400,
                // Nested, because the advisor sends excerpts of a telemetry
                // snapshot and a flat key/value view would drop this entirely.
                'sample' => ['type' => 'slow_query', 'key' => 'select * from `games`'],
            ],
            'prompt' => 'Add the eager load to pickem-home and prove it with a query-count test.',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($item))
            ->assertMountedActionModalSee('214')
            ->assertMountedActionModalSee('Add the eager load');
    });

    it('renders an item with no evidence and no prompt', function () {
        // The advisor may file a finding it could not attach numbers to, and
        // a human filing by hand attaches neither.
        $item = WorkbookItem::factory()->create(['evidence' => null, 'prompt' => null]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($item))
            ->assertOk();
    });

    it('renders evidence that is a flat list, not only a map', function () {
        $item = WorkbookItem::factory()->create(['evidence' => ['one', 'two', 'three']]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($item))
            ->assertMountedActionModalSee('two');
    });
});

describe('filing and editing by hand', function () {
    it('files a human item with a key of its own', function () {
        // The advisor is the volume, not the authority — `source` says which
        // is which, and a human item still needs the unique key everything
        // else is addressed by.
        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(CreateAction::class, [
                'title' => 'The lobby needs a Saturday countdown',
                'category' => 'feature',
                'severity' => 'low',
                'status' => 'inbox',
                'body' => 'Asked for twice this week.',
            ]);

        $item = WorkbookItem::sole();

        expect($item->source)->toBe(WorkbookItem::SOURCE_HUMAN)
            ->and($item->key)->toStartWith('human-the-lobby-needs-a-saturday-countdown')
            ->and($item->first_seen_at)->not->toBeNull()
            ->and($item->status)->toBe(WorkbookStatus::Inbox);
    });

    it('lets a human answer an item from the table', function () {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make(EditAction::class)->table($item), [
                'title' => $item->title,
                'category' => $item->category->value,
                'severity' => 'critical',
                'status' => 'dismissed',
            ]);

        expect($item->fresh()->status)->toBe(WorkbookStatus::Dismissed)
            ->and($item->fresh()->severity)->toBe(WorkbookSeverity::Critical);
    });
});

describe('the activity trail', function () {
    /*
     * The point of the trail is completeness. FIVE things could write `status`
     * and four of them recorded nothing — so what these tests hold is not that
     * MoveWorkbookItem writes an event, but that every OTHER writer goes
     * through it. A trail with four holes reads as a complete record, which is
     * worse than no trail at all.
     */

    /** Everything but the `filed` row every create writes. */
    function trail(WorkbookItem $item): array
    {
        return $item->events()->where('kind', '!=', WorkbookEvent::FILED)->get()->all();
    }

    it('records the drag', function () {
        [$a] = column(WorkbookStatus::Inbox, 1);

        Livewire::actingAs($this->admin)->test(Workbook::class)
            ->call('move', (string) $a->id, 0, 'planned');

        $event = collect(trail($a))->sole();

        expect($event->kind)->toBe(WorkbookEvent::MOVED)
            ->and($event->from_status)->toBe(WorkbookStatus::Inbox)
            ->and($event->to_status)->toBe(WorkbookStatus::Planned)
            ->and($event->actor)->toBe(WorkbookEvent::ACTOR_HUMAN);
    });

    it('records a bulk move, which used to write status directly', function () {
        $items = column(WorkbookStatus::Inbox);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callTableBulkAction('move_dismissed', $items);

        foreach ($items as $item) {
            expect(collect(trail($item))->sole()->to_status)->toBe(WorkbookStatus::Dismissed);
        }
    });

    it('appends a bulk move in selection order, because null is not zero', function () {
        // `position: null` means APPEND. Read as `0` it would mean the TOP of
        // the column, silently reversing the order — and no assertion about
        // the status would notice.
        $items = column(WorkbookStatus::Inbox);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callTableBulkAction('move_planned', $items);

        expect(WorkbookItem::query()->inColumn(WorkbookStatus::Planned)->pluck('id')->all())
            ->toBe(collect($items)->pluck('id')->all());
    });

    it('records a save from the edit form, which Filament routes around the action', function () {
        // Filament saves through `$record->update($data)`. Without `using()`
        // the status lands and nothing remembers it.
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make(EditAction::class)->table($item), [
                'title' => 'Renamed while answering it',
                'category' => $item->category->value,
                'severity' => $item->severity->value,
                'status' => 'planned',
            ]);

        expect($item->fresh()->title)->toBe('Renamed while answering it')
            ->and(collect(trail($item))->sole()->to_status)->toBe(WorkbookStatus::Planned);
    });

    it('carries the reason a form save has nowhere to put', function () {
        // "Moved to planned" is a fact anyone could guess. The note is the
        // whole reason a trail is worth opening.
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make('move')->table($item), [
                'status' => 'planned',
                'note' => 'Waiting on the ESPN feed fix first.',
            ]);

        expect(collect(trail($item))->sole()->note)->toBe('Waiting on the ESPN feed fix first.');
    });

    it('says nothing about a reorder inside a column', function () {
        // A board where every nudge writes a row is a trail nobody opens,
        // which costs it the only thing it has.
        [$a, $b, $c] = column(WorkbookStatus::Inbox);

        app(MoveWorkbookItem::class)->handle($c->id, WorkbookStatus::Inbox, 0);

        expect(trail($a))->toBe([])
            ->and(trail($b))->toBe([])
            ->and(trail($c))->toBe([])
            // ...and the reorder still happened.
            ->and($c->fresh()->position)->toBe(1);
    });

    it('stamps the lifecycle a status column cannot answer on its own', function () {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned]);

        app(MoveWorkbookItem::class)->handle($item->id, WorkbookStatus::InProgress);
        $started = $item->fresh()->started_at;

        app(MoveWorkbookItem::class)->handle($item->id, WorkbookStatus::Done);

        expect($started)->not->toBeNull()
            ->and($item->fresh()->completed_at)->not->toBeNull()
            // First entry only — bouncing back must not reset how long this
            // has been being worked on.
            ->and($item->fresh()->started_at->toIso8601String())->toBe($started->toIso8601String());
    });

    it('releases the claim the moment work is finished or back in a queue', function () {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::InProgress]);
        $item->forceFill([
            'claimed_at' => now(),
            'claimed_by' => 'agent:local',
            'claim_expires_at' => now()->addHour(),
        ])->save();

        app(MoveWorkbookItem::class)->handle($item->id, WorkbookStatus::Planned);

        expect($item->fresh()->claimed_at)->toBeNull()
            ->and($item->fresh()->claimed_by)->toBeNull()
            ->and($item->fresh()->claim_expires_at)->toBeNull();
    });

    it('keeps a lease across the transition it is a lease FOR', function () {
        // In progress -> In review is a session handing its own work on. The
        // claim survives, because the work is not finished until a human merges.
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::InProgress]);
        $item->forceFill(['claimed_at' => now(), 'claimed_by' => 'agent:local'])->save();

        app(MoveWorkbookItem::class)->handle($item->id, WorkbookStatus::InReview);

        expect($item->fresh()->claimed_by)->toBe('agent:local');
    });
});

describe('the hand-off', function () {
    /*
     * Clipboard is untestable — `navigator.clipboard` needs a secure context
     * and is absent from the automated tab. So everything here asserts the
     * rendered STATE, which is the layer a test can hold.
     */

    it('reads the reference and copies the hand-off', function () {
        $item = WorkbookItem::factory()->create();

        $html = Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            // The cell READS `CFB-12`...
            ->assertTableColumnStateSet('reference', $item->reference, $item)
            ->html();

        // ...and COPIES `/work CFB-12`, which is the whole hand-off. Asserted
        // through the rendered handler, because `navigator.clipboard` needs a
        // secure context the automated tab does not have.
        expect($html)->toContain('/work '.$item->reference);
    });

    it('puts the hand-off on every card', function () {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);

        $html = Livewire::actingAs($this->admin)->test(Workbook::class)->html();

        expect($html)->toContain($item->reference)
            ->toContain('navigator.clipboard');
    });

    it('offers the next ready issue from the header, and nothing when none is', function () {
        Livewire::actingAs($this->admin)->test(Workbook::class)
            ->assertActionHidden('next');

        $ready = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned]);
        $ready->forceFill(['ready_at' => now()])->save();

        $html = Livewire::actingAs($this->admin)->test(Workbook::class)
            ->assertActionVisible('next')
            ->html();

        expect($html)->toContain('/work '.$ready->reference);
    });

    it('sorts and searches a column that does not exist', function () {
        // `reference` is derived, so both need explicit closures or MySQL
        // answers 1054 on a column that is not there.
        $first = WorkbookItem::factory()->create();
        $second = WorkbookItem::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->sortTable('reference', 'desc')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->searchTable((string) $second->id)
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first]);
    });
});

describe('sizing, labeling and readying from the panel', function () {
    it('saves an effort and a label through the form', function () {
        $item = WorkbookItem::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make(EditAction::class)->table($item), [
                'title' => $item->title,
                'category' => $item->category->value,
                'severity' => $item->severity->value,
                'status' => $item->status->value,
                'effort' => 'l',
                'labels' => ['Slow Query'],
            ]);

        expect($item->fresh()->effort)->toBe(WorkbookEffort::Large)
            // Normalized by the model's mutator, not the form — so the form,
            // the command and a factory all land on one vocabulary.
            ->and($item->fresh()->labels)->toBe(['slow-query']);
    });

    it('carries the edit form\'s note onto the trail', function () {
        // `dehydrated(false)` would have hidden this from `using()`, which is
        // the only thing that reads it.
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Inbox]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make(EditAction::class)->table($item), [
                'title' => $item->title,
                'category' => $item->category->value,
                'severity' => $item->severity->value,
                'status' => 'planned',
                'move_note' => 'Sized it first.',
            ]);

        expect($item->fresh()->events()->where('kind', WorkbookEvent::MOVED)->sole()->note)
            ->toBe('Sized it first.')
            // ...and it is never written to the row.
            ->and($item->fresh()->getAttributes())->not->toHaveKey('move_note');
    });

    it('marks an item ready, and then stops offering to', function () {
        $item = WorkbookItem::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->callAction(TestAction::make('ready')->table($item));

        expect($item->fresh()->ready_at)->not->toBeNull();

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->assertActionHidden(TestAction::make('ready')->table($item->fresh()));
    });

    it('filters the table by a label and by an effort', function () {
        $slow = WorkbookItem::factory()->create(['labels' => ['slow-query'], 'effort' => WorkbookEffort::Large]);
        $other = WorkbookItem::factory()->create(['labels' => ['frontend'], 'effort' => WorkbookEffort::Small]);

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->filterTable('label', 'slow-query')
            ->assertCanSeeTableRecords([$slow])
            ->assertCanNotSeeTableRecords([$other])
            ->removeTableFilter('label')
            ->filterTable('effort', ['s'])
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$slow]);
    });

    it('offers no bulk move to In review', function () {
        // In review means a pull request is open and waiting on a human. A
        // bulk move puts cards there without one, and a column that lies is
        // worse than a column nobody uses.
        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->assertTableBulkActionDoesNotExist('move_in_review');
    });
});

describe('the detail view, which ships rendered by nothing', function () {
    it('renders every new section, including the ones that only sometimes exist', function () {
        // Modals are the panel's blind spot: an infolist entry runs only when
        // the modal mounts, so it ships covered by no test unless driven.
        $blocker = WorkbookItem::factory()->create(['key' => 'the-blocker', 'title' => 'Fix the feed first']);
        $item = WorkbookItem::factory()->create(['effort' => WorkbookEffort::Medium, 'labels' => ['slow-query']]);
        $item->forceFill(['branch' => $item->branchName(), 'pr_url' => 'https://example.com/pull/9'])->save();

        app(LinkWorkbookItems::class)->handle($item, $blocker, WorkbookLinkType::BlockedBy);
        app(MoveWorkbookItem::class)->handle($item->id, WorkbookStatus::Planned, note: 'Worth doing.');

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($item->fresh()))
            ->assertMountedActionModalSee($item->reference)
            ->assertMountedActionModalSee($item->branchName())
            ->assertMountedActionModalSee('slow-query')
            ->assertMountedActionModalSee('Blocked by')
            ->assertMountedActionModalSee('Fix the feed first')
            ->assertMountedActionModalSee('Worth doing.');
    });

    it('organizes the whole detail view into tabs, on both surfaces', function () {
        // Four sections stacked down the page ran past the fold on a laptop,
        // and the trail — the part a session opens a card to read — was the
        // part you could never see. Both conditional tabs are asserted too:
        // a Tab with no components renders to NOTHING, so a label over an
        // empty pane is the failure mode `visible()` exists to avoid.
        $blocker = WorkbookItem::factory()->create(['key' => 'the-blocker', 'title' => 'Fix the feed first']);
        $item = WorkbookItem::factory()->create();

        app(LinkWorkbookItems::class)->handle($item, $blocker, WorkbookLinkType::BlockedBy);
        app(MoveWorkbookItem::class)->handle($item->id, WorkbookStatus::Planned, note: 'Worth doing.');

        $tabs = ['The finding', 'The work', 'Links', 'Activity'];

        $table = Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($item->fresh()));

        $board = Livewire::actingAs($this->admin)
            ->test(Workbook::class)
            ->mountAction('view', arguments: ['item' => $item->id]);

        foreach ($tabs as $tab) {
            $table->assertMountedActionModalSee($tab);
            $board->assertMountedActionModalSee($tab);
        }
    });

    it('offers no Links tab on an item that has none', function () {
        // A Tab with no components renders to NOTHING, so an unconditional
        // Links tab would be a label over an empty pane. Activity is NOT the
        // same case and never will be: filing an item writes its first trail
        // row, so that tab always has something behind it.
        $item = WorkbookItem::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($item))
            ->assertMountedActionModalSee('The finding')
            ->assertMountedActionModalSee('Activity')
            ->assertMountedActionModalDontSee('Links');
    });

    it('renders an item with no links, no claim and no branch', function () {
        // The empty case is the one that ships broken: a section whose state
        // is null renders nothing rather than an empty box.
        $item = WorkbookItem::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ManageWorkbook::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($item))
            ->assertOk()
            ->assertMountedActionModalSee('Not started');
    });
});
