<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bearer secret on the two `/ops` surfaces.
 *
 * These are the only externally-reachable endpoints the AI layer adds, and
 * they carry no session and no user: the maintenance advisor is a Claude Code
 * routine in somebody else's cloud with no database access, so it reads a
 * telemetry snapshot over HTTP and files workbook items back. A shared secret
 * is the whole authentication, which is why the rules below are worth stating
 * rather than assuming.
 *
 * 1. **Unset means the surface does not exist.** No token configured → 404,
 *    not 403. A 403 tells an unauthenticated stranger there is something here
 *    worth guessing at; a 404 tells them nothing. Fails CLOSED: the naive
 *    version of this compares a null header against a null config and lets
 *    everybody through.
 * 2. **A short token counts as unset.** `OPS_TOKEN=test` is how a secret stops
 *    being one, and an ops endpoint is not the place to discover that.
 * 3. **`hash_equals`**, so a wrong token cannot be narrowed one byte at a time.
 * 4. **401, and nothing else.** No hint about which half was wrong.
 */
class EnsureOpsToken
{
    /** Below this, a configured token is treated as no token at all. */
    public const MINIMUM_LENGTH = 32;

    public const HEADER = 'X-Ops-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('cfb.ops_token');

        if (mb_strlen($expected) < self::MINIMUM_LENGTH) {
            abort(404);
        }

        $presented = (string) $request->header(self::HEADER, '');

        // Length-checked first: hash_equals returns false on a length mismatch
        // anyway, but comparing an empty string against the real one is a
        // pointless hash of the secret on every drive-by request.
        if ($presented === '' || ! hash_equals($expected, $presented)) {
            abort(401);
        }

        return $next($request);
    }
}
