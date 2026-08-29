<?php

use App\Filament\Resources\Slates\Pages\ListSlates;
use App\Filament\Resources\Slates\Pages\ViewSlate;
use App\Filament\Resources\Slates\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\Slates\RelationManagers\GamesRelationManager;
use App\Filament\Resources\Slates\SlateResource;
use App\Filament\Resources\Slates\Widgets\SlateStats;
use App\Models\Contest;
use App\Models\Group;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use App\Support\Cadence;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Slates, read-only on purpose. Every write to one is an Action that does
 * something a form cannot — freeze a line, validate a lineup, pay a keyed
 * reward — so the panel shows them and never edits them.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    [$this->season, $this->week] = pickemSeasonWeek();
});

/** One slate on its own contest, so a test can make several without colliding. */
function adminSlate(array $overrides = [], ?Contest $contest = null): Slate
{
    return Slate::factory()->create(array_merge([
        'contest_id' => ($contest ?? Contest::factory()->create())->id,
        'week_id' => test()->week->id,
    ], $overrides));
}

describe('the list', function () {
    it('sorts the status column by lifecycle, not alphabetically', function () {
        /*
         * `status` is a plain string column, so an alphabetical sort is
         * draft-prelim-published-settled — which puts Preliminary ABOVE
         * Published and reads as a bug in the table rather than a sort.
         * One record per status is what makes the difference visible.
         */
        $settled = adminSlate(['status' => Slate::SETTLED, 'saturday' => '2026-09-05']);
        $prelim = adminSlate(['status' => Slate::PRELIM, 'saturday' => '2026-09-12']);
        $draft = adminSlate(['status' => Slate::DRAFT, 'saturday' => '2026-09-19']);
        $published = adminSlate(['status' => Slate::PUBLISHED, 'saturday' => '2026-09-26']);

        Livewire::actingAs($this->admin)
            ->test(ListSlates::class)
            ->sortTable('status')
            ->assertCanSeeTableRecords([$draft, $published, $prelim, $settled], inOrder: true);
    });

    it('filters by status, by exhibition and by the mode on the contest', function () {
        $woodshed = adminSlate([], Contest::factory()->woodshed()->create());
        $classic = adminSlate(['exhibition' => true]);

        Livewire::actingAs($this->admin)
            ->test(ListSlates::class)
            ->filterTable('mode', 'woodshed')
            ->assertCanSeeTableRecords([$woodshed])
            ->assertCanNotSeeTableRecords([$classic])
            ->removeTableFilter('mode')
            ->filterTable('exhibition', true)
            ->assertCanSeeTableRecords([$classic])
            ->assertCanNotSeeTableRecords([$woodshed]);
    });

    it('filters to a range of Saturdays', function () {
        $early = adminSlate(['saturday' => '2026-09-05']);
        $late = adminSlate(['saturday' => '2026-11-07']);

        Livewire::actingAs($this->admin)
            ->test(ListSlates::class)
            ->filterTable('saturday', ['from' => '2026-10-01'])
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$early]);
    });

    it('reads the group through one eager load, not one query per row', function () {
        // The Group column reaches through contest → group. Lazy loading is
        // off in production, so an unnamed relation is a 500 — and no feature
        // test catches a missing eager load, which is why this counts queries.
        foreach (range(1, 6) as $i) {
            $group = Group::factory()->create(['name' => "Group {$i}"]);
            adminSlate(['saturday' => '2026-09-05'], Contest::factory()->create(['group_id' => $group->id]));
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::actingAs($this->admin)->test(ListSlates::class)->assertOk();

        // Well under one-per-row: six slates would be 12+ extra queries if the
        // contest and group loaded lazily.
        expect($queries)->toBeLessThan(12);
    });

    it('has no create and no edit route at all', function () {
        expect(SlateResource::getPages())
            ->toHaveKeys(['index', 'view'])
            ->not->toHaveKey('create')
            ->not->toHaveKey('edit');
    });
});

