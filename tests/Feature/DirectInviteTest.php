<?php

use App\Actions\InviteUserToGroup;
use App\Exceptions\CannotInvite;
use App\Exceptions\NotGroupMember;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMember;
use App\Models\User;
use App\Models\Week;
use App\Notifications\GroupInviteReceived;
use App\Support\Invitables;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * THE DIRECT INVITE — asking somebody you already play with into a private
 * group, without leaving the app.
 *
 * Two things these tests exist to hold. The first is the PRIVACY BOUNDARY:
 * the picker mixes acquaintances from private groups and public rooms into
 * one column, so it is handles only, always, and an account that never
 * claimed a handle is not listed at all. The clubhouse's own
 * `showsRealNames()` seam is deliberately not reused here, and the tests
 * below assert the stricter rule holds even where the looser one would let
 * a name through.
 *
 * The second is that the rendered list is PRESENTATION and the Action is
 * the gate — every refusal is asserted through `InviteUserToGroup`, called
 * directly, not through the buttons the screen happens to draw.
 */

/**
 * Two people who share one group, plus the private group the first of them
 * is going to invite into.
 *
 * @return array{0: User, 1: User, 2: Group, 3: Group}
 */
function invitePair(string $kind = Group::KIND_PRIVATE): array
{
    $actor = User::factory()->create(['handle' => 'taylor', 'first_name' => 'Taylor', 'last_name' => 'Vols']);
    $other = User::factory()->create(['handle' => 'dave44', 'first_name' => 'Dave', 'last_name' => 'Smoky']);

    // Where they know each other from.
    $shared = $kind === Group::KIND_LOBBY
        ? Group::factory()->room(Week::factory()->create()->id)->create(['name' => 'Marquee Room 3'])
        : Group::factory()->create(['name' => 'Rocky Top Regulars']);

    GroupMember::factory()->create(['group_id' => $shared->id, 'user_id' => $actor->id]);
    GroupMember::factory()->create(['group_id' => $shared->id, 'user_id' => $other->id]);

    // Where the actor is inviting them TO.
    $target = Group::factory()->create(['name' => 'Tuesday Night Lights']);
    GroupMember::factory()->commissioner()->create(['group_id' => $target->id, 'user_id' => $actor->id]);

    return [$actor, $other, $target, $shared];
}

it('lists somebody you share a group with, by handle, with where you know them from', function () {
    [$actor, , $target] = invitePair();

    $rows = Invitables::for($actor, $target);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['handle'])->toBe('dave44')
        ->and($rows[0]['shared'])->toBe('Rocky Top Regulars')
        ->and($rows[0]['pending'])->toBeFalse();
});

it('finds people you know from a PUBLIC room too — the audience is co-membership, either kind', function () {
    [$actor, , $target] = invitePair(Group::KIND_LOBBY);

    expect(collect(Invitables::for($actor, $target))->pluck('handle')->all())->toBe(['dave44']);
});

it('never prints a real name in the picker, even where the clubhouse would', function () {
    // A PRIVATE shared group: `showsRealNames()` says the standings table
    // may print "Dave Smoky" there. The picker may not, because a reader
    // cannot tell that row apart from one sourced out of a public room.
    [$actor, , $target] = invitePair();

    $rows = Invitables::for($actor, $target);

    expect($rows[0])->toBe([
        'id' => $rows[0]['id'],
        'handle' => 'dave44',
        'shared' => 'Rocky Top Regulars',
        'pending' => false,
    ]);

    Livewire::actingAs($actor)->test('group', ['group' => $target])
        ->set('view', 'invite')
        ->assertSee('@dave44')
        ->assertDontSee('Dave Smoky')
        ->assertDontSee('Smoky');
});

