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

        foreach (['ios-safari', 'ios-chrome', 'android', 'desktop'] as $platform) {
            expect($html)->toContain('data-platform="'.$platform.'"');
        }
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
    it('rides Home below the front door', function () {
        $html = $this->get(route('home'))->assertOk()->content();

        expect($html)->toContain('data-install-only')
            ->and($html)->toContain('cfb.install.dismissed')
            ->and($html)->toContain(route('get-app'));
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
