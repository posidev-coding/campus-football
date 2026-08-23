# Operations: queues, storage, delivery and commands

Where each store lives, how work is queued and throttled, how mail and SMS
go out, and the commands that drive it all.

## Which store lives where, and the two queue tables Redis does not replace

    cache + locks   redis      CACHE_STORE, connection `cache`, DB 1 — always
    queue           cloud on Laravel Cloud (managed queues, set at deploy);
                    redis locally, connection `default`, DB 0
    batching        MYSQL      job_batches
    failed jobs     MYSQL      failed_jobs (locally; Cloud has its own view)
    sessions        MYSQL      SESSION_DRIVER=database

`cache`, `cache_locks` and `jobs` are gone from the migrations — Redis holds
all three. **`job_batches` and `failed_jobs` are not**, and that is the part
worth knowing: `queue.batching` and `queue.failed` are configured SEPARATELY
from the queue connection and both default to the database, so a redis queue
still writes them. `cfb:summaries` dispatches a real `Bus::batch`, so dropping
`job_batches` breaks the backfill rather than merely losing bookkeeping.

**`cache:clear` calls `flushdb()`** — it wipes the whole Redis database for
the cache connection, ignoring key prefixes. Cache sits on connection `cache`
(`REDIS_CACHE_DB`, database 1) and everything else on `default` (database 0),
so clearing the cache is safe today. If sessions ever move to Redis on a
managed instance that exposes only database 0, `cache:clear` becomes a
site-wide logout — check `SESSION_CONNECTION` before making that move.

It is also the **only source of historical players.** Rosters publish the
current season only, so a 2021 player has no roster row to have come from; box
scores name everyone who took a snap.

Two shapes in that payload to respect:

- Player lines are POSITIONAL arrays, but a parallel `keys[]` names each slot.
  Zip them; never index `stats[0]`.
- Box scores contain pseudo-athletes with **negative ids** and the name "Team"
  (sack yardage charged to the team). `athletes.id` is unsigned, so inserting one
  fails outright. Skip `id <= 0`.

## The ops layer: feed_runs is the ledger, sync_runs is cfb:migrate's alone

Every recurring `cfb:*` command wraps its work in `TracksFeedRun::trackRun()`,
which writes one **feed_runs** row per invocation — records, ESPN requests
(the same singleton counter the console line prints), duration, error, and
the batch id for fan-out commands — then RETHROWS on failure so scheduler
exit codes still mean what they meant. Pruned after a fortnight. `sync_runs`
is a different thing and must stay one: cfb:migrate's resume ledger, unique
per (step, season), overwritten on re-run.

`App\Support\CoverageReport` is the expected-vs-actual layer — team stats,
summaries, standings (FBS+FCS members ONLY: D2/D3's 400 conference members
carry zero standings by design, and counting them turns a healthy 265/265
into a red 265/796), rosters, aggregates, predictor coverage, freshness.
Shared verbatim by the Filament **Sync Health** page and `cfb:doctor`
(non-zero exit on any failure), so the panel and the terminal cannot
disagree. Every check names its remedy command.

The Sync Health page introspects `Schedule::events()` rather than keeping a
second registry — but routes/console.php only loads when the CONSOLE kernel
bootstraps, so in an HTTP request the resolved Schedule is empty until
`app(Console\Kernel::class)->bootstrap()` runs. That is safe precisely
because the schedule file is guaranteed side-effect-free while loading. The
overdue flag comes from each event's own cron expression, evaluated only when
its filters pass, so August does not flag offseason-gated tasks. Manual
triggers dispatch from a curated allowlist — the options ARE the validation.

The chrome-consistency sweeps exclude `filament/` views: the admin panel
renders inside Filament's design system, and the phone-first rules enforced
on an admin table is the right rule on the wrong product.

**The panel does NOT load `resources/css/app.css`, so Tailwind utilities
written in an admin view have no definitions behind them.** The first Sync
Health page laid itself out with `grid grid-cols-2 gap-4` and `flex
items-center gap-3` and rendered as one unaligned column — every class
silently absent, which reads as bad design rather than a missing stylesheet.
So the page is built entirely from Filament's own widgets and tables, which
carry their own CSS: a `StatsOverviewWidget` for spend, `TableWidget`s with
`->records(array)` for the computed coverage and schedule rows, and a normal
Eloquent table for failures. Anything genuinely custom needs a Filament theme
registered first. Page-scoped widgets set `protected static bool $isDiscovered
= false` so they do not also appear on the dashboard — and their content is
NOT in the page's own HTML, so a test must target the widget class, not the
page.

