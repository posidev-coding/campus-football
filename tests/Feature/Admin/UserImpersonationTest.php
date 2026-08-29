<?php

use App\Actions\ImpersonateUser;
use App\Actions\LeaveImpersonation;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Signing in as somebody else — custom, no package, because the guards are
 * the part worth owning.
 *
 * The load-bearing test in here is "the flag survives an /admin request". The
 * panel runs Filament's AuthenticateSession, which FLUSHES any session whose
 * stored password hash no longer matches the authenticated user — so an
 * impersonation that does not re-stamp that hash logs everybody out on the
 * first panel request after it starts. It fails as a mysterious logout, not
 * as an error, which is exactly why it is pinned here.
 */

beforeEach(function () {
    $this->admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Admin']);
    $this->admin->forceFill(['admin' => true])->save();

    /*
     * A DIFFERENT password, and this is load-bearing rather than decoration.
     *
     * UserFactory hashes 'password' once into a static and hands the same
     * string to every user it makes — so with the default fixture the admin's
     * stored hash and the target's are byte-identical, AuthenticateSession's
     * comparison passes whatever the impersonation did, and every test below
     * about the hash swap would be green over a broken implementation.
     */
    $this->member = User::factory()->create([
        'first_name' => 'Peyton',
        'last_name' => 'Manning',
        'password' => Hash::make('a-different-password'),
    ]);

    expect($this->member->getAuthPassword())->not->toBe($this->admin->getAuthPassword());
});

describe('the guards', function () {
    it('refuses to impersonate another admin', function () {
        // Impersonating a peer is an end-run around every audit trail pointed
        // at them.
        $other = User::factory()->create();
        $other->forceFill(['admin' => true])->save();

        expect(fn () => app(ImpersonateUser::class)->handle($this->admin, $other))
            ->toThrow(HttpException::class);
    });

    it('refuses to impersonate yourself', function () {
        expect(fn () => app(ImpersonateUser::class)->handle($this->admin, $this->admin))
            ->toThrow(HttpException::class);
    });

    it('refuses a non-admin actor', function () {
        $nobody = User::factory()->create();

        expect(fn () => app(ImpersonateUser::class)->handle($nobody, $this->member))
            ->toThrow(HttpException::class);
    });

    it('refuses to nest one impersonation inside another', function () {
        // A stack of impersonations has no honest way back.
        $this->actingAs($this->admin);
        session()->put('impersonator_id', $this->admin->id);

        expect(fn () => app(ImpersonateUser::class)->handle($this->admin, $this->member))
            ->toThrow(HttpException::class);
    });

    it('hides the button for an admin and for yourself', function () {
        $other = User::factory()->create();
        $other->forceFill(['admin' => true])->save();

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $other->getKey()])
            ->assertActionHidden('impersonate');

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $this->admin->getKey()])
            ->assertActionHidden('impersonate');

        // ...and offers it for an ordinary member.
        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $this->member->getKey()])
            ->assertActionVisible('impersonate');
    });
});

describe('starting one', function () {
    it('switches the signed-in user and remembers who to go back to', function () {
        $this->actingAs($this->admin);

        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        expect(Auth::id())->toBe($this->member->id)
            // The flag survives Auth::login() because that regenerates the
            // session ID but KEEPS the data.
            ->and(session('impersonator_id'))->toBe($this->admin->id);
    });

    it('re-stamps the session password hash to the target\'s', function () {
        // Leave the admin's hash in place and the first /admin request while
        // impersonating logs everybody out.
        $this->actingAs($this->admin);

        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        $stored = session('password_hash_web');

        expect($stored)->not->toBeNull()
            // Either form the middleware accepts — the guard's cookie HMAC, or
            // the raw hash it falls back to.
            ->and(
                hash_equals(Auth::guard('web')->hashPasswordForCookie($this->member->getAuthPassword()), $stored)
                    || hash_equals($this->member->getAuthPassword(), $stored)
            )->toBeTrue();
    });

    it('starts one from the panel action and lands in the app', function () {
        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $this->member->getKey()])
            ->callAction('impersonate')
            ->assertRedirect(route('home'));

        expect(session('impersonator_id'))->toBe($this->admin->id);
    });
});

