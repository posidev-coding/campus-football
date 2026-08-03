<?php

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\MassAssignmentException;

/*
 * These are regression tests for the specific way v3 shipped broken, not
 * generic scaffolding tests. In v3 the protected route group declared
 * `verified` but never applied `auth`, and the verify middleware body was
 * commented out — so every "protected" page was publicly reachable and
 * unverified accounts had full access. Each expectation below fails loudly if
 * any of that regresses.
 */

it('redirects guests away from protected routes', function () {
    $this->get(route('account'))->assertRedirect(route('login'));
});

it('does not let unverified users reach protected routes', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('account'))
        ->assertRedirect(route('verification.notice'));
});

it('lets verified users reach protected routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('account'))
        ->assertOk();
});

it('requires email verification on the User model', function () {
    expect(new User)->toBeInstanceOf(MustVerifyEmail::class);
});

it('keeps guests out of the admin panel', function () {
    $this->get('/admin')->assertRedirect();
});

it('will not let a non-admin into the admin panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('lets an admin into the admin panel', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('refuses to mass-assign the admin flag', function () {
    // Outside production this throws rather than silently discarding, so a
    // privilege-escalation attempt surfaces as a failure in dev and CI instead
    // of quietly doing nothing. In production it degrades to a silent discard —
    // either way `admin` is never set from a mass-assignment path.
    expect(fn () => User::create([
        'name' => 'Escalation Attempt',
        'email' => 'nope@example.com',
        'password' => 'password',
        'admin' => true,
    ]))->toThrow(MassAssignmentException::class);

    expect(User::whereEmail('nope@example.com')->exists())->toBeFalse();
});

it('only sets admin through an explicit forceFill', function () {
    $user = User::factory()->create();

    expect($user->admin)->toBeFalse();

    $user->forceFill(['admin' => true])->save();

    expect($user->fresh()->isAdmin())->toBeTrue();
});