## `queue:work --memory` is useless below PHP's own limit

Ordering matters and getting it wrong looks like the guard simply not working:

    PHP memory_limit   512M   the hard kill, mid-job, no cleanup
    --memory           200    Laravel's graceful restart, checked BETWEEN jobs

Laravel only checks its threshold between jobs. With the CLI default of 128M,
`--memory=256` can never fire — PHP kills the process first. Run summary workers
as `php -d memory_limit=512M artisan queue:work --memory=200`.

Game summaries need this because memory grows roughly a megabyte per game and
never comes back inside one process. A job per game plus a recycling worker is
the fix; a longer loop is not.

## Bus batches need the Batchable trait

`Illuminate\Foundation\Queue\Queueable` does NOT include it, so `$this->batch()`
is a fatal error at run time rather than a compile-time one. Any job dispatched
in a batch that checks for cancellation must `use Batchable` explicitly.

## Uploads live on R2, and S3-compatible is not S3

`config('cfb.upload_disk')` chooses where user uploads go — `public` locally,
`r2` on Laravel Cloud, whose own filesystem does not survive a deploy. One key,
three call sites (`Brand::asset`, `Brand::bytes`, the Filament FileUpload), so
the move is one env var to undo.

**The SHIPPED brand is not on it and must not be.** `Brand::bytes()` reads
`public_path()` for the git-tracked defaults, so a stock install's favicon never
depends on a bucket being reachable — which is the state every fresh clone and
every CI run is in.

Four things R2 does differently, and none of them announces itself:

- **The S3 endpoint is AUTHENTICATED.** `Storage::url()` composes a URL from the
  endpoint when `AWS_URL` is unset, and every image 401s. Laravel Cloud does not
  inject `AWS_URL` even for a public bucket — it has to be copied in by hand, and
  it is the only thing deciding what an asset URL looks like, so pointing it at
  `cdn.campusfootball.net` is a one-line change.
- **R2 implements no object ACLs and REJECTS them** — Laravel Cloud's own docs
  say `visibility: 'public'` fails with `NotImplemented`. Visibility belongs to
  the BUCKET, chosen when it is created. Filament only calls
  `setVisibility($path, 'public')` when its own `getVisibility()` resolves to
  `public`, which happens when the disk is literally named `public` or when
  `->visibility('public')` was called — so dropping that call is what makes an
  upload safe, and `UploadDiskTest` asserts the framework's default rather than
  our source, because the risk is Filament changing it.
- **The AWS SDK sends checksum headers by default.** `request_checksum_calculation`
  defaults to `when_supported` (S3Client.php:295 in the installed 3.390.5),
  putting `x-amz-checksum-crc32` on every PutObject. Both checksum options are
  pinned to `when_required`.
- **`league/flysystem-aws-s3-v3` is a separate package** and was not installed —
  `aws/aws-sdk-php` was only there for Cloud's queue driver. Without the adapter
  `Storage::disk('r2')` throws.

**On Cloud, Livewire's temp disk matters too.** A Filament upload writes to the
temporary disk on one request and moves the file on a SECOND, which can land on
a different container — `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` has to point at the
bucket or uploads fail intermittently, looking like a flaky browser rather than a
topology problem.

**`Storage::fake()` cannot test a URL.** It replaces the disk definition with a
local one, taking the configured public URL with it. Assertions about what an
asset URL looks like configure a bucket-shaped disk with dummy credentials
instead — `url()` is string building and touches no network.

## Mail: branded, queued, and budgeted

Cloudflare Email Service, through Laravel 13's first-party `cloudflare`
transport — an API rather than SMTP, so a rejection comes back as a body naming
the address and the reason instead of a numeric code, and a newsletter drain is
one POST per message rather than one SMTP conversation per message. Templates are
Laravel's own published mail views, restyled — that buys CSS inlining and
client-tested table markup, which is most of the work in an HTML email.