describe('while impersonating', function () {
    it('renders the banner on the app, saying whose account this is', function () {
        // An impersonation nobody can tell they are inside is how an admin
        // posts as a member by accident.
        $this->actingAs($this->admin);
        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        $this->get('/')
            ->assertOk()
            ->assertSee('Signed in as Peyton Manning')
            ->assertSee('Return to admin');
    });

    it('shows no banner on an ordinary session', function () {
        $this->actingAs($this->member)
            ->get('/')
            ->assertOk()
            ->assertDontSee('Return to admin');
    });

    it('keeps the flag across an /admin request', function () {
        /*
         * The panel is the one place a session's password hash is checked at
         * all — Filament runs AuthenticateSession there and nowhere in the
         * product — so this is the request an impersonated session has to
         * survive.
         *
         * The target is a non-admin, so /admin answers 403. That is the
         * CORRECT outcome and the assertion is what happens around it: the
         * refusal must not take the session with it. A flush would drop the
         * flag and strand the admin signed in as somebody with no way back.
         *
         * Note the 403 comes from Filament's Authenticate, which runs BEFORE
         * AuthenticateSession (verified in the route stack) — so a non-admin
         * target never reaches the hash comparison on this path. The request
         * that DOES reach it is the return trip, pinned below.
         */
        $this->actingAs($this->admin);
        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        $this->get('/admin')->assertForbidden();

        expect(session('impersonator_id'))->toBe($this->admin->id)
            ->and(Auth::id())->toBe($this->member->id);
    });

    it('logs the returning admin out if the hash is NOT re-stamped', function () {
        /*
         * The fix, broken back — because this class of test passes for the
         * wrong reason more often than not.
         *
         * This walks the return path with LeaveImpersonation's re-stamp
         * removed: flag pulled, admin logged back in, session hash left as the
         * TARGET's. The admin then lands on /admin, clears Filament's
         * Authenticate (they really are an admin), reaches AuthenticateSession
         * — and the stale hash flushes the session out from under them.
         *
         * A 302 to login and a null user here is the bug this whole hash dance
         * exists to prevent; the test above it proves the real path returns 200.
         */
        $this->actingAs($this->admin);
        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        session()->pull('impersonator_id');
        Auth::login($this->admin);

        $this->get('/admin')->assertRedirect();

        expect(Auth::check())->toBeFalse();
    });
});

describe('leaving one', function () {
    it('restores the admin and lands on the target\'s admin page', function () {
        $this->actingAs($this->admin);
        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        $this->post(route('impersonation.leave'))
            ->assertRedirect('/admin/users/'.$this->member->id);

        expect(Auth::id())->toBe($this->admin->id)
            ->and(session('impersonator_id'))->toBeNull();
    });

    it('re-stamps the hash back, so the admin is not logged out on arrival', function () {
        // The redirect goes straight to /admin, which runs the middleware —
        // leaving the target's hash in the session would log the restored
        // admin out on the page they were sent to.
        $this->actingAs($this->admin);
        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        app(LeaveImpersonation::class)->handle();

        $stored = session('password_hash_web');

        expect(
            hash_equals(Auth::guard('web')->hashPasswordForCookie($this->admin->getAuthPassword()), $stored)
                || hash_equals($this->admin->getAuthPassword(), $stored)
        )->toBeTrue();

        $this->get('/admin')->assertOk();
    });

    it('fails CLOSED when the admin is gone', function () {
        // A stranded impersonation is an unlabelled account takeover. Logging
        // the session out is the only honest answer.
        $this->actingAs($this->admin);
        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        $this->admin->delete();

        expect(app(LeaveImpersonation::class)->handle())->toBeNull()
            ->and(Auth::check())->toBeFalse();
    });

    it('fails CLOSED when the admin was demoted mid-impersonation', function () {
        $this->actingAs($this->admin);
        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        $this->admin->forceFill(['admin' => false])->save();

        expect(app(LeaveImpersonation::class)->handle())->toBeNull()
            ->and(Auth::check())->toBeFalse();
    });

    it('is a no-op on a session that was never impersonating', function () {
        $this->actingAs($this->member);

        expect(app(LeaveImpersonation::class)->handle())->toBeNull()
            // ...and it did NOT log an ordinary reader out.
            ->and(Auth::id())->toBe($this->member->id);
    });

    it('needs a POST, because it changes who is signed in', function () {
        $this->actingAs($this->admin);
        app(ImpersonateUser::class)->handle($this->admin, $this->member);

        $this->get('/impersonation/leave')->assertMethodNotAllowed();
    });
});
