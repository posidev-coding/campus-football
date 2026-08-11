<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
     * Cloudflare Email Service — the mail transport in production.
     *
     * `key` is an API token scoped to email sending, not the global API key.
     * The framework's CloudflareTransport accepts `token` or `key`; this uses
     * the name Laravel's own documentation uses.
     */
    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'key' => env('CLOUDFLARE_KEY'),
    ],

    /*
     * Vonage is deliberately absent here. The notification channel ships its
     * own `config/vonage.php` and reads `vonage.api_key` / `vonage.api_secret`
     * / `vonage.sms_from` — from the same VONAGE_* env vars — so an entry here
     * would be config nothing reads, which is worse than no entry at all: it
     * is the file somebody would edit when SMS stops working.
     *
     * `VONAGE_SIGNATURE_SECRET` is the one worth knowing about; it is what
     * would let the inbound webhook verify a request really came from Vonage.
     * SmsWebhookController does not need it — it only ever turns SMS off —
     * but it is there if the webhook ever grows a second job.
     */
    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