**The Brevo `smtp` mailer stays configured on purpose.** Cloudflare Email Service
reached public beta in April 2026, and with the credentials still in the
environment `MAIL_MAILER=smtp` is a one-variable rollback rather than a deploy.

Two things about the Cloudflare transport, both verified in the framework source
rather than assumed: it posts `getHtmlBody()` VERBATIM as a JSON field, so the
templates render exactly as they did over SMTP; and `getCustomHeaders()` passes
everything through except a small bypass list that does not include
`List-Unsubscribe` — so Gmail's own unsubscribe control survives the move.

**The daily budget starts LOW (100) here, and that is the opposite of what it
looks like.** Cloudflare gives a new account a deliberately conservative daily
quota and raises it as sending reputation builds, so the first newsletter is the
run most likely to meet a ceiling. `ThrottleMail` releases rather than fails, so
anything over the budget arrives tomorrow instead of erroring.

- **The mark in an email is a PNG, never the inline SVG.** Gmail strips `<svg>`
  entirely, so `x-brand.mark` cannot be reused; the header is
  `Brand::asset('icon-192')` as an `<img>` beside HTML text. `AuthMailTest`
  asserts the rendered mail contains no `<svg`, because the failure is silent
  and in one client.
- **The theme CSS is `file_get_contents`, not Blade.** It cannot call `Brand`,
  so anything the admin can retint is an inline `style` on the Blade component
  instead. Buttons are the app's action BLUE rather than Lager — white on Lager
  is about 2:1, and the app already decided it has one action color.
- **The framework's `ResetPassword` and `VerifyEmail` are not queued**, so they
  send inside the web request — free against `log`, a visible stall behind real
  SMTP. Both are overridden on `User` with `ShouldQueue` versions rather than
  restyled via `toMailUsing()`, which would not have moved them off the request.
  Both reproduce the framework's own URL signature, so `password.reset` and
  `EmailVerificationRequest` needed no changes.
- **Transactional mail carries no unsubscribe and no throttle.** A password reset
  is not a list, and an unsubscribe control on it invites turning off the one
  email that gets an account back.
- **The verification reminder ARMS the purge.** `cfb:verification-reminders`
  (daily 07:00, riding the followed-news wake, tracked under ledger key
  `verification-reminders`, `--dry` supported) warns never-verified accounts at
  day 11 of `User::VERIFICATION_GRACE_DAYS` (14) and stamps
  `verification_reminded_at`. `User::prunable()` — User rides both existing
  `model:prune` entries beside `FeedRun` — refuses anyone unwarned or warned
  under 3 days ago, so a mail outage PAUSES deletion rather than breaking the
  "3 days" the mail promised. Admins are never pruned; the FK-less
  notifications rows are cleaned in `pruning()`.

**The daily budget exists because Brevo's 300/day is SHARED** between marketing
and transactional. An unthrottled blast can spend the allowance and leave a
password reset with nowhere to go — which reads as an auth bug, not a mail one.
`ThrottleMail` mirrors `ThrottleEspn` (check the limiter, RELEASE the job, never
sleep) against `MAIL_DAILY_BUDGET`, set below the provider's ceiling so the
headroom belongs to transactional. It counts BEFORE the send: a message that
throws still consumed the allowance.

**`now()->addDay()->diffInSeconds()` is NEGATIVE 86400.** Carbon 3 made the diff
methods signed, so that idiom — the obvious way to say "a day" — expires the
limiter key the instant it is written, every attempt reads zero, and the throttle
silently permits everything. It fails OPEN, which is the worst direction for a
guard protecting somebody else's password reset. The window is a spelled-out
constant.

**`Voice::line()` must be passed `for: $user` in anything queued.** It falls back
to `auth()->user()`, which is null in a job — so a missing argument does not
error, it renders the PG-13 line to everybody regardless of what they chose. The
test for it deliberately does NOT `actingAs` the recipient, because that would
make the fallback resolve correctly by accident and pass while the bug was there.

**Unsubscribe is a signed route outside the auth group**, answering GET and POST.
Somebody who wants the emails to stop is the least likely person to log in to do
it, and RFC 8058's one-click arrives as a POST with no session — it must return a
bare 200, since a redirect or a rendered page makes the client report a failure.
`List-Unsubscribe` and `List-Unsubscribe-Post` are what make Gmail and Apple Mail
show their own control, which is the largest deliverability lever we hold.

