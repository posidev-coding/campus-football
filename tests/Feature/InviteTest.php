<?php

use App\Actions\JoinGroup;
use App\Actions\RecordUxEvent;
use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\User;
use App\Models\WalletEntry;
use App\Support\Voice;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
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
        ->assertSee("You'll create an account (or sign in) first", escape: false);
});

it('says which KIND of thing the link opens, before the mode or the count', function () {
    /*
     * A link lands somebody who has never seen the app on a name, a mode
     * chip and a member count — none of which say whether they are being
     * invited to somebody's whole season or to one Saturday with
     * strangers. Facts only; join.pitch underneath carries the mood.
     */
    Feature::define('pickem', true);

    [, $group] = pickemContest(ContestMode::Tiered);

    Livewire::test('join', ['code' => $group->code])
        ->assertSee('Private group, all season');

    $room = Group::factory()->create([
        'kind' => Group::KIND_LOBBY,
        'week_id' => $group->contests->first()->slates->first()?->week_id
            ?? pickemSeasonWeek()[1]->id,
        'member_cap' => 20,
    ]);
    Contest::factory()->create(['group_id' => $room->id]);

    Livewire::test('join', ['code' => $room->code])
        ->assertSee('Public room')
        ->assertDontSee('Private group');
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

it('walks a guest joiner to REGISTER and back — the intended URL is this page', function () {
    /*
     * Register, not login: the invite link is the PRIMARY acquisition
     * path and the guest holding one is almost always brand new — a
     * login form is a door they cannot open. The register screen links
     * to sign-in for the few who already have an account.
     */
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest();
    $commissioner->update(['handle' => 'marcus']);

    Livewire::withQueryParams(['by' => 'marcus'])
        ->test('join', ['code' => $group->code])
        ->call('join')
        ->assertRedirect(route('register'));

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

it('seats an unverified joiner in a private group — verification gates the picks, not the seat', function () {
    Feature::define('pickem', true);

    [, $group] = pickemContest();
    $unverified = User::factory()->unverified()->create();

    Livewire::actingAs($unverified)
        ->test('join', ['code' => $group->code])
        ->call('join')
        ->assertHasNoErrors()
        ->assertRedirect(route('pickem.group', $group));

    expect(GroupMember::where(['group_id' => $group->id, 'user_id' => $unverified->id])->exists())->toBeTrue()
        // An unverified seat earns nothing — the first-group XP waits for
        // the first seat taken after verifying.
        ->and(WalletEntry::where('user_id', $unverified->id)->exists())->toBeFalse();
});

it('still gates a public room on a verified email, in Voice', function () {
    Feature::define('pickem', true);

    [, $week] = pickemSeasonWeek();
    $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 20]);
    $unverified = User::factory()->unverified()->create();

    Livewire::actingAs($unverified)
        ->test('join', ['code' => $room->code])
        ->call('join')
        ->assertHasErrors('join')
        ->assertSee(Voice::line('groups.verify_first', for: $unverified));

    expect(GroupMember::where(['group_id' => $room->id, 'user_id' => $unverified->id])->exists())->toBeFalse();
});

it('seats a registered joiner on the way back from register, with no second tap', function () {
    Feature::define('pickem', true);

    [, $group] = pickemContest();

    // The guest tap parks the code beside the intended URL...
    Livewire::test('join', ['code' => strtolower($group->code)])
        ->call('join')
        ->assertRedirect(route('register'));

    expect(session('join.auto'))->toBe($group->code);

    // ...and the landing after registration takes the seat and goes
    // straight to the clubhouse — never this card a second time.
    $registered = User::factory()->unverified()->create();

    Livewire::actingAs($registered)
        ->test('join', ['code' => $group->code])
        ->assertRedirect(route('pickem.group', $group));

    expect(GroupMember::where(['group_id' => $group->id, 'user_id' => $registered->id])->exists())->toBeTrue()
        ->and(session()->has('join.auto'))->toBeFalse();
});

it('nudges a fresh unverified seat from the clubhouse itself', function () {
    Feature::define('pickem', true);

    [, $group] = pickemContest();
    $unverified = User::factory()->unverified()->create();
    app(JoinGroup::class)->handle($unverified, $group);

    Livewire::actingAs($unverified)
        ->test('group', ['group' => $group])
        ->assertSee(Voice::line('verify.picks.body', for: $unverified));
});

it('names the group on the register screen a guest was sent to', function () {
    [, $group] = pickemContest();
    $group->update(['name' => 'Third Saturday Pickers']);

    session(['url.intended' => '/join/'.$group->code.'?by=taylor']);

    Livewire::test('auth.register')
        ->assertSee('take your seat in Third Saturday Pickers');
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

it('dates a split-week room card by the fans\' numbering', function () {
    Feature::define('pickem', true);

    // A room playing the opening week's FIRST card: its ESPN week says
    // "Week 1", its slate's Saturday says the fans' truth — Week 0.
    [, $week] = splitPickemWeek();
    $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 20]);
    $contest = Contest::factory()->create(['group_id' => $room->id]);
    Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id,
        'saturday' => '2026-08-29', 'status' => Slate::PUBLISHED,
    ]);

    Livewire::test('join', ['code' => $room->code])
        ->assertSee('Week 0');
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

it('bounces the whole screen to My Picks outside the flag', function () {
    // The coming-soon promise lives at BOTH pick'em addresses; the
    // area's own tab is where a bounced visitor belongs.
    [, $group] = pickemContest();

    Livewire::test('join', ['code' => $group->code])
        ->assertRedirect(route('pickem.home'));
});

it('lets a SIGNED-OUT visitor through the moment the launch config opens', function () {
    /*
     * The acquisition funnel, and the one shape the rest of this file cannot
     * see. Pennant resolves a guest to a NULL SCOPE, so a flag keyed to a
     * user resolves false for exactly the person a Slack link is aimed at —
     * they land on the coming-soon page and the invite dies silently.
     *
     * Every other test here stubs `Feature::define('pickem', true)`, a
     * literal that answers true for the null scope too. That stub is what
     * hid this. So this one flips the real CONFIG instead, which is what
     * launch actually does.
     */
    [, $group] = pickemContest(ContestMode::Tiered);
    $group->update(['name' => 'Third Saturday Pickers']);

    config(['cfb.pickem_open' => true]);

    expect(Feature::for(null)->active('pickem'))->toBeTrue();

    Livewire::test('join', ['code' => $group->code])
        ->assertNoRedirect()
        ->assertSee('Third Saturday Pickers')
        ->assertSee('Take your seat');
});

it('keeps a signed-out visitor OUT while the launch config is closed', function () {
    // The other half, so the test above cannot pass by simply opening the
    // door to everybody forever.
    [, $group] = pickemContest();

    expect(Feature::for(null)->active('pickem'))->toBeFalse();

    Livewire::test('join', ['code' => $group->code])
        ->assertRedirect(route('pickem.home'));
});

it('never lets a room advertise a code or a /join link', function () {
    // Rooms are joined from the lobby; PickemGroupsTest pins the
    // no-code rule, and this is its companion for the link era.
    [, $week] = pickemSeasonWeek();
    $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 20]);
    $member = pickemAdmin();
    GroupMember::factory()->create(['group_id' => $room->id, 'user_id' => $member->id]);

    $html = Livewire::actingAs($member)->test('group', ['group' => $room])->html();

    expect($html)->not->toContain('/join/')
        ->not->toContain($room->code);
});

