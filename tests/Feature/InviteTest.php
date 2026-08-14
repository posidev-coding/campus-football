<?php

use App\Enums\ContestMode;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\Voice;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

/*
 * THE INVITE LANDING — /join/{CODE}, the URL a group travels by. A guest
 * reads the whole preview before any wall; the join tap rides the
 * intended-URL machinery back here after auth; a dead code gets words and
 * a door; rooms never advertise codes or /join links at all.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

it('shows a guest the whole preview: name, game, people — before any wall', function () {
    Feature::define('pickem', true);

    [, $group] = pickemContest(ContestMode::Tiered);
    $group->update(['name' => 'Third Saturday Pickers']);

    Livewire::test('join', ['code' => strtolower($group->code)])
        ->assertSee('Third Saturday Pickers')
        ->assertSee('Triple Option')
        ->assertSee('1 member')
        ->assertSee('Take your seat')
        ->assertSee("You'll sign in or create an account first", escape: false);
});

it('credits a real inviter and silently ignores a fake one', function () {
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest();
    $commissioner->update(['handle' => 'marcus']);

    Livewire::withQueryParams(['by' => 'marcus'])
        ->test('join', ['code' => $group->code])
        ->assertSeeHtml('&commat;marcus invited you');

    Livewire::withQueryParams(['by' => 'nobody_here'])
        ->test('join', ['code' => $group->code])
        ->assertDontSee('invited you');

    // A malformed ?by= (wrong shape entirely) is nothing, never an error.
    Livewire::withQueryParams(['by' => 'NOT A HANDLE!'])
        ->test('join', ['code' => $group->code])
        ->assertOk()
        ->assertDontSee('invited you');
});

it('answers a dead code with words and a door, not a 404', function () {
    Feature::define('pickem', true);

    Livewire::test('join', ['code' => 'NOPENOPE'])
        ->assertOk()
        ->assertSee('Invite not found')
        ->assertSee(route('pickem.lobby'), escape: false);
});

it('walks a guest joiner through auth and back — the intended URL is this page', function () {
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest();
    $commissioner->update(['handle' => 'marcus']);

    Livewire::withQueryParams(['by' => 'marcus'])
        ->test('join', ['code' => $group->code])
        ->call('join')
        ->assertRedirect(route('login'));

    expect(session('url.intended'))
        ->toBe(route('pickem.join', ['code' => $group->code, 'by' => 'marcus'], absolute: false));
});

it('seats a signed-in joiner and lands them in the clubhouse', function () {
    [, $group] = pickemContest();
    $joiner = pickemAdmin();

    Livewire::actingAs($joiner)
        ->test('join', ['code' => $group->code])
        ->call('join')
        ->assertRedirect(route('pickem.group', $group));

    expect(GroupMember::where(['group_id' => $group->id, 'user_id' => $joiner->id])->exists())->toBeTrue();
});

it('tells an unverified joiner to verify, in Voice', function () {
    Feature::define('pickem', true);

    [, $group] = pickemContest();
    $unverified = User::factory()->unverified()->create();

    Livewire::actingAs($unverified)
        ->test('join', ['code' => $group->code])
        ->call('join')
        ->assertHasErrors('join')
        ->assertSee(Voice::line('groups.verify_first', for: $unverified));
});

it('skips the pitch for someone already seated — straight to their clubhouse, by kind', function () {
    [$commissioner, $group] = pickemContest();

    Livewire::actingAs($commissioner)
        ->test('join', ['code' => $group->code])
        ->assertRedirect(route('pickem.group', $group));

    [, $week] = pickemSeasonWeek();
    $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 20]);
    $seated = pickemAdmin();
    GroupMember::factory()->create(['group_id' => $room->id, 'user_id' => $seated->id]);

    Livewire::actingAs($seated)
        ->test('join', ['code' => $room->code])
        ->assertRedirect(route('pickem.room', $room));
});

it('shows a full room its honest state instead of a dead button', function () {
    Feature::define('pickem', true);

    [, $week] = pickemSeasonWeek();
    $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 1]);
    GroupMember::factory()->create(['group_id' => $room->id]);

    Livewire::test('join', ['code' => $room->code])
        ->assertSee('No open seats.')
        ->assertSee('Find another room')
        ->assertDontSee('Take your seat');
});

it('bounces the whole screen to the lobby outside the flag', function () {
    [, $group] = pickemContest();

    Livewire::test('join', ['code' => $group->code])
        ->assertRedirect(route('pickem.lobby'));
});

it('never lets a room advertise a code or a /join link', function () {
    // Rooms are joined from the lobby floor; PickemGroupsTest pins the
    // no-code rule, and this is its companion for the link era.
    [, $week] = pickemSeasonWeek();
    $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 20]);
    $member = pickemAdmin();
    GroupMember::factory()->create(['group_id' => $room->id, 'user_id' => $member->id]);

    $html = Livewire::actingAs($member)->test('group', ['group' => $room])->html();

    expect($html)->not->toContain('/join/')
        ->not->toContain($room->code);
});
