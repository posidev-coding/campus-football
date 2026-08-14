<?php

namespace App\Providers;

use App\Events\GameScoreChanged;
use App\Events\GameWentFinal;
use App\Jobs\GradeGamePicks;
use App\Models\Game;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;
use App\Services\Espn\EspnClient;
use App\Services\Nil\KeywordNilNewsProvider;
use App\Services\Nil\NilNewsProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

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
    }

    public function boot(): void
    {
        // Fail loudly on a typo'd relation instead of silently issuing N+1
        // queries — the sync jobs iterate tens of thousands of rows.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Date::use(CarbonImmutable::class);

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
         * Feature flags — the first Pennant use in the app, and the
         * convention: closures HERE until a flag earns real logic, at which
         * point it graduates to a class in app/Features. On for everyone now;
         * the flag exists so the tour can be pulled without a deploy.
         */
        Feature::define('guided-tour', fn (): bool => true);

        /*
         * Phase 5, mid-build: admins see the real Pick'em surfaces, everyone
         * else keeps the coming-soon screen. Flips to `true` at launch.
         * Nullable user — Pennant resolves guests to a null scope, and a
         * guest is never inside the flag.
         */
        Feature::define('pickem', fn (?User $user): bool => (bool) $user?->isAdmin());
    }
}
