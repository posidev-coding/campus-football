<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Voice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Turn the weekly email off, for somebody who is almost certainly not logged in.
 *
 * The signed URL is the whole authentication story: it is bound to this user's
 * id, `signed` middleware rejects a tampered one with a 403 before this runs,
 * and there is nothing here worth forging — the only thing it can do is stop
 * emails, which is the outcome an attacker would be trying to cause anyway.
 *
 * Answers GET and POST. GET is somebody clicking the footer link; POST is
 * Gmail's and Apple Mail's own one-click control (RFC 8058), which sends no
 * session and expects a bare 200 rather than a page.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, User $user): View|Response
    {
        /*
         * Idempotent on purpose. A mail client may fire its one-click more than
         * once, and a second unsubscribe must not look like a failure — so this
         * writes the same state rather than checking whether it is already set.
         *
         * `unsubscribed_at` is only stamped the FIRST time. It records that they
         * once said no, which is the thing worth keeping if they later opt back
         * in and somebody is deciding whether to re-enroll them in something.
         */
        $user->forceFill([
            'newsletter_opt_in' => false,
            'unsubscribed_at' => $user->unsubscribed_at ?? now(),
        ])->save();

        if ($request->isMethod('post')) {
            // RFC 8058 wants a 200 and nothing else. A redirect or a rendered
            // page here is what makes a client report the unsubscribe failed.
            return response()->noContent(Response::HTTP_OK);
        }

        return view('unsubscribed', [
            'user' => $user,
            'message' => Voice::line('mail.unsubscribed', for: $user),
        ]);
    }
}
