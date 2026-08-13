<?php

use App\Enums\ContentRating;
use App\Models\User;
use App\Support\Voice;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * The verify notice screen's poll — the reader is standing at the door they
 * just knocked on, and the mail click happens in ANOTHER tab. checkVerified
 * is how this one finds out: it flashes verify.moment and redirects, which
 * both lands the celebration and ends the poll.
 */
describe('the waiting surface', function () {
    it('polls hot while unverified, and only exists while unverified', function () {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('wire:poll.3s="checkVerified"', escape: false);
    });

    it('bounces an already-verified visitor straight home', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('verification.notice'))
            ->assertRedirect(route('home'));
    });

    it('does nothing while the email stays unverified', function () {
        Livewire::actingAs(User::factory()->unverified()->create())
            ->test('auth.verify-email')
            ->call('checkVerified')
            ->assertNoRedirect();
    });

    it('flashes the moment and leaves when verification lands', function () {
        $user = User::factory()->unverified()->create();

        $component = Livewire::actingAs($user)->test('auth.verify-email');

        $user->forceFill(['email_verified_at' => now()])->save();

        $component->call('checkVerified')->assertRedirect(route('home'));

        // The flash must survive into the landing render — this is the
        // whole reason the poll redirects rather than just re-rendering.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-verified-celebration', escape: false);
    });
});

describe('the Home celebration', function () {
    it('agrees with itself before Alpine boots: flash present means no cloak', function () {
        /*
         * The server value feeds BOTH the Alpine initial state and the
         * conditional x-cloak (the opensToMoment pattern) — these pin the
         * two renders so pre-paint can never disagree with post-boot.
         */
        session()->flash('verify.moment', true);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->content();

        $celebration = substr($html, strpos($html, 'data-verified-celebration') - 400, 500);

        expect($celebration)->toContain('celebrated: true')
            ->and($celebration)->not->toContain('x-cloak');
    });

    it('renders cloaked and inert without the flash', function () {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->content();

        $celebration = substr($html, strpos($html, 'data-verified-celebration') - 400, 500);

        expect($celebration)->toContain('celebrated: false')
            ->and($celebration)->toContain('x-cloak');
    });

    it('is one-load by construction: gone on the reload', function () {
        // Through the REAL flow: the verify request itself flashes, the
        // next request celebrates, the one after does not. Priming the
        // flash outside a request skips an aging cycle and lies here.
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('home'));

        $landing = $this->get(route('home'))->assertOk()->content();
        $celebration = substr($landing, strpos($landing, 'data-verified-celebration') - 400, 500);

        expect($celebration)->toContain('celebrated: true');

        $reload = $this->get(route('home'))->assertOk()->content();
        $celebration = substr($reload, strpos($reload, 'data-verified-celebration') - 400, 500);

        expect($celebration)->toContain('celebrated: false');
    });
});

describe('the voice', function () {
    it('speaks every landing and celebration line in every register, escalating', function () {
        $keys = [
            'verify.landing.title', 'verify.landing.reward', 'verify.landing.body',
            'verify.landing.body_app', 'verify.celebration.body',
        ];

        foreach ($keys as $key) {
            $pg = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::Pg]));
            $r = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::R]));

            expect($pg)->not->toBe('')
                ->and($r)->not->toBe('')
                ->and($r)->not->toBe($pg);
        }
    });

    it('keeps the coach-back instruction legible in every register', function () {
        // The joke rides around the instruction, never through it.
        foreach (ContentRating::cases() as $rating) {
            $line = Voice::line('verify.landing.body', for: User::factory()->make(['content_rating' => $rating]));

            expect(str_contains($line, 'home screen'))->toBeTrue();
        }
    });
});
