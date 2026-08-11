<?php

use App\Models\User;

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
        // Same `$flux.appearance` store the Account screen writes — two
        // controls, one localStorage truth.
        $this->actingAs(User::factory()->create())
            ->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('$flux.appearance', escape: false);
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
    it('sets standings tables two abreast from lg', function () {
        $this->get(route('standings'))
            ->assertOk()
            ->assertSee('lg:grid-cols-2', escape: false);
    });
});
