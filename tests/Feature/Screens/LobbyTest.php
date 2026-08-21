<?php

use App\Actions\PublishSlate;
use App\Enums\ContentRating;
use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * THE LOBBY — where the Picks tab lands. Outside the `pickem` flag it
 * keeps the coming-soon promise the tab shipped with, verbatim; inside it
 * one zoned scroll ordered by urgency: the week ribbon, the slates that
 * still need picks, your groups, last week's payoff, the lobby
 * (rooms, the start door, the folded code form), and every mode's rules.
 */

describe('the promise (outside the flag)', function () {
    it('renders for a guest', function () {
        // Public like every area except Account: the tab is in a guest's bar,
        // and a tab that 403s is worse than no tab.
        $this->get(route('pickem.lobby'))
            ->assertOk()
            ->assertSee("Pick'em", escape: false)
            ->assertSee('Coming soon')
            ->assertSee('Weekly slates')
            ->assertSee('Groups');
    });

    it('renders the same promise signed in', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('pickem.lobby'))
            ->assertOk()
            ->assertSee("Pick'em", escape: false)
            ->assertSee('Coming soon');
    });

    it('lights its own tab rather than borrowing another area', function () {
        $this->get(route('pickem.lobby'))
            ->assertOk()
            ->assertSee('aria-current="page"', escape: false);
    });

    it('explains the verification gate to the unverified, right at the gate', function () {
        /*
         * Verification's ONE gate is participation — picks and XP — so the
         * explanation lives here, and it is not dismissable: an explanation
         * you can dismiss becomes a mystery next visit.
         */
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('pickem.lobby'))
            ->assertOk()
            ->assertSee('get in the game')
            ->assertDontSee('cfb.verify.dismissed');
    });

    it('shows no gate to the verified or to guests', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('pickem.lobby'))
            ->assertOk()
            ->assertDontSee('data-verify-callout');

        $this->get(route('pickem.lobby'))
            ->assertOk()
            ->assertDontSee('data-verify-callout');
    });
});

