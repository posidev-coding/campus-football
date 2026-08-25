<?php

use Illuminate\Support\Facades\File;

/*
 * The empty-schedule trap, guarded at the CALL SITE because it cannot be
 * guarded at runtime.
 *
 * Resolving the Schedule out of the container and reading its events
 * returns an empty list over HTTP — routes/
 * console.php loads only when the console kernel bootstraps — and nothing
 * throws, so the caller simply concludes that nothing is scheduled.
 * SyncSchedule::events() is the one reader that bootstraps first.
 *
 * No behavioral test can catch a new offender: Pest runs in console context,
 * where the raw read is always correct. So this asserts the SHAPE of the code
 * instead, in the spirit of ChromeConsistencyTest.
 */
it('reads the schedule through SyncSchedule and nowhere else', function () {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->filter(fn ($file): bool => str_contains(File::get($file->getPathname()), 'app(Schedule::class)'))
        ->map(fn ($file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->sort()
        ->values()
        ->all();

    expect($offenders)->toBe(['app/Support/SyncSchedule.php']);
});
