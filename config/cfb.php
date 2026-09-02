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
     * THE AI LAYER'S MASTER SWITCH.
     *
     * OFF, and shipped that way. Every AI surface — the stat answers in
     * Search, the written recaps, the College GameDay fallback — asks
     * `App\Support\AiBudget::allows()` before spending anything, and this is
     * the first half of that answer. False means no call is made anywhere,
     * whatever the individual feature flags say.
     *
     * Config rather than `env()` at the call site so the flip is an
     * environment change with an instant rollback, not a deploy.
     */
    'ai_enabled' => (bool) env('AI_ENABLED', false),

    /*
     * What the AI layer may spend in a calendar month, in US dollars.
     *
     * The house pattern for the third time, after mail and SMS: THE BUDGET IS
     * OURS, NOT THEIRS. The Console's own spend limit is the outer wall and it
     * arrives as an HTTP error mid-request; this one declines a call before it
     * is made, while there is still deterministic content to serve instead.
     *
     * 25 against a projected ~$9 at pilot scale, so steady state has 2-3x of
     * headroom. That is not what this is for. The real risk is a retry storm
     * or a runaway loop, neither of which announces itself until the bill
     * arrives — a 5x spike on the projection lands almost exactly here.
     *
     * ZERO OR LESS MEANS UNCAPPED, the same convention as the mail budget. A
     * real setting, not an oversight: the Console limit still applies.
     */
    'ai_monthly_budget' => (float) env('AI_MONTHLY_BUDGET', 25),

    /*
     * The two user-facing AI surfaces, each behind its own Pennant flag whose
     * closure reads the value here — the `pickem` pattern, so flipping one is
     * an environment change and `pennant:purge <flag>` afterwards.
     *
     * Separate from the budget on purpose. These say whether a FEATURE exists;
     * the budget says whether there is money. Folding them together would mean
     * resolving Pennant against a number that moves, and the database driver
     * persists a row per resolve — so the board would answer from stale rows
     * the moment spend crossed the line.
     */
    'ai_answers' => (bool) env('AI_ANSWERS', false),

    'ai_recaps' => (bool) env('AI_RECAPS', false),

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

    /*
     * WHICH BOARD `cfb:issue` and `cfb:issues` talk to.
     *
     * Unset — the default, and every environment that has not opted in — means
     * the LOCAL table, which is the behavior these commands have always had.
     * Set it to the origin of a deployment (`https://campusfootball.test`) and
     * both commands work that board over `/ops/issues` instead, carrying
     * `ops_token` in a header.
     *
     * It exists because a working checkout's database is not the board anybody
     * reads. Cards are filed against production by the advisor, so a session
     * cutting a branch here could show a card, comment on the trail and hand
     * it to review — against a table nobody looks at — and believe all three
     * landed. The remote mode is the fix; NEVER give it a fallback. A request
     * that fails is a non-zero exit, because quietly writing to the local
     * table instead is the same bug wearing a hat.
     *
     * The ORIGIN only — no path, no query, no credentials. Paths are composed
     * from named routes on the other side, and the token rides in a header
     * rather than in this string, so `CFB_BOARD_URL` is safe in a shell
     * history and this file is not a place a secret can end up by accident.
     */
    'board_url' => env('CFB_BOARD_URL'),

    /*
     * THE RELEASE STAMP — where VERSION lives.
     *
     * The file at the repository root is the version this build is running:
     * `4.0.0-beta.1`, no `v`, and the git tag is `v` plus that. The Release
     * workflow bumps and tags it on every merge to main, so nothing in the
     * app asks git, which the deployed image does not carry.
     * `App\Support\Release` reads it; a missing or unreadable file resolves
     * to null and the screens print nothing — never a number nobody chose.
     *
     * A path rather than the value itself, so a test can point it at an
     * empty file the way `upload_disk` can be pointed at a fake.
     */
    'version_file' => base_path('VERSION'),

    /*
     * The prefix on a workbook item's reference — `CFB-12`, the handle a human
     * types and a session is handed.
     *
     * The number after it is `workbook_items.id`, derived and never stored, so
     * this string is the only part of a reference anyone chooses. Changing it
     * renames every reference at once and orphans every branch already cut
     * under the old one, so treat it as set-once.
     *
     * `WorkbookItem::findByReference()` refuses any OTHER prefix rather than
     * ignoring it, so `ACME-12` pasted in from somewhere else resolves to
     * nothing instead of quietly resolving to our twelfth item.
     */
    'issue_prefix' => env('CFB_ISSUE_PREFIX', 'CFB'),

    /*
     * Where this repository lives, and the only host a pull request URL may
     * point at.
     *
     * The panel renders `pr_url` as a link an admin will click, and that URL
     * arrives over HTTP from a routine. An unconstrained one is a phishing
     * surface for free — so the validator pins the host rather than trusting
     * `url` alone.
     */
    'repo_host' => env('CFB_REPO_HOST', 'github.com/posidev-coding/campus-football'),

    /*
     * The shared secret GitHub signs its webhook bodies with.
     *
     * UNSET MEANS OFF, and off means 404 — the same rule as `ops_token`, for
     * the same reason: a webhook door nobody has configured should not exist,
     * and should not announce that it would. A secret under
     * `EnsureGithubSignature::MINIMUM_LENGTH` counts as unset.
     *
     * Set this on the repository's webhook AND here, or a merge simply does not
     * move a card — which is the pre-webhook behavior and is safe to sit in.
     */
    'github_webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),

];