describe('the record view', function () {
    it('renders the heading with the deadline resolved from this slate\'s own Saturday', function () {
        $group = Group::factory()->create(['name' => 'The Vol Network']);
        $slate = adminSlate(
            ['saturday' => '2026-09-05'],
            Contest::factory()->create(['group_id' => $group->id]),
        );
        $slate->forceFill(['status' => Slate::PUBLISHED, 'published_at' => '2026-09-02 18:00:00'])->save();

        /*
         * Derived from Cadence, never spelled out. The league clock is
         * admin-configurable from the Pick'em Settings page, so a hardcoded
         * weekday here would be a test asserting today's configuration rather
         * than the behavior — and it would fail the first time somebody moved
         * the deadline, which is a supported thing to do.
         *
         * What IS being pinned: the deadline resolves against this SLATE's own
         * Saturday, in Eastern wall time. `slates.saturday` is a date column
         * that arrives as UTC midnight, and converting rather than re-pinning
         * it lands on 8pm the previous evening — one day early, every week,
         * silently.
         */
        $due = Cadence::slateDeadline($slate->fresh()->saturday);

        expect($due->toDateString())->toBe('2026-09-03');

        Livewire::actingAs($this->admin)
            ->test(ViewSlate::class, ['record' => $slate->getKey()])
            ->assertOk()
            ->assertSee('September 5, 2026')
            ->assertSee('The Vol Network')
            ->assertSee('Published')
            ->assertSee('Picks due '.$due->format('D g:i a T'));
    });

    it('says a draft is not published rather than leaving the row blank', function () {
        $slate = adminSlate(['status' => Slate::DRAFT, 'published_at' => null]);

        Livewire::actingAs($this->admin)
            ->test(ViewSlate::class, ['record' => $slate->getKey()])
            ->assertOk()
            ->assertSee('Not published');
    });

    it('counts picks made against picks possible', function () {
        $slate = adminSlate();
        $game = SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => pickemGame($this->season, $this->week)->id,
        ]);

        SlateEntry::factory()->count(2)->create(['slate_id' => $slate->id]);
        Pick::factory()->create(['slate_game_id' => $game->id]);

        Livewire::actingAs($this->admin)
            ->test(SlateStats::class, ['record' => $slate])
            ->assertOk()
            ->assertSee('of 2 possible');
    });

    it('never divides by a slate nobody has entered', function () {
        // An unentered slate is not 0% complete — it is one nobody opened.
        $slate = adminSlate();

        Livewire::actingAs($this->admin)
            ->test(SlateStats::class, ['record' => $slate])
            ->assertOk()
            ->assertSee('nothing to pick against yet');
    });

    it('says no payouts happen in the preliminary window', function () {
        // Every game final but the week not yet official: the window where a
        // late ESPN correction can still move a tiebreaker.
        $slate = adminSlate(['status' => Slate::PRELIM]);

        Livewire::actingAs($this->admin)
            ->test(SlateStats::class, ['record' => $slate])
            ->assertOk()
            ->assertSee('no payouts');
    });
});

describe('the games on a slate', function () {
    it('flags a frozen line that no longer matches the market', function () {
        // `spread` is FROZEN at publish and `market_spread` is what the book
        // said at the same moment. They are allowed to differ; the color is
        // how that shows without reading two columns side by side.
        $slate = adminSlate();

        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => pickemGame($this->season, $this->week)->id,
            'position' => 1,
            'spread' => -6.5,
            'market_spread' => -3.5,
        ]);

        Livewire::actingAs($this->admin)
            ->test(GamesRelationManager::class, [
                'ownerRecord' => $slate,
                'pageClass' => ViewSlate::class,
            ])
            ->assertOk()
            ->assertSee('Market said -3.5');
    });

    it('says a game could not be scored rather than showing it as zero', function () {
        // Null quality means "could not be scored at publish" — a live
        // current line the frozen spread beside it no longer is. Never zero.
        $slate = adminSlate();

        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => pickemGame($this->season, $this->week)->id,
            'quality' => null,
        ]);

        Livewire::actingAs($this->admin)
            ->test(GamesRelationManager::class, [
                'ownerRecord' => $slate,
                'pageClass' => ViewSlate::class,
            ])
            ->assertOk()
            ->assertSee('Not scored');
    });
});

describe('the entries on a slate', function () {
    it('prints a negative total as the real number it is', function () {
        // final_points is SIGNED: a backfired Woodshed Lock is a real −4 and a
        // week can genuinely finish below zero.
        $slate = adminSlate();
        SlateEntry::factory()->create(['slate_id' => $slate->id, 'final_points' => -4]);

        Livewire::actingAs($this->admin)
            ->test(EntriesRelationManager::class, [
                'ownerRecord' => $slate,
                'pageClass' => ViewSlate::class,
            ])
            ->assertOk()
            ->assertSee('-4');
    });

    it('says a tiebreaker was never entered rather than rendering a zero', function () {
        // Null LOSES to any non-null at settlement, and is never substituted
        // with 0 — which would otherwise be a real, and very wrong, guess.
        $slate = adminSlate();
        SlateEntry::factory()->create(['slate_id' => $slate->id, 'tiebreaker_total' => null]);

        Livewire::actingAs($this->admin)
            ->test(EntriesRelationManager::class, [
                'ownerRecord' => $slate,
                'pageClass' => ViewSlate::class,
            ])
            ->assertOk()
            ->assertSee('Not entered');
    });
});
