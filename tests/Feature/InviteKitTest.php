<?php

use App\Enums\ContestMode;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\Brand;
use App\Support\Cadence;
use App\Support\InviteTemplates;
use App\Support\PageMeta;
use App\Support\QrCode;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

/*
 * THE INVITE KIT — everything an invite needs beyond the URL itself: the
 * card the link unfurls as, the square a phone can scan, and the message
 * somebody actually sends.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

// ─────────────────────────────────────────────────────────────────────
// PageMeta — the share card
// ─────────────────────────────────────────────────────────────────────

it('falls back to the brand for every slot nobody set', function () {
    $meta = new PageMeta;

    expect($meta->title())->toBe(Brand::name())
        ->and($meta->windowTitle())->toBe(Brand::name())
        ->and($meta->description())->toBe(Brand::tagline())
        ->and($meta->image())->toBe(Brand::asset('og-image'));
});

it('leaves a slot alone when a later caller passes null for it', function () {
    /*
     * set() is additive per slot. A caller holding only a description
     * must not be able to blank a title somebody else set — which is the
     * shape a "clear everything not passed" implementation would have.
     */
    $meta = new PageMeta;
    $meta->set(title: 'Behind the Woodshed');
    $meta->set(description: 'Fifteen games.');

    expect($meta->title())->toBe('Behind the Woodshed')
        ->and($meta->description())->toBe('Fifteen games.');
});

it('treats blank and whitespace as nothing to say, never as a value', function () {
    // A group named with a stray newline must not produce a card whose
    // title is a newline — and an empty string is a caller with nothing
    // to say, which falls through to the brand exactly like null.
    $meta = new PageMeta;
    $meta->set(title: '   ', description: "two\n\nlines   here");

    expect($meta->title())->toBe(Brand::name())
        ->and($meta->description())->toBe('two lines here');
});

it('adds the brand to the window title but never to the share headline', function () {
    // Slack prints og:site_name on its own line, so repeating it inside
    // og:title reads as a stutter. A browser tab has no such frame.
    $meta = new PageMeta;
    $meta->set(title: 'Behind the Woodshed · Woodshed');

    expect($meta->windowTitle())->toBe('Behind the Woodshed · Woodshed · '.Brand::name())
        ->and($meta->title())->toBe('Behind the Woodshed · Woodshed');
});

it('unfurls a live invite as the group and its game', function () {
    Feature::define('pickem', true);

    [, $group] = pickemContest(ContestMode::Woodshed);
    $group->update(['name' => 'Behind the Woodshed']);

    $this->get("/join/{$group->code}")
        ->assertOk()
        ->assertSee('<meta property="og:title" content="Behind the Woodshed · Woodshed">', escape: false)
        ->assertSee('og:image" content="'.route('brand.invite-card', ['mode' => 'woodshed']).'"', escape: false);
});

it('unfurls a DEAD code and a codeless invite as the brand, inventing no group', function () {
    /*
     * THE BREAK-IT-BACK GUARD, and the reason this names the whole tag
     * rather than asserting a string appears: Brand::name() is on every
     * page either way, so "the brand appears" passes even when a group's
     * name has been written into og:title. Point describeForSharing() at
     * a dead code and this is the test that goes red.
     */
    Feature::define('pickem', true);

    $expected = '<meta property="og:title" content="'.Brand::name().'">';

    $this->get('/join/NOSUCHCD')->assertOk()->assertSee($expected, escape: false);
    $this->get('/join')->assertOk()->assertSee($expected, escape: false);
});

it('keeps a private group\'s people out of a card crawlers cache', function () {
    /*
     * The link is the credential, so the NAME is fair — anybody holding
     * the URL reads it off the page a second later. A member count is
     * different: it outlives the tap in every crawler and proxy that ever
     * touched the URL, and it buys the reader nothing.
     */
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest(ContestMode::Woodshed);
    $group->update(['name' => 'Behind the Woodshed']);

    $html = $this->get("/join/{$group->code}")->assertOk()->getContent();
    $head = str($html)->before('</head>')->toString();

    expect($head)->toContain('Behind the Woodshed')
        ->and($head)->not->toContain('member')
        ->and($head)->not->toContain($commissioner->name);
});