it('leaves out an account that never claimed a handle — it cannot be named safely', function () {
    [$actor, $other, $target] = invitePair();
    $other->update(['handle' => null]);

    expect(Invitables::for($actor, $target))->toBe([])
        ->and(Invitables::allows($actor, $other))->toBeFalse();

    Livewire::actingAs($actor)->test('group', ['group' => $target])
        ->set('view', 'invite')
        ->assertDontSee('Dave Smoky');
});

it('leaves out a stranger, the people already seated, and yourself', function () {
    [$actor, , $target] = invitePair();

    $stranger = User::factory()->create(['handle' => 'stranger']);
    $seated = User::factory()->create(['handle' => 'seated']);
    GroupMember::factory()->create(['group_id' => $target->id, 'user_id' => $seated->id]);

    $handles = collect(Invitables::for($actor, $target))->pluck('handle');

    expect($handles->all())->toBe(['dave44'])
        ->and(Invitables::allows($actor, $stranger))->toBeFalse()
        ->and(Invitables::allows($actor, $actor))->toBeFalse();
});

it('filters the list by handle prefix, and says so plainly on a miss', function () {
    [$actor, , $target] = invitePair();

    expect(Invitables::for($actor, $target, 'dav'))->toHaveCount(1)
        ->and(Invitables::for($actor, $target, 'DAV'))->toHaveCount(1)
        ->and(Invitables::for($actor, $target, 'zzz'))->toBe([]);

    Livewire::actingAs($actor)->test('group', ['group' => $target])
        ->set('view', 'invite')
        ->set('q', 'zzz')
        ->assertSee('No one you play with answers to "zzz".', escape: false)
        ->assertDontSee('@dave44');
});

it('sends the invite: a row, an inbox notification, and the ordinary join link', function () {
    Notification::fake();

    [$actor, $other, $target] = invitePair();

    Livewire::actingAs($actor)->test('group', ['group' => $target])
        ->set('view', 'invite')
        ->call('invite', $other->id)
        ->assertHasNoErrors();

    expect(GroupInvite::query()
        ->where('group_id', $target->id)
        ->where('invitee_id', $other->id)
        ->where('inviter_id', $actor->id)
        ->exists()
    )->toBeTrue();

    Notification::assertSentTo($other, GroupInviteReceived::class, function ($notification) use ($other, $target) {
        $payload = $notification->toArray($other);

        // The SAME link every other invite travels by — accepting is the
        // join screen we already ship, not a second seating path.
        return $payload['kind'] === 'group-invite'
            && $payload['key'] === 'notify.invite.body'
            && $payload['replace']['inviter'] === '@taylor'
            && $payload['replace']['group'] === 'Tuesday Night Lights'
            && $payload['url'] === route('pickem.join', ['code' => $target->code, 'by' => 'taylor']);
    });
});

it('names the group instead of inventing a sender when the inviter has no handle', function () {
    [$actor, $other, $target] = invitePair();
    $actor->update(['handle' => null]);

    $payload = (new GroupInviteReceived($target, $actor))->toArray($other);

    expect($payload['key'])->toBe('notify.invite.body.anon')
        ->and($payload['replace'])->not->toHaveKey('inviter')
        ->and($payload['url'])->toBe(route('pickem.join', ['code' => $target->code]));
});

it('asks once: a second send writes no row and makes no second noise', function () {
    Notification::fake();

    [$actor, $other, $target] = invitePair();

    app(InviteUserToGroup::class)->handle($actor, $target, $other);
    app(InviteUserToGroup::class)->handle($actor, $target, $other);

    expect(GroupInvite::where('group_id', $target->id)->count())->toBe(1);

    Notification::assertSentToTimes($other, GroupInviteReceived::class, 1);
});

it('wears "Invited" on a row already asked, rather than dropping it', function () {
    [$actor, $other, $target] = invitePair();

    app(InviteUserToGroup::class)->handle($actor, $target, $other);

    expect(Invitables::for($actor, $target)[0]['pending'])->toBeTrue();

    Livewire::actingAs($actor)->test('group', ['group' => $target])
        ->set('view', 'invite')
        ->assertSee('@dave44')
        ->assertSee('Invited');
});

