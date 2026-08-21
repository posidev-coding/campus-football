<?php

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Models\WalletEntry;
use App\Support\Leaderboard;
use Livewire\Livewire;

/*
 * The XP leaderboard: windowed SUMs over wallet_entries, circled to
 * Everyone or the people you share a group with. Window boundaries are
 * where this class of feature breaks — travelTo speaks UTC, and 01:00
 * UTC Wednesday is still Tuesday night in Knoxville.
 */

function leaderboardXp(User $user, int $xp, string $createdAt): void
{
    WalletEntry::forceCreate([
        'user_id' => $user->id,
        'xp' => $xp,
        'lattes' => 0,
        'reason' => 'test-grant',
        'created_at' => $createdAt,
    ]);
}

it('windows THIS WEEK to the seven days ending at official-final', function () {
    // Week 1's Saturday is Sep 5; official-final defaults Sunday noon ET =
    // Sep 6 16:00 UTC. Saturday night ET is inside; eight days ago is out.
    [$season, $week] = pickemSeasonWeek();
    pickemGame($season, $week);
    $this->travelTo('2026-09-07 12:00:00'); // Monday — the payoff moment

    $viewer = pickemAdmin();
    $inside = User::factory()->create(['handle' => 'inside']);
    $stale = User::factory()->create(['handle' => 'stale']);

    leaderboardXp($inside, 100, '2026-09-06 01:00:00'); // Sat night ET
    leaderboardXp($stale, 900, '2026-08-28 12:00:00');  // the week before

    $rows = Leaderboard::top('week', 'everyone', $viewer);

    expect(collect($rows)->pluck('label')->all())->toBe(['@inside'])
        ->and($rows[0]['xp'])->toBe(100);
});

it('circles MY GROUPS to actual co-members, and ranks by the window sum', function () {
    $this->travelTo('2026-09-07 12:00:00');

    $viewer = pickemAdmin();
    $groupmate = User::factory()->create(['handle' => 'groupmate']);
    $stranger = User::factory()->create(['handle' => 'stranger']);

    $group = Group::factory()->create();
    GroupMember::factory()->commissioner()->create(['group_id' => $group->id, 'user_id' => $viewer->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $groupmate->id]);

    leaderboardXp($viewer, 50, '2026-09-01 12:00:00');
    leaderboardXp($groupmate, 75, '2026-09-01 12:00:00');
    leaderboardXp($stranger, 999, '2026-09-01 12:00:00');

    $mine = Leaderboard::top('all', 'groups', $viewer);
    expect(collect($mine)->pluck('label')->all())->toBe(['@groupmate', '@'.$viewer->handle]);

    $everyone = Leaderboard::top('all', 'everyone', $viewer);
    expect(collect($everyone)->pluck('label')->first())->toBe('@stranger');
});

it('never blocks the table on the handle seam', function () {
    $viewer = pickemAdmin();
    $handleless = User::factory()->handleless()->create(['first_name' => 'Peyton', 'last_name' => 'Fulmer']);

    leaderboardXp($handleless, 40, now()->toDateTimeString());

    $rows = Leaderboard::top('all', 'everyone', $viewer);

    expect(collect($rows)->pluck('label')->all())->toContain('Peyton F.');
});

it('tells the viewer where they stand, even off the page', function () {
    $viewer = pickemAdmin();
    leaderboardXp($viewer, 10, now()->toDateTimeString());
    leaderboardXp(User::factory()->create(), 500, now()->toDateTimeString());
    leaderboardXp(User::factory()->create(), 300, now()->toDateTimeString());

    expect(Leaderboard::rankOf($viewer, 'all', 'everyone'))->toBe(['rank' => 3, 'xp' => 10])
        ->and(Leaderboard::rankOf(User::factory()->create(), 'all', 'everyone'))->toBeNull();
});

it('renders the screen with its two dials and the viewer highlighted', function () {
    $viewer = pickemAdmin();
    $group = Group::factory()->create();
    GroupMember::factory()->commissioner()->create(['group_id' => $group->id, 'user_id' => $viewer->id]);

    leaderboardXp($viewer, 120, now()->toDateTimeString());

    Livewire::actingAs($viewer)->test('pickem-leaderboard')
        ->assertSee('My groups')
        ->assertSee('Everyone')
        ->assertSee('This Week')
        ->set('view', 'all')
        ->assertSee('@'.$viewer->handle)
        ->assertSee('120');
});

it('lands a groupless reader on Everyone', function () {
    Livewire::actingAs(pickemAdmin())->test('pickem-leaderboard')
        ->assertSet('scope', 'everyone');
});

it('stays behind the flag', function () {
    $this->actingAs(User::factory()->create())->get(route('pickem.leaderboard'))->assertBadRequest();
    $this->actingAs(pickemAdmin())->get(route('pickem.leaderboard'))->assertOk();
});
