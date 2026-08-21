<?php

use App\Models\User;
use Livewire\Livewire;

/**
 * The desktop and tablet chrome: additive restyles of the same mobile-first
 * markup, held here by their class strings — the layer a feature test can
 * hold, since no test runner opens a 1280px viewport.
 */
describe('the header', function () {
    it('shows the primary chips from sm, where the tab bar retires', function () {
        // The bottom nav is `sm:hidden`; area chips gated on `md:` left a
        // 640-767px window with no primary navigation at all.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('ml-4 hidden min-w-0 flex-1 sm:flex', escape: false);
    });

    it('puts appearance in the avatar menu for signed-in readers', function () {
        // Same `$flux.appearance` store the Account screen writes — one
        // shared partial, one localStorage truth. The visible "Appearance"
        // heading is the name the icon-only control needs in a menu of
        // labeled rows; only the menu renders it.
        $this->actingAs(User::factory()->create())
            ->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('$flux.appearance', escape: false)
            ->assertSee('>Appearance<', escape: false);
    });

    it('aligns the search trigger with the actions beside it', function () {
        /*
         * The palette's root has to be a flex container. `<ui-modal>` is an
         * inline custom element, so in a block root it opens a line box whose
         * strut adds descender space beneath the trigger — the root then
         * measures taller than the trigger, and the header cluster's
         * `items-center` centres that taller box, which left the icon a couple
         * of pixels above the avatar. No test runner can measure the offset,
         * so the class that prevents it is what is pinned.
         */
        Livewire::test('search')
            ->assertSeeHtml('class="flex items-center"');
    });

    it('wears the field it opens, and lets go of focus to open it', function () {
        /*
         * From `lg` the trigger takes `flux:input size="sm"`'s geometry, so
         * the header reads as the same object as the phone's Home bar — one
         * control restyled, never a second trigger.
         *
         * The two guards underneath it are what make opening on FOCUS
         * survivable, and neither is measurable from here:
         *
         *   - a native <dialog> restores focus to whatever held it when
         *     `showModal()` ran, so the trigger blurs BEFORE dispatching, or
         *     every close re-focuses it and reopens the modal — Escape
         *     included, since the dialog's `close` event fires in a later task
         *     than the focus restore and so cannot suppress it;
         *   - `showModal()` throws InvalidStateError on an open dialog, which
         *     a plain mouse click can reach on its own: it focuses the button
         *     before it fires the click.
         */
        Livewire::test('search')
            ->assertSeeHtml('lg:w-64')
            ->assertSeeHtml('Search teams, players…')
            ->assertSeeHtml('document.activeElement?.blur()')
            ->assertSeeHtml('if (dialog?.open) { return }');
    });

    it('carries the wallet chips on every screen for a signed-in reader', function () {
        // The wallet chips are app chrome from `sm` — scoreboard rather than
        // Home proves the header, not the brand bar, renders them. Walk-On is
        // the ladder's bottom rung, computed from a fresh account's zero XP.
        $this->actingAs(User::factory()->create())
            ->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('data-tour="wallet"', escape: false)
            ->assertSee('Walk-On');
    });

    it('offers a guest no appearance control it cannot anchor', function () {
        // The menu is auth-only; a guest's header has sign-in buttons instead.
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertDontSee('$flux.appearance', escape: false);
    });
});

describe('the section row', function () {
    it('restyles the chips as underlined tabs at lg', function () {
        // Two chip rows stacked in one header read as one nav wrapped onto
        // two lines; the underline makes the section level its own species.
        $this->get(route('standings'))
            ->assertOk()
            ->assertSee('lg:border-b-2', escape: false)
            ->assertSee('lg:rounded-none', escape: false);
    });
});

describe('screen branding', function () {
    it('retires the scoreboard mark once the header lockup is on screen', function () {
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('size-6 shrink-0 sm:hidden', escape: false);
    });

    it('retires the Account lockup the same way', function () {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->content();

        expect($html)->toContain('sm:hidden');
    });
});

describe('league density', function () {
    it('sets standings tables two abreast from lg and three at the widest', function () {
        // Two-up only works because this screen carries no rail: beside one,
        // the cells were 328px — narrower than the 390px phone the six-column
        // table was measured to fit.
        $this->get(route('standings'))
            ->assertOk()
            ->assertSee('lg:grid-cols-2', escape: false)
            ->assertSee('2xl:grid-cols-3', escape: false);
    });

    /*
     * These three grids live inside their screen's own `@forelse`/`@if`, so an
     * empty database renders no grid to assert against — and standing up
     * conference memberships, season stat rows and a scheduled week to prove a
     * class ladder would test the fixtures, not the layout. The column ladder
     * is a static styling decision, so it is held at the layer that owns it:
     * the view source, the same layer ChromeConsistencyTest sweeps.
     */
    it('columns the team index up to four', function () {
        // Single-line rows, no rail — the most columns anywhere in the app.
        expect(file_get_contents(resource_path('views/livewire/teams.blade.php')))
            ->toContain('grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4');
    });

    it('columns the stat boards to three beside the rail', function () {
        expect(file_get_contents(resource_path('views/livewire/stats.blade.php')))
            ->toContain('grid gap-3 lg:grid-cols-2 2xl:grid-cols-3');
    });

    it("columns home's slate to three inside the rail column", function () {
        expect(file_get_contents(resource_path('views/livewire/home.blade.php')))
            ->toContain('grid gap-2 sm:grid-cols-2 xl:grid-cols-3');
    });
});

describe('the structural screens', function () {
    it('gives Account two columns while the drag list stays one', function () {
        // `wire:sort` must keep a single-column list: SortableJS reports an
        // index, and a grid reflow makes that index mean something else.
        $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->assertSee('lg:grid lg:grid-cols-2', escape: false)
            ->assertSee('wire:sort="reorder"', escape: false);
    });

    it('sidecars the game screen without nesting the league sheet', function () {
        /*
         * The sheet must stay a SIBLING of the scorebug. The scorebug carries
         * `backdrop-blur`, and a backdrop-filter is a containing block for
         * `fixed` descendants — a sheet inside the grid would resolve
         * `inset-0` against the scorebug instead of the viewport and open as
         * a strip, exactly as full-screen search once did.
         */
        $source = file_get_contents(resource_path('views/livewire/game.blade.php'));

        $grid = strpos($source, 'lg:grid-cols-[minmax(0,1fr)_20rem]');
        $sheet = strpos($source, "@include('partials.game-league-sheet')");

        expect($grid)->not->toBeFalse()
            ->and($sheet)->toBeGreaterThan($grid);
    });

    it('columns the long index screens only where the sentinel still clears', function (string $view) {
        // `xl` and not `lg`: two columns halve a chunk's ~3,200px push to
        // ~1,600px, which still clears a viewport plus the 600px margin.
        // Three would leave ~1,067px and let the observer re-enter early.
        expect(file_get_contents(resource_path("views/livewire/{$view}.blade.php")))
            ->toContain('-mt-1 grid gap-1.5 xl:grid-cols-2')
            ->toContain('wire:key="load-more"');
    })->with(['players', 'recruiting']);

    it('centres the article measure rather than stranding it left', function () {
        expect(file_get_contents(resource_path('views/livewire/article.blade.php')))
            ->toContain('article-body lg:mx-auto');
    });
});
