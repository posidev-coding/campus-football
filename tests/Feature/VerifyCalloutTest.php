<?php

use App\Models\User;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * THE VERIFY CALLOUT IS AN ISLAND.
 *
 * As a Blade component its wire:poll bound to the host Livewire root, so
 * the ambient tick re-rendered the ENTIRE screen (Home ≈ 12-18 queries)
 * four times a minute for every unverified reader, across seven hosts.
 * These tests hold the island's contract: the row owns its own poll, the
 * verified flip dispatches the event the hosts $refresh on, and the two
 * documented couplings survive.
 */

it('renders the row with its own poll for an unverified reader', function () {
    Livewire::actingAs(User::factory()->unverified()->create())
        ->test('verify-callout')
        ->assertSeeHtml('data-verify-callout')
        ->assertSeeHtml('wire:poll.30s');
});

it('keeps .visible off the poll, so a dismissed reader still flips', function () {
    // Dismissal display:nones the row via x-show; the flip must still
    // reach a reader who waved the nudge away. Deliberate deviation from
    // the house wire:poll shape — do not "fix" it.
    $html = Livewire::actingAs(User::factory()->unverified()->create())
        ->test('verify-callout')
        ->html();

    expect($html)->toContain('wire:poll.30s')
        ->and($html)->not->toContain('wire:poll.30s.visible');
});

it('dispatches the flip event exactly when verification lands', function () {
    /*
     * Coupling (a): the wallet chips live in the HOST tree, and the flip
     * is when they change (100 XP + 1 Tallboy). Every embed forwards
     * `email-verified` to the host as one $refresh — so the dispatch has
     * to fire on the flip tick, and only then.
     */
    $reader = User::factory()->unverified()->create();
    $island = Livewire::actingAs($reader)->test('verify-callout');

    $island->call('check')->assertNotDispatched('email-verified');

    $reader->forceFill(['email_verified_at' => now()])->save();

    $island->call('check')
        ->assertDispatched('email-verified')
        // The verified render drops the row, its poll, and the callout's
        // whole existence — the @if guard IS the "something to poll".
        ->assertDontSeeHtml('data-verify-callout')
        ->assertDontSeeHtml('wire:poll');
});

it('renders nothing visible for a guest, with no poll at all', function () {
    $html = Livewire::test('verify-callout')->html();

    expect($html)->not->toContain('data-verify-callout')
        ->and($html)->not->toContain('wire:poll');
});

it('keeps the picks variant: explanation body, no dismissal', function () {
    $html = Livewire::actingAs(User::factory()->unverified()->create())
        ->test('verify-callout', ['bodyKey' => 'verify.picks.body', 'dismissable' => false])
        ->assertSee(Voice::line('verify.picks.body'))
        ->html();

    // There it explains a gate, and an explanation you can dismiss becomes a
    // mystery: nothing hides the row and nothing offers to.
    expect($html)->not->toContain('x-show')
        ->and($html)->not->toContain('aria-label="Dismiss"');
});

it('defines the dismissed scope in BOTH shapes', function () {
    /*
     * One component, ONE Alpine scope. The x-data used to live inside the
     * same `@if ($dismissable)` as the x-show and the dismiss button, so the
     * row rendered with a scope on Home and Account and with none at all on
     * the five picks surfaces — one Livewire component, two Alpine scopes,
     * keyed by a prop. Anything reading `dismissed` without it throws a bare
     * ReferenceError out of Alpine's evaluator, carrying no element and no
     * file, and gets reported against whatever path the reader is standing
     * on: production saw one from /verify-email, a screen with no callout.
     *
     * The scope is now unconditional and the flag decides only who reads it.
     */
    $reader = User::factory()->unverified()->create();

    $scopeless = [];

    foreach (['dismissable' => true, 'picks' => false] as $shape => $dismissable) {
        $html = Livewire::actingAs($reader)
            ->test('verify-callout', ['dismissable' => $dismissable])
            ->html();

        if (! str_contains($html, 'cfb.verify.dismissed')) {
            $scopeless[] = $shape;
        }
    }

    expect($scopeless)->toBe([], implode(' and ', $scopeless)
        .' renders the row without an Alpine scope defining `dismissed`.');
});

it('is embedded in every host with the $refresh forwarder', function () {
    /*
     * Coupling (a)'s other half, swept at source: an embed without
     * @email-verified="$refresh" leaves that host's wallet chips stale
     * after the flip.
     */
    $hosts = [
        'livewire/home.blade.php',
        'livewire/account.blade.php',
        'livewire/pickem-home.blade.php',
        'livewire/lobby.blade.php',
        'livewire/join.blade.php',
        'livewire/group-create.blade.php',
        'partials/pickem-promise.blade.php',
    ];

    foreach ($hosts as $host) {
        $source = file_get_contents(resource_path("views/{$host}"));

        expect(str_contains($source, '<livewire:verify-callout'))->toBeTrue("{$host} should embed the island")
            ->and(str_contains($source, '@email-verified="$refresh"'))->toBeTrue("{$host} must forward the flip as a \$refresh");
    }
});
