<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Inbound SMS from Vonage — in practice, somebody replying STOP.
 *
 * The carriers already block the number at their end the moment somebody sends
 * STOP, so this changes nothing about whether the next message arrives. What it
 * changes is whether we keep TRYING: without it, every future send queues a job,
 * spends the carrier surcharge, and reports success into a void. Recording the
 * opt-out is how we stop paying to talk to a wall.
 *
 * ## Why this only ever turns SMS OFF
 *
 * The endpoint is public and unauthenticated — an inbound webhook has no session
 * and Vonage's signed-webhook support is optional and easy to misconfigure. So
 * rather than defend a signature, the endpoint is built so that forging it is
 * pointless: STOP is honoured, START is NOT. The worst a forged request can do
 * is stop somebody's texts, which is the same direction the user would have
 * chosen anyway.
 *
 * Turning SMS back on requires signing in, which is a deliberate asymmetry: an
 * opt-out must be easy and an opt-IN must be provably the account holder, which
 * is exactly what consent means.
 */
class SmsWebhookController extends Controller
{
    /**
     * The words carriers require to work, plus the ones people actually send.
     *
     * Matched on the whole trimmed message rather than as a substring: "stop
     * texting me about Georgia" is a complaint, but "don't stop" is not an
     * opt-out and a substring match would read it as one.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit', 'revoke', 'optout',
    ];

    public function __invoke(Request $request): Response
    {
        $from = PhoneNumber::normalize($request->input('msisdn'));
        $text = strtolower(trim((string) $request->input('text')));

        /*
         * 200 whatever happens, and early. Vonage retries a non-2xx, so an
         * unknown number or an unparseable body would otherwise become a retry
         * loop over a message there is nothing to do about.
         */
        if ($from === null || ! in_array($text, self::STOP_WORDS, true)) {
            return response()->noContent(Response::HTTP_OK);
        }

        $user = User::query()->where('phone', $from)->first();

        if ($user === null) {
            /* Worth a log line: a STOP from a number we do not hold means our
               stored format and the carrier's disagree, which would make every
               opt-out silently fail. */
            Log::warning('SMS opt-out from an unknown number.', ['from' => PhoneNumber::mask($from)]);

            return response()->noContent(Response::HTTP_OK);
        }

        /*
         * `sms_opted_in_at` is deliberately left alone. It records that consent
         * once happened — which stays true after it is withdrawn, and is what a
         * carrier asks to see when vetting the campaign.
         *
         * Idempotent: a second STOP writes the same state rather than checking
         * for it, because a carrier may well deliver one twice.
         */
        $user->forceFill(['sms_opt_in' => false])->save();

        return response()->noContent(Response::HTTP_OK);
    }
}