it('refuses a sender who holds no seat in the group', function () {
    Notification::fake();

    [, $other, $target, $shared] = invitePair();

    $outsider = User::factory()->create(['handle' => 'outsider']);
    GroupMember::factory()->create(['group_id' => $shared->id, 'user_id' => $outsider->id]);

    expect(fn () => app(InviteUserToGroup::class)->handle($outsider, $target, $other))
        ->toThrow(NotGroupMember::class);

    Notification::assertNothingSent();
});

it('refuses a recipient the sender has never played with — the list is not the gate', function () {
    Notification::fake();

    [$actor, , $target] = invitePair();
    $stranger = User::factory()->create(['handle' => 'stranger']);

    expect(fn () => app(InviteUserToGroup::class)->handle($actor, $target, $stranger))
        ->toThrow(CannotInvite::class);

    // And through the screen's own method, which is where a forged id
    // would actually arrive.
    Livewire::actingAs($actor)->test('group', ['group' => $target])
        ->set('view', 'invite')
        ->call('invite', $stranger->id)
        ->assertStatus(403);

    expect(GroupInvite::count())->toBe(0);
    Notification::assertNothingSent();
});

it('refuses a recipient with no handle even when they are a co-member', function () {
    Notification::fake();

    [$actor, $other, $target] = invitePair();
    $other->update(['handle' => null]);

    expect(fn () => app(InviteUserToGroup::class)->handle($actor, $target, $other))
        ->toThrow(CannotInvite::class);

    Notification::assertNothingSent();
});

it('refuses to invite anyone into a PUBLIC room — rooms are joined from the lobby', function () {
    Notification::fake();

    // Both already sit in the room — invitePair seats them there.
    [$actor, $other, , $shared] = invitePair(Group::KIND_LOBBY);

    expect(fn () => app(InviteUserToGroup::class)->handle($actor, $shared, $other))
        ->toThrow(CannotInvite::class);

    // And the picker is empty there, so nothing draws a button to press.
    expect(Invitables::for($actor, $shared))->toBe([]);

    Notification::assertNothingSent();
});

it('says nothing when the recipient is already seated — the outcome already happened', function () {
    Notification::fake();

    [$actor, $other, $target] = invitePair();
    GroupMember::factory()->create(['group_id' => $target->id, 'user_id' => $other->id]);

    app(InviteUserToGroup::class)->handle($actor, $target, $other);

    expect(GroupInvite::count())->toBe(0);
    Notification::assertNothingSent();
});

it('lands in the inbox and reads in the reader\'s own register', function () {
    [$actor, $other, $target] = invitePair();

    app(InviteUserToGroup::class)->handle($actor, $target, $other);

    Livewire::actingAs($other->fresh())->test('inbox')
        ->assertSee('Tuesday Night Lights')
        ->assertSee('@taylor');
});

/*
 * The source sweeps. No feature test can catch either of these: an unloaded
 * relation resolves silently under test (`.ai/rules/tests.md`), and a
 * template that reached for a name would only leak once real data had one.
 */

it('selects only the columns the picker is allowed to print', function () {
    $source = file_get_contents(app_path('Support/Invitables.php'));

    expect($source)
        ->toContain("->select(['users.id', 'users.handle'])")
        ->toContain('whereNotNull(\'users.handle\')')
        // first_name / last_name are never selected, so there is no name in
        // the render path to reach for even by accident.
        ->not->toContain('first_name')
        ->not->toContain('last_name');
});

it('builds the shared-group and pending maps in one query each, never per row', function () {
    $source = file_get_contents(app_path('Support/Invitables.php'));

    // Both lookups must be array reads inside the map, not queries.
    expect($source)
        ->toContain("'shared' => \$shared[(int) \$user->id] ?? null")
        ->toContain("'pending' => in_array((int) \$user->id, \$pending, true)")
        ->not->toContain('static $memo');
});
