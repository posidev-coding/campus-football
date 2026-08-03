<?php

namespace App\Providers;

use App\Services\Espn\EspnClient;
use App\Services\Nil\KeywordNilNewsProvider;
use App\Services\Nil\NilNewsProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

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
    }
}
