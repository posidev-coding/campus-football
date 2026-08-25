<?php

return [

    /*
     * The season the app considers current. Everything user-facing defaults to
     * this; sync commands can target any year.
     */
    'season' => (int) env('CFB_SEASON', 2025),

    /*
     * Contest scheduling, slate eligibility, and lock times are all evaluated
     * here — never in UTC. A CFB season spans EDT and EST, so this must stay a
     * named zone rather than a fixed offset.
     */
    'timezone' => env('CFB_TIMEZONE', 'America/New_York'),

    /*
     * Where user uploads live — brand overrides and avatars.
     *
     * `public` locally, `r2` on Laravel Cloud, whose local filesystem is
     * ephemeral and would drop an upload on the next deploy. One key rather
     * than a disk name spread across call sites, so the move is reversible and
     * a test can point it at a fake.
     *
     * The SHIPPED brand is not affected by this and must not be: those files
     * are in git under public/brand and are read from the local filesystem, so
     * a favicon never depends on a network call.
     */
    'upload_disk' => env('UPLOAD_DISK', 'public'),

    /*
     * THE PICK'EM FLIP.
     *
     * The `pickem` Pennant flag reads this: false keeps the real surfaces to
     * admins and everybody else on the coming-soon screen, true opens them to
     * every signed-in user. It lives in config rather than in the flag's
     * closure so the launch is an environment change with an instant rollback,
     * not a deploy — and so `pickem:preflight` can REPORT the flag's state
     * without resolving Pennant and writing a row as a side effect of asking.
     *
     * Run `php artisan pickem:preflight` before setting it. A flag opened over
     * an unstocked public floor lands a new user in an empty room, which is
     * the one first impression that cannot be taken back.
     */
    'pickem_open' => (bool) env('PICKEM_OPEN', false),

    /*
     * Whether a pick reminder may also go out by SMS.
     *
     * OFF, and shipped that way on purpose. The path is wired and tested and
     * the consent gate (User::canReceiveSms) already refuses anyone who has
     * not verified a number and said yes — but a recurring weekly text is
     * money and a carrier-complaint surface in a way an email is not, and
     * nobody has verified a number yet. Flipping this is a decision to take
     * once the pilot is real, not a default to discover.
     */
    'pickem_reminder_sms' => (bool) env('PICKEM_REMINDER_SMS', false),

    /*
     * How many emails a day we will spend on things nobody asked for
     * individually — the newsletter, and later any digest.
     *
     * Below the provider's own ceiling on purpose, so the headroom is what
     * transactional mail spends and a blast can never leave a password reset
     * with nowhere to go. Same reasoning as ESPN_RATE_LIMIT: the budget is
     * ours, not theirs.
     *
     * Starts LOW because Cloudflare gives a new account a deliberately
     * conservative daily quota and raises it as sending reputation builds — so
     * the first newsletter is the run most likely to meet a ceiling.
     * ThrottleMail releases rather than fails, so anything over it arrives
     * tomorrow instead of erroring. Raise as the account settles.
     */
    'mail_daily_budget' => (int) env('MAIL_DAILY_BUDGET', 100),

    /*
     * The same idea for SMS, and the first budget here that is about MONEY
     * rather than somebody else's rate limit: a message costs about a cent
     * all-in once the carrier surcharge is counted, so a loop that would merely
     * be rude against the ESPN feed is a bill against this one.
     */
    'sms_daily_budget' => (int) env('SMS_DAILY_BUDGET', 200),

    /*
     * THE OPS TOKEN — the shared secret behind /ops/telemetry and
     * /ops/workbook, the only externally-reachable surfaces the AI layer adds.
     *
     * They exist because the maintenance advisor is a Claude Code routine
     * running in somebody else's cloud with no database access: it reads a
     * telemetry snapshot over HTTP and files workbook items back. There is no
     * user and no session on either call, so a bearer secret is the whole
     * authentication.
     *
     * UNSET MEANS OFF, and off means 404 rather than 403 — an ops surface
     * nobody has configured should not exist, and should not announce that it
     * would exist if you guessed the token. A token below
     * `EnsureOpsToken::MINIMUM_LENGTH` counts as unset for the same reason:
     * `OPS_TOKEN=test` in an environment file is how a secret stops being one.
     *
     * Config rather than `env()` at the call site, the house pattern, so
     * rotating it is an environment change with an instant rollback and a test
     * can set it without touching the environment.
     */
    'ops_token' => env('OPS_TOKEN'),

];
