<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The install-signal beacon's landing: stamps `standalone_seen_at` the first
 * time a signed-in session reports running standalone.
 *
 * First stamp wins — the `=== null` guard makes replays and racing double
 * fires converge on one honest timestamp (a second save could only move it
 * later), so the client is free to over-call. Not an app/Actions class on
 * purpose: a pure timestamp with no side effects, the same exception the
 * tour's complete() established.
 */
class StandaloneSeenController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user->standalone_seen_at === null) {
            $user->forceFill(['standalone_seen_at' => now()])->save();
        }

        return response()->noContent();
    }
}
