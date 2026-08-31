<?php

use App\Enums\ContentRating;
use App\Models\User;
use App\Support\Brand;
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

    it('carries the flare in the stylesheet too, so a frameless tab still paints it', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        /*
         * The glow is a plain background, not an animation, because the
         * curtain is up before Alpine and must look finished in that state.
         * The rise is `from`-only for the cfb-entry-in reason: a tab that
         * renders no frames sees the end state, which is the lockup visible.
         */
        expect($css)->toContain('.cfb-boot-glow')
            ->and($css)->toContain('var(--color-brand-lager)')
            ->and($css)->toContain('@keyframes cfb-boot-rise')
            ->and(preg_match('/@keyframes cfb-boot-rise \{\s*to\b/', $css))->toBe(0);
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

    it('pins the hold: two phrases at 1400ms, curtain down at 2900, unstamped at +600', function () {
        $html = $this->get(route('home'))->assertOk()->content();

        /*
         * 1400ms a card, of which the first 400 are still crossfading — the
         * reason the deck went from three cards to two rather than the hold
         * going from 2200 to 3300. A card that cannot be read is not a joke,
         * and a launch beat is not allowed to grow: if a third card is ever
         * wanted back, it costs seconds, and that is the decision to make.
         */
        expect($html)->toContain(', 1400)')
            ->and($html)->toContain('end(), 2900')
            ->and($html)->toContain("removeAttribute('data-boot'), 600")
            ->and($html)->toContain('Math.min(this.i + 1, 1)');
    });

    it('deals exactly two cards off the six-phrase deck', function () {
        $html = $this->get(route('home'))->assertOk()->content();

        preg_match_all('/wire:key="boot-\d"\s*>([^<]+)</', $html, $matches);
        $dealt = array_map(trim(...), $matches[1]);

        // A guest cold start renders the PG-13 deck via line()'s null-user
        // fallback — resolve the whole pool at that register and the two
        // dealt must be a subset of it.
        $pool = collect(['gates', 'chains', 'headsets', 'scores', 'turf', 'replay'])
            ->map(fn (string $key) => Voice::line("splash.boot.{$key}"))
            ->all();

        expect($dealt)->toHaveCount(2)
            ->and(collect($dealt)->every(fn (string $phrase) => in_array($phrase, $pool, true)))->toBeTrue()
            // Two cards off a six-card deck, so a launch seen hundreds of
            // times is not the same launch twice in a row.
            ->and($dealt[0])->not->toBe($dealt[1]);
    });

    it('opens on the lockup, the glow and a phrase big enough to read', function () {
        $html = $this->get(route('home'))->assertOk()->content();

        $curtain = substr($html, strpos($html, 'data-boot-splash'));

        /*
         * The lockup, not the bare mark: the curtain is up pre-Alpine and
         * that first paint is what reads as a native launch, so it is the
         * frame that has to say the app's name. The phrase is `text-lg` in a
         * two-line slot — the R deck writes sentences, and a slot that grew
         * to fit one would walk the lockup up the screen mid-beat.
         */
        expect($curtain)->toContain('cfb-boot-glow')
            ->and($curtain)->toContain('motion-safe:animate-boot-rise')
            ->and($curtain)->toContain('relative h-16 w-full')
            ->and($curtain)->toContain('text-lg')
            ->and($html)->toContain(Brand::wordmark()['main']);
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
