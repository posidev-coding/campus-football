<?php

namespace App\Console\Commands;

use App\Support\Brand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Warm the iOS launch-screen set for the code that is now deployed.
 *
 * A set lives under a fingerprint of what it is drawn from — the ink, the
 * icon and `SPLASH_REVISION`, which is a constant in the code. A BrandSetting
 * save re-renders it when an admin changes the ink or the icon; nothing did
 * when a DEPLOY changed the fingerprint (a bumped revision, a new shipped
 * icon), so the new set went unrendered and the first readers on each size
 * paid for the render on the request path (CFB-114).
 *
 * Meant to run after every deploy, and safe to: `storeSplashes()` skips a size
 * already stored under the current fingerprint, so an unchanged brand writes
 * nothing, and it sweeps every other fingerprint's folder.
 *
 * Inline rather than queued, on purpose. A worker can still be running the
 * previous release while this one deploys; it would compute the OLD
 * fingerprint, store the old set and sweep the new one as an orphan.
 *
 * A size that cannot be rendered (an unreadable icon) is reported and the exit
 * is non-zero. Nothing is stored in its place: a device with no launch screen
 * launches without one, which is the designed behavior.
 */
#[Signature('cfb:brand:splashes')]
#[Description('Render any iOS launch screen missing under the current brand fingerprint and drop stale sets (run after deploy)')]
class BrandSplashesCommand extends Command
{
    public function handle(): int
    {
        /* The same reset the job does: Brand memoizes in a static, and the
           ink is read through the settings cache. */
        Brand::flush();

        $written = Brand::storeSplashes();

        $disk = Storage::disk(config('cfb.upload_disk'));
        $missing = [];

        foreach (Brand::SPLASH as [$width, $height, $dpr]) {
            if (! $disk->exists(Brand::splashPath($width, $height, $dpr))) {
                $missing[] = "{$width}x{$height}@{$dpr}";
            }
        }

        $total = count(Brand::SPLASH);
        $stored = $total - count($missing);

        $this->line("  Launch screens: wrote {$written}, {$stored} of {$total} stored under the current brand.");

        if ($missing !== []) {
            $this->line('  <fg=red>Missing: '.implode(', ', $missing).' — the icon could not be rendered.</>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
