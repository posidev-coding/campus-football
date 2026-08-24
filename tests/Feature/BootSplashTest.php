<?php

use App\Enums\ContentRating;
use App\Models\User;
use App\Support\Voice;

/**
 * The cold-start boot splash. Whether it SHOWS is decided by stylesheet
 * before Alpine exists (the head stamps `data-boot` pre-paint on cold
 * standalone loads only), so what a feature test can hold is the contract's
 * three written halves: the stamp script, the CSS gate with its dead-man,
 * and the component's timing literals — pinned so a retune has to be a
 * decision, the GuidedSetupTest precedent.
 */
describe('the cold-start stamp', function () {
    it('stamps only real document loads, on both layouts', function (string $path) {
        /*
         * `cfbAppDepth === undefined` is the cold-load detector: the depth
         * counter defined LOWER in the head does not exist yet on a real
         * load, and already does on any navigate-hop re-evaluation — so a
         * hop can never stamp. A RELOAD is a real document load that
         * deliberately does not stamp either — see the navigation-type pin
         * below; pull-to-refresh's puck is that gesture's whole experience.
         */
        $this->get($path)
            ->assertOk()
            ->assertSee('window.cfbAppDepth === undefined', false)
            ->assertSee("setAttribute('data-boot', '')", false);
    })->with([
        'app layout' => '/',
        'auth layout' => '/login',
    ]);

    it('consults the navigation type, so a pull-to-refresh reload never stamps', function (string $path) {
        /*
         * In standalone there is no reload chrome, so `type === 'reload'`
         * is a near-exact proxy for "the user pulled" — and the pull's own
         * spinner puck is the refresh experience, not a launch curtain.
         * Cold open, re-open, notification deep-link and the
         * post-onboarding redirect arrive as navigate/back_forward and
         * still stamp. The `?.` fails OPEN on an engine without the entry.
         */
        $this->get($path)
            ->assertOk()
            ->assertSee("performance.getEntriesByType('navigation')[0]?.type !== 'reload'", false);
    })->with([
        'app layout' => '/',
        'auth layout' => '/login',
    ]);

    it('gates and bails in the stylesheet, where no dead JS can strand it', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        // The 8s bail is the built-in exit: standalone has no reload
        // chrome, so a curtain JS never clears must clear itself.
        expect($css)->toContain(':root[data-boot] [data-boot-splash]')
            ->and($css)->toContain('cfb-boot-bail')
            ->and($css)->toContain('8s');
    });
});

describe('the curtain', function () {
    it('renders on both layouts, last in body, wearing the forced-dark grammar', function () {
        $html = $this->get(route('home'))->assertOk()->content();

        // Last in DOM is the z-tie-breaker over the tour scrim and the
        // pull-to-refresh puck at the same z-50.
        expect($html)->toContain('data-boot-splash')
            ->and($html)->toContain('class="dark fixed inset-0 z-50')
            ->and(strpos($html, 'data-boot-splash'))->toBeGreaterThan(strpos($html, 'data-pull-to-refresh'));

        $this->get(route('login'))->assertOk()->assertSee('data-boot-splash', escape: false);
    });

    it('pins the hold: three phrases at 750ms, curtain down at 2200, unstamped at +600', function () {
        $html = $this->get(route('home'))->assertOk()->content();

        expect($html)->toContain(', 750)')
            ->and($html)->toContain('end(), 2200')
            ->and($html)->toContain("removeAttribute('data-boot'), 600");
    });

    it('deals exactly three cards off the six-phrase deck', function () {
        $html = $this->get(route('home'))->assertOk()->content();

        preg_match_all('/wire:key="boot-\d"\s*>([^<]+)</', $html, $matches);
        $dealt = array_map(trim(...), $matches[1]);

        // A guest cold start renders the PG-13 deck via line()'s null-user
        // fallback — resolve the whole pool at that register and the three
        // dealt must be a subset of it.
        $pool = collect(['gates', 'chains', 'headsets', 'scores', 'turf', 'replay'])
            ->map(fn (string $key) => Voice::line("splash.boot.{$key}"))
            ->all();

        expect($dealt)->toHaveCount(3)
            ->and(collect($dealt)->every(fn (string $phrase) => in_array($phrase, $pool, true)))->toBeTrue();
    });
});

describe('the deck', function () {
    it('speaks every boot phrase in every register, escalating', function () {
        foreach (['gates', 'chains', 'headsets', 'scores', 'turf', 'replay'] as $key) {
            $pg = Voice::line("splash.boot.{$key}", for: User::factory()->make(['content_rating' => ContentRating::Pg]));
            $r = Voice::line("splash.boot.{$key}", for: User::factory()->make(['content_rating' => ContentRating::R]));

            expect($pg)->not->toBe('')
                ->and($r)->not->toBe('')
                ->and($r)->not->toBe($pg);
        }
    });
});
