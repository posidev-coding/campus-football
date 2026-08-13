<?php

use App\Enums\ContentRating;
use App\Models\User;
use App\Support\Voice;

/*
 * The Picks coming-soon screen — Pick'em's front door, shipped ahead of
 * Pick'em so the fifth tab has a destination and the tour has a stop.
 */

describe('the screen', function () {
    it('renders the promise for a guest', function () {
        // Public like every area except Account: the tab is in a guest's bar,
        // and a tab that 403s is worse than no tab.
        $this->get(route('picks'))
            ->assertOk()
            ->assertSee("Pick'em", escape: false)
            ->assertSee('Coming soon')
            ->assertSee('Weekly slates')
            ->assertSee('Groups');
    });

    it('renders the same promise signed in', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('picks'))
            ->assertOk()
            ->assertSee("Pick'em", escape: false)
            ->assertSee('Coming soon');
    });

    it('lights its own tab rather than borrowing another area', function () {
        $this->get(route('picks'))
            ->assertOk()
            ->assertSee('aria-current="page"', escape: false);
    });

    it('explains the verification gate to the unverified, right at the gate', function () {
        /*
         * Verification's ONE gate is participation — picks and XP — so the
         * explanation lives here, and it is not dismissable: an explanation
         * you can dismiss becomes a mystery next visit.
         */
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('picks'))
            ->assertOk()
            ->assertSee('get in the game')
            ->assertDontSee('cfb.verify.dismissed');
    });

    it('shows no gate to the verified or to guests', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('picks'))
            ->assertOk()
            ->assertDontSee('data-verify-callout');

        $this->get(route('picks'))
            ->assertOk()
            ->assertDontSee('data-verify-callout');
    });
});

describe('the voice', function () {
    it('pitches in each register, and never the same line up the ladder', function () {
        // Pick'em is a LOUD surface even before it exists: the pitch carries
        // the personality, while the feature cards stay plain promises.
        $pg = Voice::line('picks.screen.pitch', for: User::factory()->make(['content_rating' => ContentRating::Pg]));
        $r = Voice::line('picks.screen.pitch', for: User::factory()->make(['content_rating' => ContentRating::R]));

        expect($pg)->not->toBe('')
            ->and($r)->not->toBe('')
            ->and($pg)->not->toBe($r);
    });
});
