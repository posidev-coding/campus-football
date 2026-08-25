<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        /*
         * The two `/ops` surfaces, registered OUTSIDE the `web` group on
         * purpose. They are machine-to-machine — no user, no session, no form
         * — so cookies, session start and CSRF would all be cost with no
         * benefit, and keeping the POST out of the group means it needs no
         * CSRF exemption. An exemption is a thing somebody widens later.
         */
        then: function (): void {
            Route::group([], __DIR__.'/../routes/ops.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * One-click unsubscribe arrives as a POST from Gmail or Apple Mail
         * (RFC 8058 List-Unsubscribe-Post) with no session and therefore no
         * CSRF token. Exempting it is safe because the URL carries a signature
         * bound to the user id — `signed` is the authentication, and a tampered
         * link 403s before the controller runs.
         */
        $middleware->validateCsrfTokens(except: [
            'unsubscribe/*',
            /* Vonage posts an inbound SMS with no session and no token. Safe to
               exempt because the handler can only turn SMS off — see
               SmsWebhookController for why that is the whole defense. */
            'webhooks/sms/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * `ops/*` renders JSON as well, and it is not a nicety: the callers
         * there are machines. A validation failure that comes back as a 302 to
         * a login page tells a Claude Code routine nothing about what it got
         * wrong, and it would retry the same malformed payload every week.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('ops/*'),
        );
    })->create();