**A time-dependent fixture fails one day a week.** `PlayerPageTest` set a game
log fetched "an hour ago" and called it fresh — true Sunday to Friday, false on
Saturday, where the poll window is 15 minutes. Pin fixtures to a value that holds
under the TIGHTEST window, not the usual one.

## The weekly loop: two waves, two claims, three channels

Shipped 2026-08-22. Three sends, all riding plumbing that already existed.

**Pick reminders** — `pickem:remind`, every fifteen minutes 08:00–23:45 in
season. Two waves off the same sweep: `remind` a day before the next open
kickoff, `last_call` ninety minutes before it, each with its own stamp on
`slates` (`picks_reminded_at`, `last_call_sent_at`). A slate is stamped even
when nobody was due, the `games.kickoff_alert_sent_at` discipline — "checked,
nothing to send" must not become "retry forever" at that cadence.

The anchor is `Slate::nextKickoff()`, not `slateDeadline()` and not
`firstKickoff()`. The commissioner's deadline is when an unpublished slate
forfeits to the standard card; players lock game by game at kickoff. And the
FIRST kickoff stops being anybody's deadline once the noon games start, while
the late card is still pickable — anchoring there dropped the whole slate out
of the window the moment its earliest game began.

Wave two is suppressed when wave one fired inside `LAST_CALL_SUPPRESS_HOURS`,
so a late-published card does not produce two messages in twelve hours.

**Results** — dispatched from `SettleSlate`'s claim, the only once-ever
signal in that path, and dispatched as an ID because the in-memory `$slate`
is stale from that line onward (the claim is a query-builder update, so
`status` still reads prelim). `AnnounceSlateResults` takes its OWN claim on
`results_announced_at` before building the batch.

**Why two claims.** `settled_at` claims the money and
`results_announced_at` claims the noise. A queue retry re-runs the whole
fan-out, so without the second claim every entrant is mailed twice; and a
botched announcement is repaired with `pickem:announce --slate=<id>`, which
clears that stamp and replays, while payouts stay keyed and spent. Nothing
in the announcement path can reach the wallet.

**The audience roots in `group_members`**, never `slate_entries` — for both
the reminder and the "you missed it" half of results. An entry row is
created lazily on the first pick, so a member who picked nothing is invisible
to any query rooted there, and is precisely who both messages are for.

**Channels per moment:**

| | mail | push | database | vonage |
| --- | --- | --- | --- | --- |
| Pick reminder | ✅ verified + `pickem_notify_opt_in` | ✅ | ✗ stale after lock | behind `PICKEM_REMINDER_SMS`, off |
| Results (entrant) | ✅ | ✅ | ✅ | ✗ |
| Results (missed it) | ✗ never | ✅ | ✅ | ✗ |

Mail on the pick'em list is its own consent (`pickem_notify_opt_in`, its own
`List-Id`, and the signed unsubscribe names the list) — somebody may want
their reminder and not the Sunday digest.

**The mail budget is one bucket.** The weekly digest and the results
announcement are both bulk; the digest moved from Sunday 08:00 to TUESDAY
08:00 so they do not share a day and release each other's tail into tomorrow.
Tuesday is also the pick'em week's turnover.

**Never resolve Pennant in a sweep.** The reminder mirrors
`config('cfb.pickem_open')` the way `pickem:preflight` does — the database
driver persists a row per resolve, so `Feature::for($user)` inside a loop
writes a row per user per run.

## Web push: VAPID keys, and the subscription is the consent

Push rides `laravel-notification-channels/webpush` (over `minishlink/web-push`;
`ext-gmp` speeds the signing but openssl suffices — check the Cloud runtime
when sends feel slow). `php artisan webpush:vapid` generates the key pair into
the env: the PUBLIC key ships to the browser inside Blade via
`config('webpush.vapid.public_key')`, the PRIVATE key signs every send and is
a real secret, and ROTATING them silently orphans every existing subscription
— treat them like a signing cert. They must exist in the Cloud env before the
first deploy that sends.