it('serves one share card per MODE and 404s anything else', function () {
    // Per-mode, never per-group: a group-shaped image URL would make a
    // private group's existence a distinct entry in every proxy log.
    $this->get(route('brand.invite-card', ['mode' => 'woodshed']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->get('/brand/invite/nonsense.png')->assertNotFound();
});

it('sends no session cookie with a share card, because a crawler fetches it', function () {
    $response = $this->get(route('brand.invite-card', ['mode' => 'classic']));

    expect($response->headers->getCookies())->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────
// The QR square
// ─────────────────────────────────────────────────────────────────────

it('renders an inline QR with no fixed size and no XML prolog', function () {
    $svg = QrCode::svg('https://campusfootball.test/join/WDSQVFOX');

    expect($svg)->toStartWith('<svg')
        ->and($svg)->not->toContain('<?xml')
        ->and($svg)->toContain('viewBox="0 0 256 256"');

    // Stripped from the OPENING TAG so CSS decides the size; a fixed 256
    // overflows at 390. The background <rect> keeps its own dimensions —
    // those are the drawing's coordinates, not the element's size.
    expect(str($svg)->before('>')->toString())
        ->not->toContain('width=')
        ->not->toContain('height=');
});

it('keeps the QR scannable by carrying its own white plate', function () {
    // Many phone cameras will not read an inverted QR at all, and the
    // failure looks like a broken code rather than a theming choice.
    expect(QrCode::svg('https://campusfootball.test/join/WDSQVFOX'))
        ->toContain('fill="#ffffff"');
});

it('puts a scannable QR on the clubhouse invite panel', function () {
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest(ContestMode::Woodshed);

    Livewire::actingAs($commissioner)
        ->test('group', ['group' => $group])
        ->set('view', 'invite')
        ->assertSee('viewBox="0 0 256 256"', escape: false);
});

// ─────────────────────────────────────────────────────────────────────
// The messages
// ─────────────────────────────────────────────────────────────────────

it('reads the rules off the mode instead of restating them', function () {
    /*
     * ContestMode::ruleLines() is the ONE source the lobby explainer, the
     * mode doors, the join landing and the docs all read. An invitation
     * that spelled the scoring out by hand would be a fourth place for it
     * to drift — and the one people read FIRST.
     */
    $group = Group::factory()->make(['name' => 'Behind the Woodshed', 'code' => 'RQUZXKLZ']);

    $templates = InviteTemplates::for($group, ContestMode::Woodshed, 'https://x.test/join/RQUZXKLZ', 15);
    $slack = collect($templates)->firstWhere('key', 'slack')['body'];

    foreach (ContestMode::Woodshed->ruleLines(15) as $line) {
        expect($slack)->toContain($line);
    }
});

it('reads the deadline off Cadence, which is admin-configurable', function () {
    // It already moved once — Saturday noon in the 2016 founders' league,
    // Thursday noon ET since 2026-08-20. A hardcoded day in an invitation
    // is a support conversation on day one.
    $group = Group::factory()->make(['name' => 'Behind the Woodshed', 'code' => 'RQUZXKLZ']);

    foreach (InviteTemplates::for($group, ContestMode::Woodshed, 'https://x.test/j', 15) as $template) {
        expect($template['body'])->toContain(Cadence::deadlineLabel());
    }
});

it('carries the link in every channel and the code where there is room for it', function () {
    $group = Group::factory()->make(['name' => 'Log In, Vol Out', 'code' => 'WDSQVFOX']);
    $url = 'https://campusfootball.test/join/WDSQVFOX?by=taylor';

    $templates = InviteTemplates::for($group, ContestMode::Classic, $url, 10);

    expect($templates)->toHaveCount(3);

    foreach ($templates as $template) {
        expect($template['body'])->toContain($url);
    }

    // The spoken-word fallback belongs where there is room for it; a text
    // message is the one place a second way in is just noise.
    $keyed = collect($templates)->keyBy('key');
    expect($keyed['slack']['body'])->toContain('WDSQVFOX')
        ->and($keyed['email']['body'])->toContain('WDSQVFOX')
        ->and($keyed['email']['subject'])->toContain('Log In, Vol Out');
});

it('sizes the pitch from the CONTEST, never the mode default', function () {
    // A Week 0 Shotgun room deals eight. An invitation promising ten is
    // the group lying about the game it is selling.
    $group = Group::factory()->make(['name' => 'Walk-ons', 'code' => 'ABCD1234']);

    $eight = InviteTemplates::for($group, ContestMode::Classic, 'https://x.test/j', 8)[0]['body'];

    expect($eight)->toContain('8 games')->and($eight)->not->toContain('10 games');
});

it('warns the invitee about the one step people skip', function () {
    // JoinGroup throws PickemParticipationGated until the address is
    // confirmed, so an invitee who ignores the verification mail cannot
    // take the seat they were just handed.
    $group = Group::factory()->make(['name' => 'Behind the Woodshed', 'code' => 'RQUZXKLZ']);

    $email = collect(InviteTemplates::for($group, ContestMode::Woodshed, 'https://x.test/j', 15))
        ->firstWhere('key', 'email')['body'];

    expect($email)->toContain('confirm your address');
});

it('puts the ready-to-send messages on the clubhouse, keyed for the morph', function () {
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest(ContestMode::Woodshed);

    Livewire::actingAs($commissioner)
        ->test('group', ['group' => $group])
        ->set('view', 'invite')
        ->assertSee('Ready to send')
        ->assertSee('Text message')
        ->assertSee('Slack post')
        ->assertSee('invite-template-email');
});

it('renders the panel without a templates block when there is no contest to describe', function () {
    // Nothing to describe is not the same as a generic description: the
    // link and the QR still render, the rules do not get invented.
    $group = Group::factory()->create();
    $commissioner = pickemAdmin();
    GroupMember::factory()->commissioner()->create([
        'group_id' => $group->id, 'user_id' => $commissioner->id,
    ]);

    $html = Livewire::actingAs($commissioner)
        ->test('group', ['group' => $group])
        ->set('view', 'invite')
        ->assertSee('viewBox="0 0 256 256"', escape: false)
        ->assertDontSee('Ready to send')
        ->html();

    expect($html)->not->toContain('invite-template-');
});

// ─────────────────────────────────────────────────────────────────────
// The handoff
// ─────────────────────────────────────────────────────────────────────

it('hands the seat over in one move, leaving exactly one commissioner', function () {
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest(ContestMode::Woodshed);
    $successor = User::factory()->create(['first_name' => 'Dale']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $successor->id]);

    Livewire::actingAs($commissioner)
        ->test('group', ['group' => $group])
        ->call('handOff', $successor->id)
        ->assertHasNoErrors();

    expect($group->memberships()->where('user_id', $successor->id)->first()->isCommissioner())->toBeTrue()
        ->and($group->memberships()->where('user_id', $commissioner->id)->first()->isCommissioner())->toBeFalse()
        ->and($group->memberships()->where('role', GroupMember::COMMISSIONER)->count())->toBe(1);
});

it('refuses to hand off a seat the actor does not hold', function () {
    Feature::define('pickem', true);

    [, $group] = pickemContest(ContestMode::Woodshed);
    $member = User::factory()->create();
    $other = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $other->id]);

    Livewire::actingAs($member)
        ->test('group', ['group' => $group])
        ->call('handOff', $other->id)
        ->assertForbidden();
});

it('refuses to promote somebody who is not in the group', function () {
    /*
     * Otherwise this is a second door into a private group, reachable by
     * anyone who can name a user. The invite code is the only door.
     */
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest(ContestMode::Woodshed);
    $stranger = User::factory()->create();

    Livewire::actingAs($commissioner)
        ->test('group', ['group' => $group])
        ->call('handOff', $stranger->id)
        ->assertStatus(422);

    expect($group->memberships()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

it('lets the outgoing commissioner leave, which they could not do before', function () {
    /*
     * The dead end this action exists to open: LeaveGroup refuses while
     * anybody else remains, so a founder could neither hand the league
     * over nor walk away from it.
     */
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest(ContestMode::Woodshed);
    $successor = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $successor->id]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])->call('handOff', $successor->id);

    Livewire::actingAs($commissioner)
        ->test('group', ['group' => $group])
        ->call('leave')
        ->assertHasNoErrors();

    expect($group->memberships()->where('user_id', $commissioner->id)->exists())->toBeFalse();
});

it('offers the handoff only to a commissioner', function () {
    Feature::define('pickem', true);

    [$commissioner, $group] = pickemContest(ContestMode::Woodshed);
    $member = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    Livewire::actingAs($commissioner)
        ->test('group', ['group' => $group])
        ->set('view', 'members')
        ->assertSee('Make commissioner');

    Livewire::actingAs($member)
        ->test('group', ['group' => $group])
        ->set('view', 'members')
        ->assertDontSee('Make commissioner');
});
