<?php

namespace App\Jobs;

use App\Support\Brand;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Render the iOS launch-screen set ahead of the readers who would wait on it.
 *
 * Dispatched by BrandSetting when the ink or the icon changes, which are the
 * only two things a launch screen is drawn from. It is not on a clock: a
 * nightly re-render of an unchanged set is the same waste moved off the
 * request path. Fourteen renders is about a second and a half of GD, which is
 * why it does not run inside the admin's save.
 *
 * Unique, so an admin clicking Save three times queues one render.
 */
class RenderBrandSplashes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Must stay below the queue's `retry_after` (90s). */
    public int $timeout = 60;

    public int $tries = 3;

    public function handle(): void
    {
        /*
         * A worker outlives the request that saved the brand, and Brand
         * memoizes in a static. Without this the fingerprint would be read
         * off whatever brand this process last saw.
         */
        Brand::flush();

        Brand::storeSplashes();
    }
}