There is deliberately NO push consent column. A `push_subscriptions` row can
only exist through an explicit permission grant on a device, so the
subscription IS the consent, device-scoped like the install itself: the
Account switch manages this device's row, `whereHas('pushSubscriptions')` is
the send gate, and no second flag can drift out of agreement with the
browser. The permission prompt is spent the moment it shows — every ask lives
inside a real tap (Account's switch, Home's standalone-only nudge), never on
load.

`cfb:kickoff-alerts` sweeps every five minutes across a fifteen-minute
lookahead, confined to the live window the score tier already keeps awake and
season-gated with it, so it adds no scale-to-zero wakes of its own. The
per-game `kickoff_alert_sent_at` stamp is what makes the overlapping window
send once — and a game with zero reachable followers is stamped too, so
"checked, nothing to send" can never become "retry forever". `--dry` reports
the would-send set without sending or stamping.

## SMS: the one thing that could not be consolidated

Cloudflare has no SMS product — their own Workers docs demonstrate calling Twilio,
which is the answer. So SMS is Vonage, through
`laravel/vonage-notification-channel`, which is the channel Laravel's own
notification docs use and therefore drops into the existing `Notification`
classes as one entry in `via()`.

**The provider barely affects the cost; the CARRIER does.** Since February 2025
US carriers block unregistered numbers outright, so A2P 10DLC registration is
mandatory whoever you use: ~$20 one-time, $1.50-$10/mo per campaign, ~$1.15/mo
for a number, and a $0.003-$0.005 surcharge per message on top of Vonage's
$0.0079. Vonage and Twilio are the same per message; Telnyx is ~35% cheaper.
Choose for how well it disappears into Laravel, because that is the only axis
with real variance. An unapproved number does not fail loudly — messages simply
never arrive.

**Consent is three separate claims, and they fail differently:**

- `sms_opt_in` defaults to FALSE, unlike `newsletter_opt_in`. Signing up for a
  football app can fairly be read as wanting email about football; it cannot be
  read as handing over a phone.
- `phone_verified_at` is not consent, it is identity. One mistyped digit is a
  stranger's phone, and unlike a bounced email they experience it as spam from a
  company they have never heard of. The number is NOT stored until a code proves
  it — writing it first would let anybody park a stranger's phone on their own
  account.
- `sms_opted_in_at` survives an opt-out. It records that consent once happened,
  which stays true afterwards and is what a carrier asks to see when vetting the
  campaign.

The gate lives on `User::routeNotificationForVonage()`, not in each `via()`, so
a new notification cannot forget it — Laravel skips a channel whose route is
falsy, so a user without consent is not an error, they are simply not on that
channel and still get the mail.

**Phone numbers are stored E.164 and only E.164.** An inbound STOP arrives as a
number and nothing else; if the carrier's format and ours differ by a character
the opt-out matches no user and we keep paying to text somebody who told us to
stop. `App\Support\PhoneNumber` normalizes, and REFUSES to guess — a number it
cannot parse is rejected at the form, because a guessed country code is a
stranger's phone.

**The STOP webhook only ever turns SMS OFF, and that is its whole security
model.** It is unauthenticated, because an inbound webhook has no session. Rather
than defend a signature, it is built so forging it is pointless: STOP is
honoured, START is not, so the worst a forged request achieves is what the user
could have asked for anyway. Turning SMS back on requires signing in — an
opt-out must be easy and an opt-IN must be provably the account holder, which is
what consent means. It matches on the WHOLE trimmed message, so "don't stop" is
not an opt-out.

**Vonage's send API returns success for a message the carrier will drop.**
`status 0` means accepted and billed, not delivered — so the delivery-receipt
webhook (`webhooks/sms/status`, required on a Vonage application anyway) is the
only place the truth appears. An unregistered 10DLC campaign shows up there as
`rejected`, which is the failure shape worth recognising: accepted upstream,
charged, silently never arriving. It LOGS and changes no user state: a receipt
describes one message, most non-delivery is transient, and unverifying a number
on a single expiry would quietly disable a channel somebody consented to. Acting
on receipts needs a message log and a threshold across several of them.

`ThrottleSms` is the third of its kind after `ThrottleEspn` and `ThrottleMail`,
and the first where the ceiling is MONEY rather than somebody else's rate limit:
a loop that would merely be rude against the ESPN feed is a bill against this
one. The verification code carries no daily budget — it is transactional, like a
password reset — so a per-USER rate limit on the form is the only thing between
a form and an invoice. Per user, never per number: keyed on the number, somebody
could walk a range and use us as a free SMS cannon.

