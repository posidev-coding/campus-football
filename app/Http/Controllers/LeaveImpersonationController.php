<?php

namespace App\Http\Controllers;

use App\Actions\LeaveImpersonation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The way back out of an impersonated session — the POST behind the amber
 * banner's "Return to admin".
 *
 * A POST rather than a GET because it changes who is signed in, so it rides
 * CSRF like every other state change.
 */
class LeaveImpersonationController
{
    public function __invoke(Request $request, LeaveImpersonation $leave): RedirectResponse
    {
        // Captured BEFORE the switch: after it, the signed-in user is the
        // admin again and there is nothing left to say which account this
        // session had been wearing.
        $targetId = $request->user()?->id;

        $admin = $leave->handle();

        if ($admin === null) {
            // Failed closed — the admin is gone or demoted, and the session
            // was invalidated rather than left stranded as the target.
            return redirect()->route('home');
        }

        $target = $targetId === null ? null : User::find($targetId);

        return $target === null
            ? redirect('/admin')
            : redirect('/admin/users/'.$target->getKey());
    }
}
