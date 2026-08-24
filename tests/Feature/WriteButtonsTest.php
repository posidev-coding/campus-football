<?php

/*
 * EVERY WRITE BUTTON DISABLES WHILE ITS WRITE IS IN FLIGHT.
 *
 * The ChromeConsistencyTest pattern, applied to double submission: a
 * second tap during the round trip re-fires the action, and before this
 * sweep existed a double-fired Create minted TWO groups with two codes.
 * Server-side idempotency is the real guard where it matters (group
 * create, keyed wallet grants); the disabled attribute is what keeps the
 * common case from ever reaching it.
 */

use Symfony\Component\Finder\Finder;

/** @return array<string, string> path (relative to views) => contents */
function writeButtonViews(): array
{
    $views = [];

    foreach (Finder::create()->files()->in(resource_path('views'))->exclude('filament')->name('*.blade.php') as $file) {
        $views[str_replace(resource_path('views').'/', '', $file->getPathname())] = $file->getContents();
    }

    return $views;
}

it('finds views to sweep, or the check below is vacuous', function () {
    expect(writeButtonViews())->not->toBeEmpty();
});

it('gives every wire:submit form a loading-disabled submit', function () {
    $violations = [];

    foreach (writeButtonViews() as $path => $contents) {
        preg_match_all('/<form\b[^>]*wire:submit[^>]*>(.*?)<\/form>/s', $contents, $forms, PREG_SET_ORDER);

        foreach ($forms as $i => $form) {
            if (! str_contains($form[1], 'wire:loading.attr="disabled"')) {
                $violations[] = "{$path} (form ".($i + 1).')';
            }
        }
    }

    expect($violations)->toBe([], implode(' | ', $violations)
        .' — every wire:submit form needs a submit with wire:loading.attr="disabled".');
});
