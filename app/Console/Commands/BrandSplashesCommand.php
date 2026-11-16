<?php

namespace App\Console\Commands;

use App\Support\Brand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
 * nothing, and it sweeps every other fingerprint's folder. If the previous
 * release is still serving while this runs, its readers miss, render on the
 * request path and re-store their old folder; the next run sweeps it. One
 * stray folder until the next deploy is cheaper than a sweep that has to
 * remember which fingerprint came before.
 *
 * Inline rather than queued, on purpose. A worker can still be running the
 * previous release while this one deploys; it would compute the OLD
 * fingerprint, store the old set and sweep the new one as an orphan.
 *
 * It never fails a deploy. A launch screen is not worth a 500 on the route
 * (`storedSplash()`), and it is not worth blocking a hotfix either: a size
 * that cannot be rendered (an unreadable icon) or a disk that throws is
 * reported as a warning, named, and the exit is 0. `--strict` makes either a
 * failing exit, for a manual run that wants one. Nothing is ever stored in a
 * missing size's place: a device with no launch screen launches without one,
 * which is the designed behavior.
 */
#[Signature('cfb:brand:splashes {--strict : Exit non-zero when any size is missing or the disk fails}')]
#[Description('Render any iOS launch screen missing under the current brand fingerprint and drop stale sets (run after deploy)')]
class BrandSplashesCommand extends Command
{
    public function handle(): int
    {
        /* The same reset the job does: Brand memoizes in a static, and the
           ink is read through the settings cache. */
        Brand::flush();

        $total = count(Brand::SPLASH);

        try {
            $written = Brand::storeSplashes();
            $missing = $this->missing();
        } catch (Throwable $e) {
            report($e);

            $this->warn("  Launch screens not warmed — the upload disk failed: {$e->getMessage()}");

            return $this->outcome();
        }

        $stored = $total - count($missing);

        $this->line("  Launch screens: wrote {$written}, {$stored} of {$total} stored under the current brand.");

        if ($missing !== []) {
            $this->warn('  Missing: '.implode(', ', $missing).' — the icon could not be rendered.');

            return $this->outcome();
        }

        return self::SUCCESS;
    }

    /**
     * The declared sizes with nothing stored under the current fingerprint.
     *
     * @return list<string>
     */
    private function missing(): array
    {
        $disk = Storage::disk(config('cfb.upload_disk'));
        $missing = [];

        foreach (Brand::SPLASH as [$width, $height, $dpr]) {
            if (! $disk->exists(Brand::splashPath($width, $height, $dpr))) {
                $missing[] = "{$width}x{$height}@{$dpr}";
            }
        }

        return $missing;
    }

    /** A warning by default, so a deploy goes ahead; a failure only when asked. */
    private function outcome(): int
    {
        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