describe('the lobby (inside the flag)', function () {
    it('carries the week\'s state onto each group card, under the week ribbon', function () {
        [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);
        $group->update(['name' => 'Rocky Top Rejects']);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        Livewire::actingAs($commissioner)->test('lobby')
            ->assertSee('Lobby')
            ->assertSee('Week 1')
            ->assertSee('Rocky Top Rejects')
            ->assertSee('Triple Option')
            ->assertSee('of 15');
    });

    it('orders the zones by urgency', function () {
        [$commissioner, , $contest] = pickemContest(ContestMode::Tiered);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        // A second, already-settled game gives the recap zone something.
        $settledGroup = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $settledGroup->id, 'user_id' => $commissioner->id]);
        $settledContest = Contest::factory()->create(['group_id' => $settledGroup->id]);
        [, $week] = pickemSeasonWeek();
        $settledSlate = Slate::factory()->create([
            'contest_id' => $settledContest->id,
            'week_id' => $week->id,
            'status' => Slate::SETTLED,
            'settled_at' => now()->subDay(),
        ]);
        SlateEntry::factory()->create([
            'slate_id' => $settledSlate->id,
            'user_id' => $commissioner->id,
            'final_points' => 80,
        ]);

        Livewire::actingAs($commissioner)->test('lobby')
            ->assertSeeInOrder([
                'Needs your picks',
                'Your games',
                'Last week',
                'Find a game',
                "How it's played",
            ]);
    });

    it('walks a first-run visitor through the mode doors', function () {
        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('Pick your game')
            ->assertSee('Shotgun')
            ->assertSee('Triple Option')
            ->assertSee('The Woodshed')
            ->assertSee(route('pickem.create'), escape: false)
            ->assertDontSee('Your games');
    });

    it('spells every mode\'s rules, stakes included', function () {
        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee("How it's played", escape: false)
            ->assertSee('every one worth 10 points')
            ->assertSee('Tier 1 pays 9')
            ->assertSee('8, 6 and 4')
            ->assertSee('+6 right, −4 wrong')
            ->assertSee('101')
            ->assertSee('no pushes, ever');
    });

    it('hands the commissioner the build prompt on a slateless week', function () {
        [$commissioner, $group] = pickemContest(ContestMode::Classic);
        pickemSeasonWeek();

        Livewire::actingAs($commissioner)->test('lobby')
            ->assertSee('Build the slate');
    });

    it('pays the Monday payoff: last week\'s settled result while it\'s fresh', function () {
        [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
        [, $week] = pickemSeasonWeek();
        $slate = Slate::factory()->create([
            'contest_id' => $contest->id,
            'week_id' => $week->id,
            'status' => Slate::SETTLED,
            'settled_at' => now()->subDay(),
        ]);
        SlateEntry::factory()->create([
            'slate_id' => $slate->id,
            'user_id' => $commissioner->id,
            'final_points' => 8,
            'won' => true,
        ]);

        Livewire::actingAs($commissioner)->test('lobby')
            ->assertSee('Last week')
            ->assertSee('8 pts')
            ->assertSee('Winner');
    });

    it('shows the public inventory its honest empty state', function () {
        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('Find a game')
            ->assertSee('No open rooms right now');
    });

    it('lists an open public room with its door', function () {
        Group::factory()->lobby()->create(['name' => 'The Big Lobby']);

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('The Big Lobby')
            ->assertSee('Join');
    });

    it('seats a lobby joiner and lands them in the room', function () {
        $lobby = Group::factory()->lobby()->create();
        $admin = pickemAdmin();

        Livewire::actingAs($admin)->test('lobby')
            ->call('joinLobby', $lobby->id)
            ->assertRedirect(route('pickem.group', $lobby));

        expect(GroupMember::where(['group_id' => $lobby->id, 'user_id' => $admin->id])->exists())->toBeTrue();
    });

    it('lands a room joiner at the room\'s own address — no clubhouse double-hop', function () {
        [, $week] = pickemSeasonWeek();
        $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 20]);
        $admin = pickemAdmin();

        Livewire::actingAs($admin)->test('lobby')
            ->call('joinLobby', $room->id)
            ->assertRedirect(route('pickem.room', $room));

        expect(GroupMember::where(['group_id' => $room->id, 'user_id' => $admin->id])->exists())->toBeTrue();
    });

    it('answers a race to the last seat in Voice, in the lobby', function () {
        [, $week] = pickemSeasonWeek();
        $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 1]);
        GroupMember::factory()->create(['group_id' => $room->id]);
        $admin = pickemAdmin();

        Livewire::actingAs($admin)->test('lobby')
            ->call('joinLobby', $room->id)
            ->assertHasErrors('lobbies')
            ->assertSee(Voice::line('contest.room.full', for: $admin));

        expect(GroupMember::where(['group_id' => $room->id, 'user_id' => $admin->id])->exists())->toBeFalse();
    });

    it('still joins from the folded code form', function () {
        $group = Group::factory()->create();
        $admin = pickemAdmin();

        Livewire::actingAs($admin)->test('lobby')
            ->set('code', strtolower($group->code))
            ->call('join')
            ->assertRedirect(route('pickem.group', $group));

        expect(GroupMember::where(['group_id' => $group->id, 'user_id' => $admin->id])->exists())->toBeTrue();
    });
});

describe('the voice', function () {
    it('pitches in each register, and never the same line up the ladder', function () {
        // Pick'em is a LOUD surface even before it exists: the pitch carries
        // the personality, while the feature cards stay plain promises.
        $pg = Voice::line('picks.screen.pitch', for: User::factory()->make(['content_rating' => ContentRating::Pg]));
        $r = Voice::line('picks.screen.pitch', for: User::factory()->make(['content_rating' => ContentRating::R]));

        expect($pg)->not->toBe('')
            ->and($r)->not->toBe('')
            ->and($pg)->not->toBe($r);
    });
});