/*
 * THE APP INVITE — /join?by=handle, the same screen with no code behind
 * it. A private group's code cannot be sent to a stranger on a thin
 * Saturday and a room's code would rot inside a week, so the link worth
 * sending carries only who is asking.
 *
 * The load-bearing rule here is BRANCH ORDER. "No group" is what a
 * codeless link resolves to AND what a dead code resolves to, and the two
 * get opposite screens: the miss card tells a reader to go ask for a
 * fresh link, which is exactly the wrong thing to say to somebody holding
 * a working one.
 */

it('opens the app pitch on a codeless link, never the miss card', function () {
    Feature::define('pickem', true);

    Livewire::test('join')
        ->assertOk()
        ->assertSee(Voice::line('join.app.heading'))
        ->assertSee('Public rooms are open to anyone')
        ->assertSee('Create your account')
        ->assertDontSee('Invite not found');
});

it('still answers a DEAD code with the miss card — the branch order IS the feature', function () {
    /*
     * The regression this change can actually introduce. Point the app
     * invite at a non-empty code and this goes red: a dead link would
     * start selling the app instead of admitting it is dead, and the
     * reader would never learn to ask for a working one.
     */
    Feature::define('pickem', true);

    Livewire::test('join', ['code' => 'NOPENOPE'])
        ->assertOk()
        ->assertSee('Invite not found')
        ->assertSee(Voice::line('join.miss'))
        ->assertDontSee(Voice::line('join.app.heading'))
        ->assertDontSee('Public rooms are open to anyone');
});

