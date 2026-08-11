<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
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
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
