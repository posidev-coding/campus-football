<?php

namespace App\Actions;

use App\Models\User;
use BadMethodCallException;
use Illuminate\Support\Facades\Auth;

/**
 * Sign in as somebody else, to see what they see.
 *
 * Custom rather than a package, because the whole feature is four lines of
 * session work and three guards — and the guards are the part worth owning.
 *
 * The banner that makes an impersonated session obvious lives in the PRODUCT
 * layout, not here: an impersonation nobody can tell they are inside is how an
 * admin ends up posting as a member by accident.
 */
class ImpersonateUser
{
    public function handle(User $admin, User $target): void
    {
        /*
         * The guards ARE the API. Never a non-admin actor, never another
         * admin (impersonating a peer is an end-run around every audit trail
         * pointed at them), never yourself, and never nested — a stack of
         * impersonations has no honest way back.
         */
        abort_unless($admin->isAdmin(), 403);
        abort_if($target->isAdmin() || $admin->is($target), 403);
        abort_if(session()->has('impersonator_id'), 403);

        session()->put('impersonator_id', $admin->id);

        // Regenerates the session ID but KEEPS the data, which is what lets
        // the flag above survive the switch.
        Auth::login($target);

        $this->rememberPasswordHash($target);
    }

    /**
     * Re-stamp the session's password hash for whoever is signed in now.
     *
     * The panel runs Filament's AuthenticateSession (Illuminate's, with a
     * different redirect), and it FLUSHES any session whose stored hash does
     * not match the authenticated user. That check is the panel's alone —
     * nothing in the product runs this middleware — so the hash only ever
     * matters on the way into /admin.
     *
     * Where it bites is the RETURN trip, which is why LeaveImpersonation
     * calls this too: an admin coming back lands on /admin, clears Filament's
     * Authenticate because they really are an admin, and is then logged out by
     * the target's stale hash. (Measured 2026-08-28: without the re-stamp that
     * request is a 302 to login with a null user. The test pins it by breaking
     * it back.)
     *
     * Calling it on the way IN is belt and braces rather than the load-bearing
     * half: Filament's Authenticate 403s a non-admin target BEFORE
     * AuthenticateSession runs, and the middleware's own end-of-request
     * `storePasswordHashInSession()` already re-stamps for whoever the guard
     * finished as. It stays because it makes the invariant true at the moment
     * the switch happens rather than as a side effect of the framework's
     * ordering, which is a thing a Filament upgrade could quietly change.
     *
     * The value mirrors the middleware's own storage exactly — the guard's
     * HMAC when it offers one, the raw hash when it does not. The middleware
     * VALIDATES either form, so the raw hash alone would pass today; storing
     * what the framework stores means this does not depend on that
     * backward-compatibility branch staying.
     */
    public static function rememberPasswordHash(User $user): void
    {
        $hash = $user->getAuthPassword();

        try {
            $hash = Auth::guard('web')->hashPasswordForCookie($hash);
        } catch (BadMethodCallException) {
            // A guard with no cookie hashing — the raw value is the canonical
            // one there, and the middleware compares it as such.
        }

        session()->put('password_hash_web', $hash);
    }
}
