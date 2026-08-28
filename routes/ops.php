<?php

use App\Http\Controllers\Ops\GithubController;
use App\Http\Controllers\Ops\IssueController;
use App\Http\Controllers\Ops\TelemetryController;
use App\Http\Controllers\Ops\WorkbookController;
use App\Http\Middleware\EnsureGithubSignature;
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

        /*
         * The issue board, for a cloud routine that wants to WORK an item
         * rather than file one.
         *
         * WHY ONLY THE LIST IS SIGNED, said plainly rather than worked around.
         * `signed` protects a URL that is HANDED to a client and then lives in
         * a config file, a shell history and a log line — it binds it to this
         * exact path so a leak cannot be edited into a different one. It was
         * never doing authentication; the token is. A URL the client COMPOSES
         * itself gains nothing from a signature, and cannot carry one anyway.
         * So the fixed-path read is signed, exactly like `/ops/telemetry`, and
         * every variable-path route here is a WRITE — and writes were never
         * signed.
         *
         * What that costs: the routes become enumerable by a token holder, who
         * already reaches `/ops/workbook`, so it is not a new grant. A leaked
         * write URL is worthless without the token, and a token holder can
         * compose any URL anyway. The mitigation that matters is SCOPE — see
         * the controller for the list of things these endpoints cannot do —
         * not signing.
         */
        Route::get('issues', [IssueController::class, 'index'])
            ->middleware('signed')
            ->name('issues.index');

        // POST because it TAKES THE CLAIM, which collapses list-then-claim into
        // one call and removes the race between them.
        Route::post('issues/next', [IssueController::class, 'next'])->name('issues.next');

        /*
         * The same bounded vocabulary as the advisor's key regex, so a
         * `../../etc/passwd` probe stops at the ROUTER rather than in a
         * controller. There is no `done` route, and there never will be.
         */
        Route::post('issues/{issue}/claim', [IssueController::class, 'claim'])->name('issues.claim');
        Route::post('issues/{issue}/release', [IssueController::class, 'release'])->name('issues.release');
        Route::post('issues/{issue}/start', [IssueController::class, 'start'])->name('issues.start');
        Route::post('issues/{issue}/review', [IssueController::class, 'review'])->name('issues.review');
        Route::post('issues/{issue}/comment', [IssueController::class, 'comment'])->name('issues.comment');
    })
    ->where('issue', '[A-Za-z0-9][A-Za-z0-9-]{0,120}');

/*
 * The merge webhook — the one door that is NOT tokened, because GitHub will not
 * send our header.
 *
 * It authenticates the other way round: GitHub signs the raw body with a shared
 * secret and sends `X-Hub-Signature-256`, so {@see EnsureGithubSignature} checks
 * an HMAC over the body instead of comparing a bearer string. Same failure
 * modes as the token, deliberately — unset secret means 404, a short one counts
 * as unset, `hash_equals`, 401 with no hint.
 *
 * Same `throttle:ops` in front of it, so a flood costs a rate-limiter hit
 * rather than a signature computation per request.
 */
Route::middleware(['throttle:ops', EnsureGithubSignature::class])
    ->prefix('ops')
    ->name('ops.')
    ->group(function (): void {
        Route::post('github', GithubController::class)->name('github');
    });
