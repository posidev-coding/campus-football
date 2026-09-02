<?php

namespace App\Providers;

use App\Events\GameScoreChanged;
use App\Events\GameWentFinal;
use App\Jobs\GradeGamePicks;
use App\Models\FeedRun;
use App\Models\Game;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;
use App\Services\Espn\EspnClient;
use App\Services\Nil\KeywordNilNewsProvider;
use App\Services\Nil\NilNewsProvider;
use App\Support\PageMeta;
use App\Support\R2SignedUploadUrl;
use App\Support\R2Writes;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Shared per process. The client carries the request counter that feed
         * runs report, and its throttle only means anything if every sync step
         * in a run is drawing from the same instance.
         */
        $this->app->singleton(EspnClient::class);

        /*
         * ESPN publishes no NIL data, so the default implementation filters its
         * news feed by keyword. Bound through an interface so a paid provider
         * can replace it without touching a page.
         */
        $this->app->bind(NilNewsProvider::class, KeywordNilNewsProvider::class);

        /*
         * What THIS page's <head> says. Scoped, never singleton: a queue
         * worker holds one container for its whole life, so a singleton
         * would leak one reader's group name into the next reader's card.
         */
        $this->app->scoped(PageMeta::class);

        /*
         * ACL-free writes to R2, attached when the disk RESOLVES. The
         * manager consults custom creators before its built-in one, so
         * this wraps the stock S3 driver for every s3-driver disk and
         * bolts R2Writes onto the client of any disk carrying a plain
         * `'no_acl' => true` (config-cache safe; the SDK ignores the extra
         * key). The driver stays `s3`, so Livewire's isUsingS3() branch is
         * untouched. Never at boot: a boot-time attach lands on a client
         * the tests never see and does not survive forgetDisk().
         */
        Storage::extend('s3', function ($app, array $config) {
            $disk = Storage::createS3Driver($config);

            if ($config['no_acl'] ?? false) {
                R2Writes::attach($disk->getClient());
            }

            return $disk;
        });
    }

    public function boot(): void
    {
        // Fail loudly on a typo'd relation instead of silently issuing N+1
        // queries — the sync jobs iterate tens of thousands of rows.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Date::use(CarbonImmutable::class);

        /*
         * Livewire's direct-to-bucket upload signs an ACL R2 refuses. Bound
         * rather than swapped: a swap sets a resolved instance and would
         * displace the stub Livewire installs for its own tests, where no
         * URL should be signed at all. A binding lets that stub still win.
         */
        $this->app->bind(GenerateSignedUploadUrl::class, R2SignedUploadUrl::class);

        /*
         * Live pick'em scoring, event-driven end to end: the sync tier that
         * already paid the ESPN request announces "this game moved", and
         * grading rides the announcement — never a poll. Closures here
         * rather than a Listeners folder (no new base folder), dispatching
         * a per-game unique job so a scoring flurry collapses.
         */
        Event::listen(GameScoreChanged::class, fn (GameScoreChanged $event) => GradeGamePicks::dispatch($event->gameId));
        Event::listen(GameWentFinal::class, fn (GameWentFinal $event) => GradeGamePicks::dispatch($event->gameId));

        /*
         * The Conversation's three topic scopes, ENFORCED: a morph against
         * any unmapped model throws instead of writing a class name into
         * conversation_posts.topic_type. Adding a scope here is a product
         * decision (see the roadmap's "no league firehose"), not a string.
         *
         * User is IDENTITY-mapped, not aliased. It already morphs today —
         * notifications.notifiable_type, push_subscriptions.subscribable_type
         * and Pennant's feature scopes all store the FQCN — so an alias like
         * 'user' would strand every existing row behind a WHERE that now says
         * 'user', and no alias means enforcement breaks those writes
         * entirely. The identity entry keeps every stored string valid and
         * every new write identical, while any truly unmapped model still
         * fails loudly.
         */
        Relation::enforceMorphMap([
            'game' => Game::class,
            'team' => Team::class,
            'group' => Group::class,
            User::class => User::class,
        ]);

        /*
         * A failed queue job, into the same ledger the scheduled commands
         * write to.
         *
         * Hand-built even though Pulse is now installed, because Pulse's
         * Exceptions recorder sees the THROW and Laravel Cloud's managed
         * queues keep the failed JOB record entirely to themselves — the app
         * cannot read `failed_jobs` there at all. Without this row, a job that
         * dies in production is invisible to every screen we own; with it, the
         * Sync Health failures table shows it beside the commands.
         *
         * A closure rather than an app/Listeners class, following the
         * event-driven scoring above: no new base folder, and the write itself
         * is FeedRun's, where the rest of the ledger's API lives.
         *
         * Swallowing is deliberate and the ONLY place in the app it is. This
         * runs inside the handler for something that already failed; a throw
         * here would replace the real exception with a bookkeeping one and
         * lose the actual cause.
         */
        Queue::failing(function (JobFailed $event): void {
            try {
                FeedRun::jobFailed($event->job->resolveName(), $event->exception->getMessage());
            } catch (Throwable $e) {
                Log::warning('Could not record a queue failure.', ['exception' => $e->getMessage()]);
            }
        });

        /*
         * The `/ops` surfaces' rate limit, keyed by IP.
         *
         * Named rather than inline because both routes share it and the number
         * is a decision: a weekly routine making two calls has all the headroom
         * it will ever need at thirty a minute, and a loop is stopped an order
         * of magnitude before it costs anything. The limiter rides Redis
         * through the cache store, like the mail and SMS budgets.
         */
        RateLimiter::for('ops', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));

        /*
         * Pulse's dashboard, on the same key everything else admin-only uses.
         *
         * Pulse ships its own `viewPulse` gate answering `environment('local')`,
         * which is open to every signed-in developer locally and CLOSED to
         * everybody in production — so the dashboard would be unreachable
         * exactly where the telemetry lives. This define runs after Pulse's
         * (package providers boot before application ones) and replaces it in
         * every environment, local included, so what is enforced under test is
         * what is enforced in production.
         */
        Gate::define('viewPulse', fn (?User $user): bool => (bool) $user?->isAdmin());

        /*
         * Feature flags — the first Pennant use in the app, and the
         * convention: closures HERE until a flag earns real logic, at which
         * point it graduates to a class in app/Features. On for everyone now;
         * the flag exists so the tour can be pulled without a deploy.
         */
        Feature::define('guided-tour', fn (): bool => true);

        /*
         * The PICKS walk, its own flag beside the Home one: it points at an
         * economy that can be pulled independently of the app's first-run
         * story, and a walkthrough of a feature that has been turned off is
         * worse than no walkthrough at all.
         */
        Feature::define('picks-tour', fn (): bool => true);

        /*
         * Admins see the real Pick'em surfaces while `cfb.pickem_open` is
         * false; everyone else keeps the coming-soon screen. Launch is that
         * config going true (PICKEM_OPEN in the environment), so the flip is
         * reversible in seconds and needs no deploy — run
         * `php artisan pickem:preflight` first, which reports the same config
         * rather than resolving this closure and persisting a row to ask.
         *
         * OPEN MEANS OPEN, GUESTS INCLUDED. Pennant resolves a guest to a
         * NULL SCOPE, so an earlier `$user !== null` guard here locked out
         * exactly the person an invite link is aimed at: /join/{CODE} bounces
         * the whole screen when this flag is inactive, so a signed-out
         * visitor clicking a shared link landed on the coming-soon page and
         * the acquisition funnel died silently. The guard was right while the
         * flag was admin-only and wrong the moment it opened.
         *
         * Any test for this must flip the CONFIG. `Feature::define('pickem',
         * true)` is a literal that answers true for the null scope too, which
         * is what hid the bug through a green InviteTest.
         */
        Feature::define('pickem', fn (?User $user): bool => config('cfb.pickem_open') === true
            || (bool) $user?->isAdmin());

        /*
         * The two AI surfaces, the same shape as `pickem`: a closure over
         * config, so each flip is an environment change with an instant
         * rollback — and `php artisan pennant:purge <flag>` afterwards, because
         * the database driver persists a resolved value and anybody who has
         * already loaded a page keeps their old answer until those rows go.
         *
         * BOTH READ THE MASTER SWITCH, so `AI_ENABLED=false` closes everything
         * at once without having to remember the list.
         *
         * They do NOT read the budget. That is a runtime question asked at the
         * call site through `App\Support\AiBudget::allows()` — resolving
         * Pennant against a number that moves would persist a row the moment
         * spend crossed the line and then answer from it afterwards.
         */
        Feature::define('ai-answers', fn (): bool => config('cfb.ai_enabled') === true
            && config('cfb.ai_answers') === true);

        Feature::define('ai-recaps', fn (): bool => config('cfb.ai_enabled') === true
            && config('cfb.ai_recaps') === true);
    }
}
