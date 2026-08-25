<?php

use App\Http\Controllers\Ops\TelemetryController;
use App\Http\Controllers\Ops\WorkbookController;
use App\Http\Middleware\EnsureOpsToken;
use Illuminate\Support\Facades\Route;

/*
 * The maintenance advisor's two doors — the ONLY externally-reachable surfaces
 * the AI layer adds.
 *
 * They exist because the advisor is a Claude Code routine running in somebody
 * else's cloud: it can read this repository and it can make HTTP calls, but it
 * has no database. So it reads a telemetry snapshot through one door and files
 * workbook items back through the other.
 *
 * DELIBERATELY OUTSIDE THE `web` GROUP, registered from bootstrap/app.php.
 * There is no user, no session and no form here, so cookies, session start and
 * CSRF are all cost with no benefit — and being outside the group means the
 * POST needs no CSRF exemption, which is the kind of exemption that gets
 * widened later by somebody in a hurry.
 *
 * Every route carries the same three guards, in this order:
 *
 *   throttle        a bounded number of attempts, before anything else runs
 *   EnsureOpsToken  the shared secret; unset config means 404, not 403
 *   signed          on the READ only — see the controller for why
 */
Route::middleware(['throttle:ops', EnsureOpsToken::class])
    ->prefix('ops')
    ->name('ops.')
    ->group(function (): void {
        /*
         * The snapshot. `signed` on top of the token because this URL is the
         * thing that will end up pasted into a routine's configuration, a
         * terminal history and a log line — the signature binds it to this
         * exact path and query so a leaked URL cannot be edited into a
         * different one, and the token means a leaked URL alone is not enough.
         */
        Route::get('telemetry', TelemetryController::class)
            ->middleware('signed')
            ->name('telemetry');

        /*
         * The write. NOT signed: the advisor composes this request itself
         * rather than following a URL somebody handed it, and a signature over
         * a POST body is not what `signed` checks anyway. The token, the
         * throttle, and a validator that accepts only the bounded vocabulary
         * are the guards — see the controller.
         */
        Route::post('workbook', WorkbookController::class)->name('workbook');
    });
