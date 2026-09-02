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
use App\Support\Voice;
use Illuminate\Support\Facades\Blade;
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
        ->assertSee('Want a season-long group?')
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
        // One strip, four stops: the code lives on Invite and the roster
        // on Members, each a view of its own.
        ->set('view', 'invite')
        ->assertSee($group->code)
        ->set('view', 'members')
        ->assertSee('Commissioner');
});

it('wears its kind in the hero, and says what a private group IS', function () {
    /*
     * The chip used to render for lobbies only, so "Public" was a mark
     * some rooms wore and nothing at all was said about the container a
     * private group is — a badge one side of a pair wears is a badge
     * nobody reads as a pair. The frame line beneath is the room blurb's
     * missing symmetric half: the mode states the card, this states what
     * the thing around it is.
     */
    $admin = pickemAdmin();
    $group = app(CreateGroup::class)->handle($admin, 'The Test Group', ContestMode::Tiered);

    Livewire::actingAs($admin)->test('group', ['group' => $group])
        ->assertSee('Private')
        ->assertSee(Voice::line('group.private.frame', for: $admin))
        ->assertDontSee('Public');

    $lobby = Group::factory()->lobby()->create(['name' => 'Walk-Ons Welcome']);
    GroupMember::factory()->create(['group_id' => $lobby->id, 'user_id' => $admin->id]);

    // And the room half keeps its own words — never the group's.
    Livewire::actingAs($admin)->test('group', ['group' => $lobby])
        ->assertSee('Public')
        ->assertDontSee(Voice::line('group.private.frame', for: $admin));
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

/*
 * THE BRIEF IS AN ACCORDION (2026-09-01). The blurb-and-frame under the
 * hero and the rules card at the Standings foot were the same facts in
 * two places; one collapsed x-mode-rules at the top of the Slate tab says
 * them once, ungated on membership and outside the published fork, with
 * the laws every mode shares inside it. x-show keeps the lines in the
 * DOM, which is what lets the frame-line pins above keep holding.
 */
it('states the mode once, as a collapsed accordion atop the slate, laws and all', function () {
    $admin = pickemAdmin();
    $group = app(CreateGroup::class)->handle($admin, 'The Test Group', ContestMode::Tiered);

    $html = Livewire::actingAs($admin)->test('group', ['group' => $group])
        ->assertSet('view', 'slate')
        ->assertSee(Voice::line('group.private.frame', for: $admin))
        // The shared laws ride inside the accordion, so the clubhouse
        // says the half-point rule the way the Lobby and the explainer do.
        ->assertSee('no pushes, ever')
        ->html();

    // Exactly one accordion, collapsed, and its identity line clamps
    // rather than truncates — a pitch is a sentence.
    expect(substr_count($html, 'aria-controls="mode-rules-'))->toBe(1)
        ->and($html)->toContain('aria-expanded="false"');

    $identity = (string) str($html)->after('aria-controls="mode-rules-')->before('</button>');

    expect($identity)->toContain('line-clamp-2')
        ->not->toContain('truncate');

    // The Standings tab says none of it any more.
    Livewire::actingAs($admin)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertDontSee(Voice::line('group.private.frame', for: $admin))
        ->assertDontSeeHtml('aria-controls="mode-rules-');
});

it('keeps the accordion outside the published fork, so a slateless group still says what it is', function () {
    $admin = pickemAdmin();
    $group = app(CreateGroup::class)->handle($admin, 'The Test Group', ContestMode::Classic);

    // No slate at all: the else branch renders the build prompt, and the
    // brief must still be in the DOM above it.
    $html = Livewire::actingAs($admin)->test('group', ['group' => $group])
        ->assertSee(Voice::line('group.slate.build_prompt', for: $admin))
        ->html();

    expect(strpos($html, 'aria-controls="mode-rules-'))->not->toBeFalse()
        ->and(strpos($html, 'aria-controls="mode-rules-'))->toBeLessThan(strpos($html, Voice::line('group.slate.build_prompt', for: $admin)));
});

it('renders no empty slot wrapper where the accordion carries nothing extra', function () {
    // The lobby and the explainer pass no slot; the guard is on content,
    // so neither renders an empty div under the rule list.
    $bare = Blade::render('<x-mode-rules :mode="$mode" />', ['mode' => ContestMode::Classic]);
    $filled = Blade::render('<x-mode-rules :mode="$mode" clamp>extra</x-mode-rules>', ['mode' => ContestMode::Classic]);

    expect($bare)->not->toContain('gap-1.5 pt-3')
        ->toContain('truncate')
        ->and($filled)->toContain('gap-1.5 pt-3')
        ->toContain('extra')
        ->toContain('line-clamp-2');
});
