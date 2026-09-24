<?php

use App\Models\User;
use App\Support\PulseExceptions;
use Laravel\Pulse\Recorders\Exceptions;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Twenty CannotUpdateLockedPropertyException hits in a day, all filed under
 * app/Http/Middleware/RecordPageView.php:100 — the `$next()` of a middleware
 * that only passes the request along (CFB-86). Pulse files an exception under
 * the first frame outside vendor/, and a Livewire update runs entirely inside
 * Livewire's own endpoint, so for every Livewire exception that frame is the
 * page-view sensor. The row named no component, and so no bug.
 */

/** Post an update for help-sheet's locked `path`, the way a stale tab would. */
function refusedUpdate(User $user): CannotUpdateLockedPropertyException
{
    $snapshot = Livewire::actingAs($user)->test('help-sheet')->snapshot;

    test()->withoutExceptionHandling();

    try {
        test()->actingAs($user)
            ->withHeader('X-Livewire', '1')
            ->postJson(route('default-livewire.update'), [
                'components' => [[
                    'snapshot' => json_encode($snapshot),
                    'updates' => ['path' => '/somewhere-else'],
                    'calls' => [],
                ]],
            ]);
    } catch (CannotUpdateLockedPropertyException $e) {
        return $e;
    }

    throw new RuntimeException('The update was not refused, so there is nothing to attribute.');
}

it('files a Livewire refusal under its component and property, not the page-view middleware', function () {
    $refusal = refusedUpdate(User::factory()->create());

    // The premise, reproduced: Pulse's own resolver lands on the middleware.
    expect(invade(app(Exceptions::class))->resolveLocation($refusal))
        ->toStartWith('app/Http/Middleware/RecordPageView.php');

    // The regression guard: ours names what a person needs to find the bug.
    expect(invade(app(PulseExceptions::class))->resolveLocation($refusal))
        ->toBe('livewire:help-sheet [path]');
});

it('leaves an exception thrown in application code where Pulse put it', function () {
    // Only a location that landed on a middleware is re-read. Anything the
    // stock resolver already places in real code is the better answer.
    $thrown = new RuntimeException('from a test, which is application code');

    expect(invade(app(PulseExceptions::class))->resolveLocation($thrown))
        ->toBe(invade(app(Exceptions::class))->resolveLocation($thrown))
        ->not->toStartWith('app/Http/Middleware/');
});
