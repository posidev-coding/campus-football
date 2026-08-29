<?php

use App\Enums\WorkbookStatus;
use App\Filament\Resources\Athletes\AthleteResource;
use App\Filament\Resources\Workbook\WorkbookResource;
use App\Models\User;
use App\Models\WorkbookItem;
use Filament\Facades\Filament;

/*
 * Panel-wide invariants, swept rather than remembered.
 *
 * Everything here was true when the panel was finished; the point of a sweep
 * is that it stays true for the sixteenth resource somebody adds in a hurry.
 * Each one is a rule the panel would otherwise lose quietly.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    $this->actingAs($this->admin);
});

/** Every resource the panel has registered. */
function panelResources(): array
{
    return array_map(
        fn ($registration): string => is_string($registration) ? $registration : $registration->getResource(),
        Filament::getPanel('admin')->getResources(),
    );
}

/** The subset that actually appears on the sidebar. */
function navigableResources(): array
{
    return array_values(array_filter(
        panelResources(),
        fn (string $resource): bool => $resource::shouldRegisterNavigation(),
    ));
}

it('gives every sidebar resource an icon and a group', function () {
    // A resource with no group falls out of the taxonomy and lands loose at
    // the top of the rail; one with no icon is invisible on the collapsed
    // rail, which is the whole point of collapsing it.
    foreach (navigableResources() as $resource) {
        expect($resource::getNavigationIcon())->not->toBeNull("{$resource} has no navigation icon")
            ->and($resource::getNavigationGroup())->not->toBeNull("{$resource} has no navigation group");
    }
});

it('puts every sidebar resource in a group the panel actually registered', function () {
    // A typo'd group string does not error — it silently creates a second,
    // unstyled group with one item in it.
    $registered = collect(Filament::getPanel('admin')->getNavigationGroups())
        ->map(fn ($group): ?string => $group->getLabel())
        ->all();

    $strays = array_values(array_filter(
        navigableResources(),
        fn (string $resource): bool => ! in_array($resource::getNavigationGroup(), $registered, true),
    ));

    expect($strays)->toBe([]);
});

it('badges only the Workbook, because badge noise is the enemy of a compact rail', function () {
    /*
     * Open work is the one count worth carrying on the sidebar: it is a queue
     * somebody is expected to empty. A badge on every table is decoration, and
     * decoration everywhere means nobody reads the one that matters.
     *
     * Asserted on which resources OVERRIDE the method rather than on what it
     * returns right now — Workbook's own badge is correctly null when the
     * inbox is empty, so a value check would pass for the wrong reason on a
     * quiet database.
     */
    $badged = array_values(array_filter(
        navigableResources(),
        fn (string $resource): bool => (new ReflectionMethod($resource, 'getNavigationBadge'))
            ->getDeclaringClass()->getName() === $resource,
    ));

    expect($badged)->toBe([WorkbookResource::class]);

    // ...and it really does count, once there is something to count.
    WorkbookItem::factory()->count(2)->create(['status' => WorkbookStatus::Inbox]);

    expect(WorkbookResource::getNavigationBadge())->toBe('2');
});

it('gives every resource table an empty state heading', function () {
    /*
     * A SOURCE sweep, because a Filament table cannot be built outside a
     * Livewire host and the alternative is instantiating every page.
     *
     * The rule it holds: an empty table with no heading renders a bare "No
     * records found", which for a panel over a sync-driven database leaves the
     * reader with the question the screen should be answering — is it broken,
     * or has the sync not run?
     */
    $offenders = [];

    foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
        $contents = file_get_contents($file);

        // A resource with no table columns has no table to empty — Contest is
        // reached record-first and never lists.
        if (! str_contains($contents, 'TextColumn::make') && ! str_contains($contents, 'ImageColumn::make')) {
            continue;
        }

        if (! str_contains($contents, 'emptyStateHeading')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('offers a create button on exactly one resource, and it is the Workbook', function () {
    /*
     * Found in a browser, not in a test — which is why it is now a test.
     *
     * A scaffolded `CreateAction` sits in every generated List/Manage page's
     * header, and removing the `create` PAGE from the resource does not remove
     * it: the button renders and opens a create modal that writes the row
     * directly. Every assertion about `getPages()` passes while it does,
     * because the button lives on the PAGE, not on the resource.
     *
     * Two real ones were shipping when this was written. Users offered "New
     * user", which would mint an account around registration — skipping the
     * handle rules, the welcome mail and onboarding. Worse, the wallet ledger
     * offered one, and that modal writes a row straight around
     * `GrantWalletEntry`, the single doorway the idempotency rule lives in.
     *
     * The Workbook is the one legitimate exception: filing an item by hand is
     * a deliberate, tested feature (`source = human`), and it is a
     * ManageRecords page whose CreateAction is a modal by design.
     */
    $withCreate = [];

    foreach (panelResources() as $resource) {
        foreach ($resource::getPages() as $registration) {
            $page = $registration->getPage();
            $source = file_get_contents((new ReflectionClass($page))->getFileName());

            if (str_contains($source, 'CreateAction::make()')) {
                $withCreate[] = class_basename($page);
            }
        }
    }

    expect($withCreate)->toBe(['ManageWorkbook']);
});

it('keeps the 35k-row table out of global search', function () {
    // A contains-LIKE over athletes on every keystroke is the slowest thing
    // the panel could do, and the product solves the same problem with a
    // prefix match on an index.
    expect(AthleteResource::canGloballySearch())->toBeFalse();
});

it('is written in American English', function () {
    /*
     * A project-wide rule, swept here for the two trees this work created.
     * Not pedantry: `game_odds.favorite_team_id` is a real column, and a
     * "favourite" written next to it is a bug rather than a spelling.
     */
    $british = ['favourite', 'colour', 'centre', 'organis', 'cancelled', 'grey', 'behaviour'];

    $files = array_merge(
        glob(app_path('Filament/**/*.php'), GLOB_BRACE) ?: [],
        iterator_to_array(new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'))),
            '/\.php$/',
        )),
        iterator_to_array(new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/filament'))),
            '/\.blade\.php$/',
        )),
    );

    $offenders = [];

    foreach ($files as $file) {
        $path = (string) $file;
        $contents = mb_strtolower(file_get_contents($path));

        foreach ($british as $word) {
            if (str_contains($contents, $word)) {
                $offenders[] = basename($path).' → '.$word;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('serves every sidebar resource\'s index page without falling over', function () {
    /*
     * The cheapest possible guard against a resource that boots but cannot
     * render — a bad relation name in modifyQueryUsing, a column reading a
     * relation nothing eager-loads. Lazy loading is off in testing for the
     * static check only, so this will not catch a missing eager load; it
     * catches everything louder than that.
     */
    foreach (navigableResources() as $resource) {
        if (! $resource::hasPage('index')) {
            continue;
        }

        $this->get($resource::getUrl('index'))
            ->assertOk();
    }
});
