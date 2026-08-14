<?php

use App\Enums\ContestMode;
use App\Models\Group;
use App\Models\User;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * The creation wizard — Name → The Game → the invite moment. The group
 * exists only after the final submit and carries exactly ONE contest in
 * the chosen mode. All three doors open now: the Woodshed's rules landed
 * with the founders' email, so the founders' game is a real choice.
 */

it('keeps the wizard behind the flag, and ahead of the {group} binding', function () {
    // A non-admin is refused by the flag; an admin gets the wizard — which
    // also proves `groups/new` registered BEFORE `groups/{group}`, or this
    // would 404 as a failed model binding for a group named "new".
    $this->actingAs(User::factory()->create())->get('/groups/new')->assertBadRequest();
    $this->actingAs(pickemAdmin())->get('/groups/new')->assertOk();
});

it('walks name, game, invite — and creates exactly one contest', function () {
    $admin = pickemAdmin();

    Livewire::actingAs($admin)->test('group-create')
        ->assertSee('Name your group')
        ->set('name', 'Saturday People')
        ->call('toGame')
        ->assertSee('Pick your game')
        ->assertSee('Triple Option')
        ->assertSee('The Woodshed')
        ->call('choose', 'tiered')
        ->call('create')
        ->assertSee("You're live", escape: false);

    $group = Group::where('name', 'Saturday People')->first();

    expect($group)->not->toBeNull()
        ->and($group->contests()->pluck('mode')->all())->toBe([ContestMode::Tiered])
        ->and($group->memberships()->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('shows the code huge at the invite moment, with the road to the clubhouse', function () {
    $admin = pickemAdmin();

    $wizard = Livewire::actingAs($admin)->test('group-create')
        ->set('name', 'The Corner Office')
        ->call('toGame')
        ->call('choose', 'classic')
        ->call('create');

    $group = Group::where('name', 'The Corner Office')->first();

    $wizard->assertSee($group->code)
        ->assertSee(route('pickem.group', $group), escape: false);
});

it('requires a name before the game step, and a game before creating', function () {
    Livewire::actingAs(pickemAdmin())->test('group-create')
        ->call('toGame')
        ->assertHasErrors('name');

    Livewire::actingAs(pickemAdmin())->test('group-create')
        ->set('name', 'No Game Yet')
        ->call('toGame')
        ->call('create')
        ->assertHasErrors('mode');

    expect(Group::where('name', 'No Game Yet')->exists())->toBeFalse();
});

it('opens the Woodshed door now that the founders\' rules landed', function () {
    Livewire::actingAs(pickemAdmin())->test('group-create')
        ->set('name', 'Founders Club')
        ->call('toGame')
        ->call('choose', 'woodshed')
        ->call('create')
        ->assertHasNoErrors();

    $group = Group::where('name', 'Founders Club')->first();

    expect($group)->not->toBeNull()
        ->and($group->contests()->pluck('mode')->all())->toBe([ContestMode::Woodshed]);
});

it('tells an unverified creator to verify, in their own register', function () {
    $unverified = User::factory()->unverified()->create(['admin' => true]);

    Livewire::actingAs($unverified)->test('group-create')
        ->set('name', 'Ghost Group')
        ->call('toGame')
        ->call('choose', 'classic')
        ->call('create')
        ->assertHasErrors('mode')
        ->assertSee(Voice::line('groups.verify_first'));

    expect(Group::where('name', 'Ghost Group')->exists())->toBeFalse();
});
