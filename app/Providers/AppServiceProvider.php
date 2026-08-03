<?php

namespace App\Providers;

use App\Services\Espn\EspnClient;
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