it('routes /join with no code at all — the URL a share sheet actually sends', function () {
    // Livewire::test() mounts past the router, so the optional segment is
    // only really proven by a request. One route and one name: the same
    // call without a code is the codeless link.
    expect(route('pickem.join', ['by' => 'marcus'], absolute: false))->toBe('/join?by=marcus');

    User::factory()->create(['handle' => 'marcus']);
    config(['cfb.pickem_open' => true]);

    $this->get(route('pickem.join', ['by' => 'marcus']))
        ->assertOk()
        ->assertSee('&commat;marcus invited you', escape: false)
        ->assertSee(Voice::line('join.app.heading'));
});

it('credits a real inviter on a codeless link and says nothing about a fake one', function () {
    Feature::define('pickem', true);

    User::factory()->create(['handle' => 'marcus']);

    Livewire::withQueryParams(['by' => 'marcus'])
        ->test('join')
        ->assertSeeHtml('&commat;marcus invited you');

    Livewire::withQueryParams(['by' => 'nobody_here'])
        ->test('join')
        ->assertOk()
        ->assertDontSee('invited you');
});

it('walks a codeless guest to REGISTER, and the intended URL is the LOBBY', function () {
    /*
     * Register for the same reason the coded invite does. The difference
     * is the destination: there is nothing here to be seated into, so
     * coming back would only show the pitch a second time.
     */
    Feature::define('pickem', true);

    Livewire::test('join')
        ->call('start')
        ->assertRedirect(route('register'));

    expect(session('url.intended'))->toBe(route('pickem.lobby', absolute: false));
});

it('sends a signed-in codeless visitor straight to the Lobby', function () {
    Livewire::actingAs(pickemAdmin())
        ->test('join')
        ->call('start')
        ->assertRedirect(route('pickem.lobby'));
});

it('bounces a codeless guest to My Picks while the flag is closed', function () {
    /*
     * NOT a bug, and the rehearsal is what it proves: the flag gates the
     * whole pick'em surface before the flip, and a link into a surface
     * that does not exist yet belongs on the area's own coming-soon page.
     * The link goes live with the surface it points at.
     */
    expect(Feature::for(null)->active('pickem'))->toBeFalse();

    Livewire::withQueryParams(['by' => 'marcus'])
        ->test('join')
        ->assertRedirect(route('pickem.home'));
});

it('counts a codeless open on the signal that already exists', function () {
    /*
     * UxSignal is a bounded vocabulary — eight named signals and nothing
     * else may be counted. InviteOpened fires in mount() BEFORE the group
     * lookup, so a codeless open is measured for free and this change
     * adds no case. This is the test that keeps that true.
     */
    Feature::define('pickem', true);

    Redis::connection('pulse')->flushdb();

    Livewire::test('join')->assertOk();

    $counts = (array) Redis::connection('pulse')->hgetall(RecordUxEvent::dayKey('2026-09-02'));

    expect(array_map('intval', $counts))->toBe(['invite_opened' => 1]);
});

it('wears the group\'s own mark on the preview, and the mode tile only when it has none', function () {
    Feature::define('pickem', true);
    Storage::fake(config('cfb.upload_disk'));

    [, $group] = pickemContest(ContestMode::Tiered);

    // Unmarked: the mode tile stands in, and no image is invented for it.
    Livewire::test('join', ['code' => strtolower($group->code)])
        ->assertDontSeeHtml('object-cover');

    // Marked: the uploaded icon is the mark a QR scan lands on — the same
    // one the clubhouse wears — never the mode's tile in its place.
    $group->forceFill(['icon' => 'group-icons/vols.jpg'])->save();

    Livewire::test('join', ['code' => strtolower($group->code)])
        ->assertSeeHtml('src="'.$group->fresh()->iconUrl().'"')
        ->assertSeeHtml('object-cover');
});

it('lets a long name wrap beside the chip instead of truncating it', function () {
    Feature::define('pickem', true);

    [, $group] = pickemContest(ContestMode::Tiered);
    $group->update(['name' => 'VOLS 101: No Prerequisites Whatsoever']);

    // The name is the one thing a scan has to confirm: two lines, never an
    // ellipsis, and the chip off the title row below sm so it has the width.
    Livewire::test('join', ['code' => strtolower($group->code)])
        ->assertSee('VOLS 101: No Prerequisites Whatsoever')
        ->assertSeeHtml('line-clamp-2 break-words text-xl')
        ->assertDontSeeHtml('class="truncate text-xl')
        ->assertSeeHtml('hidden shrink-0 rounded-full');
});
