<?php

use App\Actions\CreateGroup;
use App\Actions\JoinGroup;
use App\Actions\LeaveGroup;
use App\Actions\RemoveGroupMember;
use App\Enums\ContestMode;
use App\Exceptions\GroupNeedsCommissioner;
use App\Exceptions\NotGroupCommissioner;
use App\Exceptions\PickemParticipationGated;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\CfbCalendar;
use App\Support\Navigation;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

/*
 * Phase 5 slice 3: groups. The Actions carry every gate (verified email,
 * commissioner authority, the commissioner-leaves-last rule) so a public
 * Livewire method can never route around one — which is why the gates are
 * tested at the Action, and the screens are tested for what they render.
 */

// ---------------------------------------------------------------- actions

it('creates a group with its commissioner, ONE contest and invite code', function () {
    $creator = User::factory()->create();

    $group = app(CreateGroup::class)->handle($creator, 'The Test Group', ContestMode::Tiered);

    expect($group->code)->toHaveLength(8)
        ->and($group->kind)->toBe(Group::KIND_PRIVATE)
        ->and($group->memberships()->first()->role)->toBe(GroupMember::COMMISSIONER)
        // ONE game per group per season, chosen at the door — the
        // auto-both-modes era is over and the unique index holds it.
        ->and($group->contests()->pluck('mode')->all())->toBe([ContestMode::Tiered])
        ->and($group->contests()->first()->season_year)->toBe(app(CfbCalendar::class)->currentYear())
        ->and($group->contests()->first()->mode_changed_at)->toBeNull();
});

it('fields the Woodshed now that the founders\' rules landed', function () {
    $creator = User::factory()->create();

    $group = app(CreateGroup::class)->handle($creator, 'Founders Only', ContestMode::Woodshed);

    expect($group->contests()->pluck('mode')->all())->toBe([ContestMode::Woodshed]);
});

it('pays founding and joining XP exactly once, ever', function () {
    $creator = User::factory()->create();

    app(CreateGroup::class)->handle($creator, 'First Group', ContestMode::Classic);
    app(CreateGroup::class)->handle($creator, 'Second Group', ContestMode::Tiered);

    expect(WalletEntry::where('user_id', $creator->id)->where('reason', 'first-group-created')->count())->toBe(1)
        ->and(WalletEntry::where('user_id', $creator->id)->where('reason', 'first-group')->count())->toBe(1);
});

it('gates creating and joining on a verified email', function () {
    $unverified = User::factory()->unverified()->create();
    $group = Group::factory()->create();

    expect(fn () => app(CreateGroup::class)->handle($unverified, 'No Ghosts', ContestMode::Classic))
        ->toThrow(PickemParticipationGated::class)
        ->and(fn () => app(JoinGroup::class)->handle($unverified, $group))
        ->toThrow(PickemParticipationGated::class);
});

it('seats a joiner once — joining again is a no-op, not an error', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();

    app(JoinGroup::class)->handle($user, $group);
    app(JoinGroup::class)->handle($user, $group);

    expect($group->memberships()->where('user_id', $user->id)->count())->toBe(1)
        ->and(WalletEntry::where('user_id', $user->id)->where('reason', 'first-group')->count())->toBe(1);
});

