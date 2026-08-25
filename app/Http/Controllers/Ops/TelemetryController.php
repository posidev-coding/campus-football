<?php

namespace App\Http\Controllers\Ops;

use App\Support\TelemetrySnapshot;
use Illuminate\Http\JsonResponse;

/**
 * The telemetry snapshot, over HTTP — the advisor's read door.
 *
 * Identical payload to `cfb:telemetry --json`, because both ask the same
 * {@see TelemetrySnapshot}. The advisor is a Claude Code routine with no
 * database access, so this is the only way it learns anything about the
 * running app; everything else it knows, it reads out of the repository.
 *
 * SIGNED as well as token-guarded. The token is the authentication; the
 * signature is about the URL itself, which is the thing that ends up pasted
 * into a routine's configuration, a shell history and a log line. It binds the
 * URL to this exact path and query, so a leaked one cannot be edited into
 * something else, and the token means a leaked URL on its own is not enough.
 *
 * READ ONLY, and aggregate only. Nothing here writes, and the payload carries
 * no user identifiers at all — asserted in `OpsEndpointTest`, not assumed.
 */
class TelemetryController
{
    public function __invoke(TelemetrySnapshot $telemetry): JsonResponse
    {
        return response()->json($telemetry->build());
    }
}
