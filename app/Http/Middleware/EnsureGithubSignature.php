<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GitHub's HMAC on the webhook door.
 *
 * The same four rules as {@see EnsureOpsToken}, for the same reasons — unset
 * means 404 rather than 403, a short secret counts as unset, `hash_equals`, and
 * one status code with no hint about which half was wrong.
 *
 * What differs is WHY there is a second middleware at all: GitHub will not send
 * our `X-Ops-Token` header. It signs the RAW REQUEST BODY with a shared secret
 * and sends `sha256=…`, so the check has to happen over the body before
 * anything parses it, and the ops token cannot be reused here.
 *
 * The body is read with `getContent()`, never re-encoded from the parsed array:
 * `json_encode(json_decode($body))` is not byte-identical to what GitHub
 * signed — key order, unicode escaping and float formatting all move — and the
 * signature would fail for reasons nobody could debug.
 */
class EnsureGithubSignature
{
    /** Below this, a configured secret is treated as no secret at all. */
    public const MINIMUM_LENGTH = 32;

    public const HEADER = 'X-Hub-Signature-256';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('cfb.github_webhook_secret');

        if (mb_strlen($secret) < self::MINIMUM_LENGTH) {
            abort(404);
        }

        $presented = (string) $request->header(self::HEADER, '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if ($presented === '' || ! hash_equals($expected, $presented)) {
            abort(401);
        }

        return $next($request);
    }
}
