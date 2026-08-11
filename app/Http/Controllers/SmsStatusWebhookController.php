<?php

namespace App\Http\Controllers;

use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Delivery receipts from Vonage.
 *
 * This is the only thing that distinguishes "Vonage accepted it and charged us"
 * from "it reached a handset" — the send API returns status 0 for both, so
 * without a receipt a message that a carrier silently dropped is
 * indistinguishable from one that arrived.
 *
 * That distinction is not academic here. A US long virtual number sending A2P
 * traffic without an approved 10DLC campaign is REJECTED by the carrier, and it
 * fails in exactly the shape that is hardest to notice: accepted upstream,
 * billed, never delivered. The `rejected` receipt is where that shows up, and
 * `err-code` is where it says why.
 *
 * ## Why this only logs
 *
 * It deliberately does NOT touch `phone_verified_at` or `sms_opt_in`. A receipt
 * describes ONE message, and most non-delivery is transient — a handset off,
 * out of coverage, roaming. Unverifying somebody's number because a single
 * message expired would quietly disable a channel they consented to, which is
 * the same shape as writing a default when a feed returns nothing.
 *
 * Acting on receipts properly needs a message log and a threshold across
 * several of them. That belongs with the volume pick'em will bring; until then
 * this is an honest record and nothing more.
 */
class SmsStatusWebhookController extends Controller
{
    /** Anything that is not this is worth a look. */
    private const DELIVERED = 'delivered';

    /**
     * Receipts that mean the carrier refused, rather than merely failed to
     * reach a handset this time. These are the ones that indicate a
     * CONFIGURATION problem — an unregistered campaign, a blocked sender —
     * rather than somebody's phone being off.
     *
     * @var list<string>
     */
    private const REFUSED = ['rejected', 'failed', 'undeliverable'];

    public function __invoke(Request $request): Response
    {
        $status = strtolower(trim((string) $request->input('status')));

        /*
         * 200 whatever arrives. Vonage retries a non-2xx, and there is nothing
         * a retry could fix about a receipt we did not understand — it would
         * only turn one unparseable payload into many.
         */
        if ($status === '' || $status === self::DELIVERED) {
            return response()->noContent(Response::HTTP_OK);
        }

        $context = [
            'status' => $status,
            'to' => PhoneNumber::mask(PhoneNumber::normalize($request->input('msisdn'))),
            'message_id' => $request->input('messageId'),
            'error_code' => $request->input('err-code'),
            'network' => $request->input('network-code'),
        ];

        /*
         * Split by severity on purpose. A refusal is ours to fix and should be
         * loud; an expiry is the world being the world and should not train
         * anybody to ignore the channel.
         */
        if (in_array($status, self::REFUSED, true)) {
            Log::error('SMS refused by the carrier.', $context);
        } else {
            Log::warning('SMS not delivered.', $context);
        }

        return response()->noContent(Response::HTTP_OK);
    }
}
