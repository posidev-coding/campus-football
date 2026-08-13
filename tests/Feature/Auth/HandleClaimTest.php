<?php

use App\Enums\ContentRating;
use App\Models\User;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * Claiming a handle, now that registration stopped asking for one. Account is
 * where a handle begins; null means never claimed and is a state every
 * surface must render honestly — no fabricated stand-ins.
 */

it('renders Account for a handleless user without a fabricated handle', function () {
    // The typed $handle property made `null` a TypeError on mount before the
    // coalesce — this passing at all is half the point.
    Livewire::actingAs(User::factory()->handleless()->create())
        ->test('account')
        ->assertSee('Claim your handle')
        ->assertDontSee('@fan', escape: false);
});

it('shows the claimed handle instead of the claim prompt', function () {
    // The @ is written as the &#64; entity in the template, so the raw HTML
    // is what carries it.
    Livewire::actingAs(User::factory()->create(['handle' => 'rockytop']))
        ->test('account')
        ->assertSeeHtml('&#64;rockytop')
        ->assertDontSee('Claim your handle');
});

it('lets the handleless save their profile without claiming', function () {
    $user = User::factory()->handleless()->create(['first_name' => 'Neyland']);

    Livewire::actingAs($user)
        ->test('account')
        ->set('first_name', 'General')
        ->call('saveProfile')
        ->assertHasNoErrors();

    // Still null — an empty field is "not yet", never a write of ''.
    expect($user->fresh()->first_name)->toBe('General')
        ->and($user->fresh()->handle)->toBeNull();
});

it('claims a handle from the profile modal', function () {
    $user = User::factory()->handleless()->create();

    Livewire::actingAs($user)
        ->test('account')
        ->set('handle', 'rockytop')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->handle)->toBe('rockytop');
});

it('validates a claim like the claim it is', function () {
    $user = User::factory()->handleless()->create();

    Livewire::actingAs($user)
        ->test('account')
        ->set('handle', 'gs')
        ->call('saveProfile')
        ->assertHasErrors('handle');
});

it('will not claim a handle someone else holds, whatever the case', function () {
    /*
     * `@Gunner11` and `@gunner11` reading as two people is exactly the
     * confusion a handle exists to prevent. The unique index sits on a
     * case-insensitive collation, so the database enforces this even if a
     * future caller skips the form.
     */
    User::factory()->create(['handle' => 'gunner11']);
    $user = User::factory()->handleless()->create();

    Livewire::actingAs($user)
        ->test('account')
        ->set('handle', 'GUNNER11')
        ->call('saveProfile')
        ->assertHasErrors('handle');

    expect($user->fresh()->handle)->toBeNull();
});

it('strips what cannot be typed in a mention as you go', function () {
    // Corrected while typing rather than rejected afterwards — a capital
    // should not become an error message to read and fix.
    Livewire::actingAs(User::factory()->handleless()->create())
        ->test('account')
        ->set('handle', 'Gunner Stockton!')
        ->assertSet('handle', 'gunnerstockton');
});

it('never lets a claimed handle blank back to nothing', function () {
    $user = User::factory()->create(['handle' => 'rockytop']);

    Livewire::actingAs($user)
        ->test('account')
        ->set('handle', '')
        ->call('saveProfile')
        ->assertHasErrors('handle');

    expect($user->fresh()->handle)->toBe('rockytop');
});

it('speaks the claim in every register, escalating', function () {
    $pg = Voice::line('profile.claim_handle', for: User::factory()->make(['content_rating' => ContentRating::Pg]));
    $r = Voice::line('profile.claim_handle', for: User::factory()->make(['content_rating' => ContentRating::R]));

    expect($pg)->not->toBe('')
        ->and($r)->not->toBe('')
        ->and($r)->not->toBe($pg);
});
