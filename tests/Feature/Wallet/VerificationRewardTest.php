<?php

use App\Actions\GrantWalletEntry;
use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\URL;

/*
 * The wallet ledger and the verification payout. The invariant these protect:
 * a one-time grant pays exactly once no matter how many times its trigger
 * fires, and the ledger still supports the same reason recurring when Pick'em
 * starts paying weekly. The unique (user_id, key) index is the guarantee;
 * everything here proves the paths through it.
 */

it('pays the verification reward when the Verified event fires', function () {
    $user = User::factory()->unverified()->create();

    event(new Verified($user));

    $entry = WalletEntry::sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->xp)->toBe(GrantWalletEntry::VERIFICATION_XP)
        ->and($entry->credits)->toBe(GrantWalletEntry::VERIFICATION_CREDITS)
        ->and($entry->reason)->toBe(GrantWalletEntry::REASON_EMAIL_VERIFIED)
        ->and($entry->key)->toBe(GrantWalletEntry::REASON_EMAIL_VERIFIED);
});

it('pays a double-fired Verified event exactly once', function () {
    $user = User::factory()->unverified()->create();

    // A stale verification link re-dispatches the event; the idempotency key
    // makes the second fire a zero-row insert, not a second payday.
    event(new Verified($user));
    event(new Verified($user));

    expect(WalletEntry::count())->toBe(1);
});

it('ignores a duplicate one-time grant at the action level too', function () {
    $user = User::factory()->create();
    $grant = app(GrantWalletEntry::class);

    $grant->handle($user, xp: 100, credits: 1, reason: 'email-verified', key: 'email-verified');
    $grant->handle($user, xp: 100, credits: 1, reason: 'email-verified', key: 'email-verified');

    expect(WalletEntry::count())->toBe(1);
});

it('lets a keyless entry repeat — the shape every future spend and weekly win takes', function () {
    $user = User::factory()->create();
    $grant = app(GrantWalletEntry::class);

    $grant->handle($user, xp: 0, credits: -1, reason: 'contest-entry');
    $grant->handle($user, xp: 0, credits: -1, reason: 'contest-entry');

    expect(WalletEntry::where('reason', 'contest-entry')->count())->toBe(2);
});

it('grants through the real signed verification URL', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    // The flash replaced ?verified=1: a query param captured into a
    // home-screen install is the landmine onboarding.moment retired.
    $this->actingAs($user)->get($url)
        ->assertRedirect(route('home'))
        ->assertSessionHas('verify.moment');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(WalletEntry::where('user_id', $user->id)->count())->toBe(1);
});

it('sums the ledger into wallet totals', function () {
    $user = User::factory()->create();

    WalletEntry::factory()->for($user)->create(['xp' => 100, 'credits' => 1]);
    WalletEntry::factory()->for($user)->create(['xp' => 25, 'credits' => 0]);
    WalletEntry::factory()->for($user)->create(['xp' => 0, 'credits' => -1, 'reason' => 'contest-entry']);

    expect($user->walletTotals())->toBe(['xp' => 125, 'credits' => 0]);
});

it('reads an empty ledger as zeros, never null', function () {
    $user = User::factory()->create();

    expect($user->walletTotals())->toBe(['xp' => 0, 'credits' => 0]);
});

it('keeps one user\'s ledger out of another\'s totals', function () {
    $user = User::factory()->create();
    WalletEntry::factory()->create(['xp' => 500, 'credits' => 5]);

    expect($user->walletTotals())->toBe(['xp' => 0, 'credits' => 0]);
});