## Commands

```
# ALWAYS raise the memory limit for a multi-season migrate. Memory accumulates
# across steps in one process and PHP's CLI default is 128M — it dies partway
# through with a fatal, not an error the command can report. --resume picks up
# from sync_runs, so nothing is lost, but it costs a restart.
php -d memory_limit=1024M artisan cfb:migrate --from=2021 --to=2026
php artisan cfb:migrate --resume                  # after an interruption
php artisan cfb:migrate --summaries               # opt in to the slow pass

php artisan cfb:sync --year=2025 [--only=step]    # reference data + standings
php artisan cfb:games --tier=live|today|current|recent|season
php artisan cfb:players [--only=rosters|stats] [--team=61]
php artisan cfb:summaries --missing [--year=2025] # box scores, 1 req/game
php artisan cfb:coaches [--missing|--current]     # careers + tenures, 2+2N req/coach
php artisan cfb:aggregate                         # season totals, 0 requests
```

The pick'em loop's own commands, all DB-only and all safe to dry-run:

```
php artisan pickem:remind --dry                   # who would be nudged, stamps nothing
php artisan pickem:remind --wave=last_call        # one wave rather than both
php artisan pickem:announce --slate=42 --dry      # what a replay would re-send
php artisan pickem:announce --slate=42            # clears results_announced_at, replays
```

`pickem:announce` refuses a slate that has not settled. It is a repair for
the ANNOUNCEMENT, never a settle button: payouts are keyed and `settled_at`
is untouched by anything it does.

**A seed is not finished when `cfb:migrate` exits.** Its `rosters` and `stats`
steps QUEUE `SyncTeamSeason` jobs rather than running inline, and seeding
completed games trips the just-final branch in `SyncGames::store()`, so a
six-season run leaves ~4,800 summary jobs on `live` and ~1,600 roster jobs on
`default`. With no worker running, the command reports success and exits 0
while those tables stay empty — the same "looks done, did nothing" shape as a
403. Start workers, then let the queues drain:

```
for i in $(seq 1 12); do
  php -d memory_limit=512M artisan queue:work --queue=live,default,backfill \
      --memory=200 --stop-when-empty &
done
```

## The Pick'em flip is a config change, and it needs a purge behind it

`Feature::define('pickem', ...)` reads `config('cfb.pickem_open')`, which reads
`PICKEM_OPEN`. False keeps the real surfaces to admins and everybody else on
the coming-soon screen; true opens them to every signed-in user. It lives in
config rather than in the closure so launch is an environment change with an
instant rollback, and so the preflight can REPORT the flag's state without
resolving Pennant — which would persist a row as a side effect of asking.

```
php artisan pickem:preflight        # readiness; non-zero while anything blocks
# set PICKEM_OPEN=true
php artisan config:clear
php artisan pennant:purge pickem    # REQUIRED — see below
```

**The purge is not optional.** Pennant's database driver PERSISTS every
resolved value, so the closure runs once per user and the answer is read from
a `features` row after that. Flipping the config reaches nobody who has
already loaded a page: they keep the false stored for them, and the launch
silently does nothing for exactly the people who were already here. The
preflight's `Stored flag values` row counts those rows and prints the purge
command; `PickemPreflightTest` pins the whole sequence.

The preflight checks what has to be TRUE underneath the flag, not the flag
itself: a week resolved from the calendar (never a hardcoded season), an open
public room with a published slate for EVERY mode, at least fifteen lined
games in the Saturday window (a game with no posted line can never publish),
the league clock, and the three sweeps — `pickem:publish-slates`,
`pickem:settle`, `pickem:open-lobbies` — actually registered. A flag opened
over an unstocked lobby lands a new user in an empty room, which is the one
first impression that cannot be taken back.

It never writes, never stocks anything, and never flips the flag.

`--env=testing` does NOT switch databases — there is no `.env.testing`, so
artisan loads `.env` and `migrate:fresh --env=testing` drops the DEVELOPMENT
database. phpunit.xml's `<env>` block applies to PHPUnit runs only. Validate
schema changes with `php artisan test`, never by rebuilding the dev database.
