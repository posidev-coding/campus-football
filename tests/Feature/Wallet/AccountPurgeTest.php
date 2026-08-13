<?php

use App\Models\Team;
use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The self-destruct. Deleting USERS is the most destructive query in the app,
 * so every clause of User::prunable() gets its own "survives" case — the
 * verified-user case most of all, because a test for this class of bug passes
 * for the wrong reason more often than not (an empty prunable query also
 * deletes nobody). The "actually prunes" case anchors the suite: with it
 * green, each survival case is proven against a query that demonstrably
 * deletes.
 */

function pruneUsers(): void
{
    test()->artisan('model:prune', ['--model' => [User::class]])->assertSuccessful();
}

it('prunes an old, warned, never-verified account — and its satellite rows', function () {
    $user = User::factory()->unverified()->create([
        'created_at' => now()->subDays(20),
        'verification_reminded_at' => now()->subDays(4),
    ]);

    $team = Team::factory()->create();
    $user->followedTeams()->attach($team->id, ['position' => 1]);
    $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => []]);
    WalletEntry::factory()->for($user)->create(['xp' => 25]);

    pruneUsers();

    expect(User::whereKey($user->id)->exists())->toBeFalse()
        ->and(DB::table('team_follows')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('notifiable_id', $user->id)->exists())->toBeFalse()
        ->and(WalletEntry::where('user_id', $user->id)->exists())->toBeFalse();
});

it('never prunes a verified account, however old', function () {
    $user = User::factory()->create([
        'created_at' => now()->subYears(2),
        'verification_reminded_at' => now()->subDays(30),
    ]);

    pruneUsers();

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

it('keeps an unverified account inside the fourteen-day grace window', function () {
    $user = User::factory()->unverified()->create([
        'created_at' => now()->subDays(13),
        'verification_reminded_at' => now()->subDays(4),
    ]);

    pruneUsers();

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

it('keeps an old unverified account that was never warned', function () {
    // The reminder mail promises three days. An account the mail never reached
    // — outage, budget throttle, deploy-day backlog — waits for its warning
    // instead of vanishing unannounced.
    $user = User::factory()->unverified()->create([
        'created_at' => now()->subDays(40),
        'verification_reminded_at' => null,
    ]);

    pruneUsers();

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

it('keeps an account warned less than three days ago', function () {
    $user = User::factory()->unverified()->create([
        'created_at' => now()->subDays(20),
        'verification_reminded_at' => now()->subDays(2),
    ]);

    pruneUsers();

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

it('never prunes an admin, verified or not', function () {
    $admin = User::factory()->admin()->unverified()->create([
        'created_at' => now()->subDays(40),
        'verification_reminded_at' => now()->subDays(10),
    ]);

    pruneUsers();

    expect(User::whereKey($admin->id)->exists())->toBeTrue();
});
