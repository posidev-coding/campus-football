<?php

use App\Enums\ContentRating;
use App\Models\User;
use App\Support\Voice;

/**
 * The install walkthrough at /app, and the banner that points at it. The
 * detection itself is client-side, so these hold what a feature test can:
 * every platform's steps in the payload, the states Alpine toggles between,
 * and the seams (CSS standalone hide, localStorage dismissal) by their
 * rendered attributes.
 */
describe('the screen', function () {
    it('renders every platform walkthrough in one payload', function () {
        // Detection picks a default; it must never be the only path to the
        // steps — user agents lie, and a reader may want the steps for the
        // phone in their other hand.
        $html = $this->get(route('get-app'))->assertOk()->content();

        foreach (['ios-safari', 'ios-chrome', 'ios-firefox', 'android', 'desktop'] as $platform) {
            expect($html)->toContain('data-platform="'.$platform.'"');
        }
    });

    it('checks the Firefox badge before the Chrome badge before the Safari default', function () {
        /*
         * Every iOS browser is WebKit wearing a badge, so the badge token is
         * the whole signal — and the order is load-bearing: a UA carrying
         * FxiOS must never fall through to the CriOS test or the Safari
         * default. Pinned as source order because detection is client-side.
         */
        $html = $this->get(route('get-app'))->assertOk()->content();

        expect(strpos($html, 'FxiOS'))->not->toBeFalse()
            ->and(strpos($html, 'FxiOS'))->toBeLessThan(strpos($html, 'CriOS'));
    });

    it('focuses a detected phone down to one walkthrough, with a way back', function () {
        // The switcher yields to the detected platform's steps; the toggle is
        // the honest escape hatch, because detection is a guess wearing
        // confidence.
        $html = $this->get(route('get-app'))->assertOk()->content();

        expect($html)->toContain('Using a different browser?')
            ->and($html)->toContain('x-show="! focused()"')
            ->and($html)->toContain('showAll = true');
    });

    it('floats a pointing cue per mobile platform, decorative and phone-only', function () {
        /*
         * CSS animation only — the automated tab renders no frames, so what a
         * test can hold is the markup: one keyed cue per mobile platform,
         * hidden from `sm` up (desktop chrome positions are unpredictable),
         * bounce behind motion-safe, and never a tap target.
         */
        $html = $this->get(route('get-app'))->assertOk()->content();

        foreach (['ios-safari', 'ios-chrome', 'ios-firefox', 'android'] as $platform) {
            expect($html)->toContain('wire:key="cue-'.$platform.'"');
        }

        expect($html)->toContain('motion-safe:animate-bounce')
            ->and($html)->toContain('pointer-events-none')
            ->and(substr_count($html, 'sm:hidden'))->toBeGreaterThanOrEqual(4);

        // Pointing only when the platform on screen is the one detection
        // found: an arrow at chrome that is not there beats no arrow to death.
        expect($html)->toContain("detected === 'ios-chrome'");
    });

    it("quotes the OS's own labels verbatim in the steps", function () {
        // The user is hunting for these exact words in a real menu; the voice
        // stays out of the instructions entirely.
        $this->get(route('get-app'))
            ->assertOk()
            ->assertSee('Add to Home Screen')
            ->assertSee('Share')
            ->assertSee('Install');
    });

    it('routes iPhone Chrome and Firefox through More', function () {
        /*
         * Both browsers open the system share sheet with Add to Home Screen
         * tucked behind More on a stock action list — a step learned on a
         * real phone, which the first shipped instructions skipped. Twice:
         * once per browser, so trimming either walkthrough fails here.
         */
        $html = $this->get(route('get-app'))->assertOk()->content();

        expect(substr_count($html, 'tap <strong>More</strong> first'))->toBeGreaterThanOrEqual(2);
    });

    it('carries an already-installed state for standalone visits', function () {
        // assertSee escapes its argument the way Blade escaped the line.
        $this->get(route('get-app'))
            ->assertOk()
            ->assertSee('x-show="standalone"', escape: false)
            ->assertSee(Voice::line('install.screen.installed'));
    });

    it('offers the captured native prompt as a real button', function () {
        $html = $this->get(route('get-app'))->assertOk()->content();

        expect($html)->toContain('x-show="installReady"')
            ->and($html)->toContain('cfb:install-ready');
    });
});

describe('the banner', function () {
    it('waits for a toured member — demonstrated interest, then the pitch', function () {
        /*
         * The tour's last stop makes the install case; the banner is the
         * reinforcement AFTER it, never the opener. Dismissal is keyed per
         * user per device (localStorage, namespaced by id): install state is
         * a property of the device, and two people sharing one phone each
         * get their own answer.
         */
        $toured = User::factory()->create(['tour_completed_at' => now()]);

        $html = $this->actingAs($toured)->get(route('home'))->assertOk()->content();

        expect($html)->toContain('data-install-only')
            ->and($html)->toContain("'cfb.install.dismissed.' + ".$toured->id)
            ->and($html)->toContain(route('get-app'));
    });

    it('never pitches a guest, and not a member the tour has not finished with', function () {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('cfb.install.dismissed');

        $this->actingAs(User::factory()->create(['tour_completed_at' => null]))
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('cfb.install.dismissed');
    });

    it('is removed inside the installed app by stylesheet, not by script', function () {
        // A JS hide flashes the pitch at an installed user for the beat
        // before Alpine boots; the media query never renders it at all.
        expect(file_get_contents(resource_path('css/app.css')))
            ->toContain('display-mode: standalone');
    });

    it('gives Account a permanent path to the walkthrough', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->assertSee('Get the app')
            ->assertSee(route('get-app'));
    });
});

describe('the voice', function () {
    it('speaks each register, and never the same line up the ladder', function () {
        // LOUD chrome: all three registers exist side by side, and R actually
        // escalates rather than repeating PG-13 with a shrug.
        foreach (['install.banner.heading', 'install.banner.body', 'install.screen.heading', 'install.screen.installed'] as $key) {
            $pg = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::Pg]));
            $r = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::R]));

            expect($pg)->not->toBe('')
                ->and($r)->not->toBe('')
                ->and($pg)->not->toBe($r);
        }
    });
});
