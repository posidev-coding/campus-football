<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Stop impersonating and become the admin again.
 *
 * Fails CLOSED. If the admin row is gone, or has been demoted since the
 * impersonation started, this logs the session out entirely rather than
 * leaving somebody signed in as the target with no way back and no banner
 * saying why — a stranded impersonation is an unlabelled account takeover.
 */
class LeaveImpersonation
{
    /**
     * @return User|null the admin who was restored, or null when the session
     *                   was not impersonating or had to be closed
     */
    public function handle(): ?User
    {
        $adminId = session()->pull('impersonator_id');

        if ($adminId === null) {
            return null;
        }

        $admin = User::find($adminId);

        if ($admin === null || ! $admin->isAdmin()) {
            Auth::logout();
            session()->invalidate();

            return null;
        }

        Auth::login($admin);

        // The session's stored hash still belongs to the target; leave it and
        // the next /admin request logs the restored admin straight back out.
        ImpersonateUser::rememberPasswordHash($admin);

        return $admin;
    }
}