it('lets a member leave, keeps the commissioner until last, and buries an empty group', function () {
    $commissioner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::factory()->create();
    GroupMember::factory()->commissioner()->create(['group_id' => $group->id, 'user_id' => $commissioner->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    // The commissioner leaves last.
    expect(fn () => app(LeaveGroup::class)->handle($commissioner, $group))
        ->toThrow(GroupNeedsCommissioner::class);

    app(LeaveGroup::class)->handle($member, $group);
    expect($group->memberships()->count())->toBe(1);

    // The last one out turns off the lights.
    app(LeaveGroup::class)->handle($commissioner, $group);
    expect(Group::find($group->id))->toBeNull();
});

it('lets only the commissioner remove a member, and never themselves', function () {
    $commissioner = User::factory()->create();
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $group = Group::factory()->create();
    GroupMember::factory()->commissioner()->create(['group_id' => $group->id, 'user_id' => $commissioner->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $memberA->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $memberB->id]);

    expect(fn () => app(RemoveGroupMember::class)->handle($memberA, $group, $memberB))
        ->toThrow(NotGroupCommissioner::class);

    app(RemoveGroupMember::class)->handle($commissioner, $group, $commissioner);
    expect($group->memberships()->count())->toBe(3);

    app(RemoveGroupMember::class)->handle($commissioner, $group, $memberA);
    expect($group->memberships()->where('user_id', $memberA->id)->exists())->toBeFalse();
});

// ----------------------------------------------------------- flag and nav

it('keeps the clubhouse routes behind the pickem flag', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get(route('pickem.create'))->assertBadRequest();

    $admin = pickemAdmin();
    $this->actingAs($admin)->get(route('pickem.create'))->assertOk();
});

it('serves My Picks at /picks and walks the one retired URL there', function () {
    // /picks was a 301 to /lobby and is a real screen again. There is
    // deliberately no redirect the other way: a browser caches a 301
    // forever, and one pointing back would loop for every dev machine
    // still holding the old one.
    $this->get('/picks')->assertOk();
    $this->get('/picks/groups')->assertMovedPermanently()->assertRedirect(route('pickem.home'));
});

it('grows the Picks area sections only inside the flag', function () {
    $this->actingAs(User::factory()->create());
    expect(collect(Navigation::areas())->firstWhere('key', 'picks')['sections'])->toBe([]);

    Feature::flushCache();
    $this->actingAs(pickemAdmin());
    $sections = collect(Navigation::areas())->firstWhere('key', 'picks')['sections'];

    expect(collect($sections)->pluck('label')->all())->toBe(['My Picks', 'Lobby', 'Leaderboard', 'History'])
        // A room or group visit lights MY PICKS: a reader inside one is a
        // seated member playing, not somebody browsing the store. The
        // Lobby chip lights on the browser alone.
        ->and($sections[0]['routes'])->toContain('pickem.group', 'pickem.room')
        ->and($sections[1])->not->toHaveKey('routes');
});

// ---------------------------------------------------------------- screens

it('splits the two screens: your groups on My Picks, the rooms in the Lobby', function () {
    $admin = pickemAdmin();
    $mine = Group::factory()->create(['name' => 'The Woodshed Alumni']);
    GroupMember::factory()->commissioner()->create(['group_id' => $mine->id, 'user_id' => $admin->id]);
    Group::factory()->lobby()->create(['name' => 'The Big Lobby']);

    Livewire::actingAs($admin)->test('pickem-home')
        ->assertSee('The Woodshed Alumni')
        ->assertSee('Have an invite code?')
        // The store is a door here, never a shelf.
        ->assertDontSee('The Big Lobby');

    Livewire::actingAs($admin)->test('lobby')
        ->assertSee('The Big Lobby')
        // And the lobby is nobody's dashboard.
        ->assertDontSee('The Woodshed Alumni')
        ->assertDontSee('Have an invite code?');
});

it('links the door to the creation wizard from both screens', function () {
    Livewire::actingAs(pickemAdmin())->test('pickem-home')
        ->assertSee(route('pickem.create'), escape: false);

    Livewire::actingAs(pickemAdmin())->test('lobby')
        ->assertSee('Rather run your own?')
        ->assertSee(route('pickem.create'), escape: false);
});

it('answers a bad code with the Voice line, not a crash', function () {
    Livewire::actingAs(pickemAdmin())->test('pickem-home')
        ->set('code', 'NOPENOPE')
        ->call('join')
        ->assertHasErrors('code');
});

it('shows a member their clubhouse: hero, mode, code and roster', function () {
    $admin = pickemAdmin();
    $group = app(CreateGroup::class)->handle($admin, 'The Test Group', ContestMode::Tiered);

    Livewire::actingAs($admin)->test('group', ['group' => $group])
        ->assertSee('The Test Group')
        ->assertSee('Triple Option')
        ->set('view', 'members')
        ->assertSee($group->code)
        ->assertSee('Commissioner');
});

it('keeps outsiders off a private group page and lets them read a lobby', function () {
    $admin = pickemAdmin();
    $private = Group::factory()->create();
    $lobby = Group::factory()->lobby()->create(['name' => 'Walk-Ons Welcome']);

    // The old nested URL survives only as a hop; the clubhouse itself
    // still holds the members-only door.
    $this->actingAs($admin)->get(route('picks.group', $private))
        ->assertRedirect(route('pickem.group', $private));
    $this->actingAs($admin)->get(route('pickem.group', $private))->assertForbidden();

    Livewire::actingAs($admin)->test('group', ['group' => $lobby])
        ->assertSee('Walk-Ons Welcome')
        ->assertSee('Join this lobby');
});

it('never shows a lobby an invite code', function () {
    $admin = pickemAdmin();
    $lobby = Group::factory()->lobby()->create();
    GroupMember::factory()->create(['group_id' => $lobby->id, 'user_id' => $admin->id]);

    Livewire::actingAs($admin)->test('group', ['group' => $lobby])
        ->assertDontSee($lobby->code)
        ->set('view', 'members')
        ->assertDontSee($lobby->code);
});
