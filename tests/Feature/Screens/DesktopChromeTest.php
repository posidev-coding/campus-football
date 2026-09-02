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

describe('the measure never narrows as the window widens', function () {
    it('caps no screen at lg with nothing below it', function () {
        /*
         * The completeness sweep, and the reason it is a sweep rather than
         * twelve pinned strings: `lg:mx-auto lg:w-full lg:max-w-3xl` has no
         * rung beneath it, so the column is FULL WIDTH at 1000px and 200px
         * NARROWER at 1024. Measured on /picks before this was fixed — 968px
         * of column at a 1000px window, 768px at 1024, and still 768px at
         * 1440, where `<main>` is 1408 and 640px of it sat empty.
         *
         * Twelve screens had it and nobody had noticed, because a reader
         * only sees it while dragging a window across the boundary. Engage
         * a cap at `md`, where the content box (viewport - 32) is still
         * under it, and the measure grows to the cap and then holds.
         */
        $violations = [];

        foreach (glob(resource_path('views/livewire/*.blade.php')) as $path) {
            if (str_contains(file_get_contents($path), 'lg:mx-auto lg:w-full lg:max-w-')) {
                $violations[] = basename($path);
            }
        }

        expect($violations)->toBe([], implode(', ', $violations)
            .' — caps the measure at `lg` with no rung below it, so the column'
            .' is full width at 1000px and narrower at 1024. Engage the cap at'
            .' `md` instead, where the content box is still under it.');
    });

    it('gives My Picks ONE measure, capped and unprefixed, with the spine in order', function () {
        /*
         * The lg sidecar is gone (pass 2, 2026-09-01): after the tail
         * thinned it was a 20rem column carrying one door and a bar beside
         * a spine that starved at ~648px. The personal branch is one
         * column capped at max-w-3xl — UNPREFIXED, so the cap engages the
         * moment the content box reaches it and the lg-cap sweep above
         * cannot trip — and the urgency spine keeps its source order,
         * which IS the phone order.
         */
        $source = file_get_contents(resource_path('views/livewire/pickem-home.blade.php'));

        $measure = strpos($source, 'mx-auto flex w-full max-w-3xl flex-col gap-5');
        $seats = strpos($source, 'data-tour="seats"');
        $invite = strpos($source, 'Have an invite code?');

        expect($measure)->not->toBeFalse()
            ->and($source)->not->toContain('lg:grid-cols-[minmax(0,1fr)_20rem]')
            ->and($seats)->toBeGreaterThan($measure)
            ->and($invite)->toBeGreaterThan($seats);
    });

    it('keeps the ladder below the week tail, where a tabless reader had it', function () {
        /*
         * The one branch a restructure here could break. On a TABLESS first
         * run the ladder renders inside the week flow (`! $hasTabs`), so if
         * it rose above the invite disclosure it would jump ABOVE it on a
         * phone. It sits at the foot after the invite instead; with one
         * column, source order is the whole guarantee.
         */
        $source = file_get_contents(resource_path('views/livewire/pickem-home.blade.php'));

        expect(strpos($source, 'THE LADDER belongs to Results'))
            ->toBeGreaterThan(strpos($source, 'Have an invite code?'));
    });
});

describe('flat card lists claim the width', function () {
    it('grids the lists whose own component already grids elsewhere', function (string $view, string $grid) {
        // Each of these was a flat @foreach of a card that runs two- or
        // three-up on another screen — a headline in the left third of a
        // 1096px row, and nothing in the other two.
        expect(file_get_contents(resource_path("views/livewire/{$view}.blade.php")))
            ->toContain($grid);
    })->with([
        ['home', 'grid gap-2 md:grid-cols-2 xl:grid-cols-3'],
        ['team', 'grid gap-2 md:grid-cols-2 xl:grid-cols-3'],
        ['conference', 'grid gap-2 md:grid-cols-2 xl:grid-cols-3'],
        // `lg`, not `md`, and that is room-row's own constraint: it is
        // measured to starve its name below 390px, and two-up at a 768px
        // viewport gives 356px cells. `lg` gives 484px.
        ['lobby', 'grid gap-2 lg:grid-cols-2 xl:grid-cols-3'],
        // One measure and no sidecar since pass 2, so seats go two-up at
        // `md` like the clubhouse's cards. `gap-3`, not `gap-2`: the cards
        // carry a surface, and at 8px a stack of them ran together.
        ['pickem-home', 'grid gap-3 md:grid-cols-2'],
    ]);

    it('cancels the pick surface bleed only where it sits in a column', function () {
        /*
         * `-mx-4 px-4` runs the sticky bands edge to edge while their text
         * stays on the cards, and that is right only while the surface spans
         * the PAGE. In a grid column the trailing 16px lands in the 24px
         * column gap and paints an opaque band under the sidecar's shoulder
         * — the same family as the league sheet nesting inside the game
         * screen's grid.
         *
         * A flag rather than a constant, because the two hosts differ: the
         * clubhouse opens a column, the slate builder renders the surface in
         * a centred measure where the symmetric bleed is still correct. Both
         * bands must wear it, so a second sticky block cannot be added later
         * and quietly bleed on its own.
         */
        $source = file_get_contents(resource_path('views/partials/pick-slate.blade.php'));

        /*
         * Counted off the CLASS ATTRIBUTES, not the file: the docblock above
         * them names `-mx-4` too, and a sweep that counts prose passes or
         * fails on how the comment is worded rather than on the markup.
         */
        $bands = array_values(array_filter(
            explode("\n", $source),
            fn (string $line) => str_contains($line, 'class=')
                && str_contains($line, '-mx-4')
        ));

        $unflagged = array_filter($bands, fn (string $line) => ! str_contains($line, '{{ $bleed }}'));

        expect($source)->toContain("\$bleed = \$sidecar ? 'lg:me-0 lg:pe-0' : ''")
            ->and($bands)->not->toBeEmpty()
            ->and($unflagged)->toBe([], 'a sticky band in the pick surface bleeds the page gutter without carrying {{ $bleed }}')
            // Trailing edge only: the leading edge IS the page gutter, and
            // cancelling it would pull the band off the content column.
            ->and($source)->not->toContain('lg:mx-0');

        // And the clubhouse must pass the flag in step with the column it
        // opens — a grid without the flag is the bleed, a flag without the
        // grid is a band that stops 16px short.
        $group = file_get_contents(resource_path('views/livewire/group.blade.php'));

        expect($group)->toContain("'sidecar' => \$slateSidecar")
            ->and($group)->toContain("'lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-6' => \$slateSidecar");
    });

    it('never grids the urgency-ordered zones', function () {
        /*
         * "Needs your picks" and Home's picks strip are ordered by urgency:
         * a grid makes "first" mean top-LEFT rather than top, which is a
         * weaker signal for the zone `docs/screens.md` calls the reason the
         * screen works. The zone is one hero and one count now (the
         * compact rows retired 2026-09-01), and the run from its heading
         * to the hero stays flat on purpose.
         */
        $picks = file_get_contents(resource_path('views/livewire/pickem-home.blade.php'));

        $hero = strpos($picks, 'wire:key="hero-');
        $zone = substr($picks, $hero - 1500, 1500);

        expect($hero)->not->toBeFalse()
            ->and($zone)->toContain('Needs your picks')
            ->and($zone)->not->toContain('grid-cols');
    });
});
