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
 *
 * The URL names WHICH LIST it is silencing. There are two — the weekly digest
 * and the pick'em loop — and they are separate consents on purpose: stopping
 * the Sunday email must not also stop being told your picks are due, or the
 * app reads as broken to somebody who only wanted less mail on a Sunday.
 * An unknown or absent list silences the newsletter, which is the one every
 * already-sent footer link points at.
 */
class UnsubscribeController extends Controller
{
    /** The list a signed link may name, mapped to the column it clears. */
    private const LISTS = [
        'newsletter' => 'newsletter_opt_in',
        'pickem' => 'pickem_notify_opt_in',
    ];

    public function __invoke(Request $request, User $user): View|Response
    {
        $column = self::LISTS[$request->string('list')->toString()] ?? 'newsletter_opt_in';

        /*
         * Idempotent on purpose. A mail client may fire its one-click more than
         * once, and a second unsubscribe must not look like a failure — so this
         * writes the same state rather than checking whether it is already set.
         *
         * `unsubscribed_at` is only stamped the FIRST time. It records that they
         * once said no, which is the thing worth keeping if they later opt back
         * in and somebody is deciding whether to re-enroll them in something.
         * It is stamped by EITHER list: the fact recorded is "this person has
         * said no to something", not which thing.
         */
        $user->forceFill([
            $column => false,
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
