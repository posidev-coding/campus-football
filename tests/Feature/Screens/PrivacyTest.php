<?php

use App\Enums\ContentRating;
use App\Http\Middleware\RecordPageView;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Support\HelpTopics;
use App\Support\Navigation;

/*
 * WHAT WE RECORD — the one page in the app that is a promise rather than a
 * feature, which is why almost everything asserted here is about the promise
 * matching the code that keeps it.
 *
 * It renders for a GUEST on purpose. Somebody deciding whether to sign up is
 * the reader it was written for, and a disclosure behind `auth` arrives after
 * the decision it was supposed to inform.
 */

describe('the page', function () {
    it('renders for somebody who is not signed in', function () {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('What we record')
            ->assertSee('The screens you open')
            ->assertSee('Deleting your account');
    });

    it('says the retention window the pruner actually enforces', function () {
        /*
         * TWO HALVES, AND BOTH ARE NEEDED. The rendered assertion catches
         * somebody typing a different number into the copy. The source
         * assertion catches the likelier failure: somebody typing the RIGHT
         * number today, which then becomes a promise the app quietly stops
         * keeping the day `KEEP_DAYS` moves. A constant in a sentence is the
         * only version of this that cannot drift.
         */
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee(ActivityEvent::KEEP_DAYS.' days');

        expect(file_get_contents(resource_path('views/privacy.blade.php')))
            ->toContain('ActivityEvent::KEEP_DAYS');
    });

    it('states the distinction the sensor is actually built around', function () {
        // The route NAME and not the address is the whole identity design:
        // an address carries a group id, an invite code and a query string,
        // and the name carries none of them. A page that did not say so would
        // be describing a different app.
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('never the address')
            ->assertSee('pickem.group');
    });

    it('belongs to no nav area, so no tab lights for it', function () {
        // A page reached from a link once is not a place in the product, and
        // a fifth thing lighting the Account tab would say it is.
        $claimed = collect(Navigation::areas())
            ->flatMap(fn (array $area): array => $area['routes'])
            ->contains('privacy');

        expect($claimed)->toBeFalse();
    });

    it('is counted like any other screen, which is the only honest version', function () {
        // The page explaining that screens are counted, quietly exempting
        // itself, would be a small lie sitting inside a promise.
        expect(RecordPageView::isScreenRoute('privacy'))->toBeTrue();
    });
});

describe('the doors to it', function () {
    it('is linked from the register form, before anybody has an account', function () {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('What we record about you')
            ->assertSee(route('privacy'));
    });

    it('is linked from Account, which is where somebody goes looking', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->assertSee('What we record')
            ->assertSee(route('privacy'));
    });
});

describe('the help topic', function () {
    it('answers in all three registers and points at the page', function () {
        /*
         * Three registers, because the answer rides Voice like every other
         * help topic — but all three state the same facts, and all three send
         * the reader to the one page that is authoritative. The PAGE itself
         * is deliberately register-free: it renders for a guest, who has no
         * rating, and a promise that gets funnier as somebody turns a dial up
         * is not a promise.
         */
        foreach (ContentRating::cases() as $rating) {
            $reader = User::factory()->create(['content_rating' => $rating]);

            $answer = HelpTopics::answer('account.data', $reader);

            expect($answer)->not->toBeNull()
                ->and($answer['body'])->not->toBe('')
                ->and($answer['body'])->toContain(ActivityEvent::KEEP_DAYS.' days')
                ->and($answer['href'])->toBe(route('privacy'));
        }
    });

    it('carries the days as a fill rather than a typed-out number', function () {
        // Same reason as the page: the answer and the pruner read one
        // constant, so a change to the window moves both.
        expect(HelpTopics::TOPICS)->toHaveKey('account.data');

        expect(file_get_contents(app_path('Support/Voice.php')))
            ->toContain(':days days');
    });
});
