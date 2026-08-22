<?php

use App\Actions\GrantWalletEntry;
use App\Actions\JoinGroup;
use App\Actions\PublishSlate;
use App\Actions\SpawnPublicContest;
use App\Enums\ContentRating;
use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Support\Navigation;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * MY PICKS — the reader's own pick'em week at /picks, split off the lobby
 * 2026-08-20. Outside the `pickem` flag it renders the same coming-soon
 * promise the Lobby does, because both doors have to answer a guest.
 *
 * The fact this file exists to hold beyond the zones: the lobby teaser
 * sells a NUMBER, and that number is the one thing on this screen that
 * could quietly disagree with the store it opens.
 */

/** A week with enough suggestible games for any mode's standard slate. */
function pickemHomeWeek(): array
{
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 16) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 95 - $i]);
    }

    return [$season, $week];
}

describe('the promise (outside the flag)', function () {
    it('renders for a guest', function () {
        // Public like every area except Account: the tab is in a guest's
        // bar, and a tab that 403s is worse than no tab.
        $this->get(route('pickem.home'))
            ->assertOk()
            ->assertSee("Pick'em", escape: false)
            ->assertSee('Coming soon')
            ->assertSee('Weekly slates');
    });

    it('renders the same promise to a signed-in reader outside the flag', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('pickem.home'))
            ->assertOk()
            ->assertSee('Coming soon')
            ->assertDontSee('Your groups');
    });

    it('shows an admin the real screen, at both doors', function () {
        // The four-cell matrix's remaining corner: flag on, and both
        // routes are real 200s with no hop between them.
        $this->actingAs(pickemAdmin())->get(route('pickem.home'))
            ->assertOk()
            ->assertDontSee('Coming soon');

        $this->actingAs(pickemAdmin())->get(route('pickem.lobby'))->assertOk();
    });

    it('explains the verification gate at the gate', function () {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('pickem.home'))
            ->assertOk()
            ->assertSee('get in the game');
    });
});

describe('my week (inside the flag)', function () {
    it('orders the zones by urgency', function () {
        [$commissioner, , $contest] = pickemContest(ContestMode::Tiered);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        // A second, already-settled week gives the payoff zone something.
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

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSeeInOrder([
                'My Picks',
                'Needs your picks',
                // GROUPS, not "games" — a game is played on a field.
                'Your groups',
                'Last week',
                'Have an invite code?',
                'The Lobby',
            ]);
    });

    it('carries the week\'s state onto each group card, under the week ribbon', function () {
        [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);
        $group->update(['name' => 'Rocky Top Rejects']);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee('Week 1')
            ->assertSee('Rocky Top Rejects')
            ->assertSee('Triple Option')
            ->assertSee('of 15');
    });

    it('dates a group card from its OWN Saturday inside a split week', function () {
        /*
         * 2026's Week 1 holds two cards, 8/29 and 9/5. Reading the deadline
         * off the WEEK resolves through saturdayOf() — the busier 9/5 — so a
         * group playing 8/29 was told its picks were due 9/3, a week after
         * the games had been played. This lands on the rehearsal Saturday,
         * which is the only real one before launch.
         */
        $this->travelTo('2026-08-26 16:00:00');

        [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
        [, $week] = splitPickemWeek();

        $slate = Slate::factory()->create([
            'contest_id' => $contest->id,
            'week_id' => $week->id,
            'saturday' => '2026-08-29',
            'status' => Slate::PUBLISHED,
            'published_at' => now(),
        ]);

        $card = Livewire::actingAs($commissioner)->test('pickem-home')
            ->instance()->cards->firstWhere('contest.id', $contest->id);

        // Thursday noon before the card being played, not before the next one.
        expect($card['deadline']->toDateString())->toBe('2026-08-27')
            ->and($card['deadline']->toDateString())->not->toBe('2026-09-03');

        unset($slate);
    });

    it('makes the mode doors the only create affordance on a first run', function () {
        /*
         * The doors ARE the pitch and the create door both. The old screen
         * carried a second full-width "Start a group" card underneath
         * them, which is the same destination drawn twice.
         */
        Livewire::actingAs(pickemAdmin())->test('pickem-home')
            ->assertSee('Pick your mode')
            ->assertSee('Shotgun')
            ->assertSee('The Woodshed')
            ->assertSee(route('pickem.create'), escape: false)
            ->assertDontSee('Your groups')
            ->assertDontSee('Start a group');
    });

    it('keeps a small escape to the wizard once the reader has groups', function () {
        [$commissioner] = pickemContest();

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee('Your groups')
            ->assertSee('Start a group')
            ->assertSee(route('pickem.create'), escape: false)
            // With groups in hand the doors are the wizard's job, not the
            // dashboard's.
            ->assertDontSee('Pick your mode');
    });

    it('pays the Monday payoff while it is still the conversation', function () {
        [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
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

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee('Last week')
            ->assertSee('8 pts')
            ->assertSee('Winner');
    });

    it('names the next rung and the climb to it, in one compacted row', function () {
        $user = User::factory()->create(['admin' => true, 'content_rating' => ContentRating::Pg13]);
        app(GrantWalletEntry::class)->handle($user, 1000, 0, 'test-seed', 'test-seed');

        Livewire::actingAs($user->fresh())->test('pickem-home')
            ->assertSee('Rotation')
            ->assertSee('1,000 XP')
            ->assertSee(Voice::line('rank.to_next', ['remaining' => '750', 'next' => 'Starter'], for: $user));

        // At the top the climb line is SKIPPED, never a finished bar under
        // a promotion that is not coming.
        $top = User::factory()->create(['admin' => true, 'content_rating' => ContentRating::Pg13]);
        app(GrantWalletEntry::class)->handle($top, 20000, 0, 'test-seed', 'test-seed');

        Livewire::actingAs($top->fresh())->test('pickem-home')
            ->assertSee('Legend')
            ->assertSee(Voice::line('rank.topped_out', for: $top));
    });
});

describe('the lobby teaser', function () {
    beforeEach(function () {
        $this->travelTo('2026-09-02 12:00:00');
    });

    it('sells a plain count that opens the store', function () {
        [, $week] = pickemHomeWeek();

        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        app(SpawnPublicContest::class)->handle(ContestMode::Tiered, $week);

        Livewire::actingAs(pickemAdmin())->test('pickem-home')
            ->assertSee('The Lobby')
            ->assertSee('2 rooms open this Saturday')
            ->assertSee(route('pickem.lobby'), escape: false)
            // The store's inventory stays in the store: no room names, no
            // Join buttons, no blurbs on the dashboard.
            ->assertDontSee('Hail Mary');
    });

    it('counts one room in the singular', function () {
        [, $week] = pickemHomeWeek();
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

        Livewire::actingAs(pickemAdmin())->test('pickem-home')
            ->assertSee('1 room open this Saturday');
    });

    it('never counts a seat the reader already holds', function () {
        [, $week] = pickemHomeWeek();
        $viewer = pickemAdmin();

        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        $seated = app(SpawnPublicContest::class)->handle(ContestMode::Tiered, $week);
        app(JoinGroup::class)->handle($viewer, $seated);

        Livewire::actingAs($viewer->fresh())->test('pickem-home')
            ->assertSee('1 room open this Saturday');
    });

    it('says the honest empty line at zero rather than "0 rooms"', function () {
        $viewer = pickemAdmin();

        Livewire::actingAs($viewer)->test('pickem-home')
            ->assertSee('The Lobby')
            ->assertSee(Voice::line('lobby.publics.empty', for: $viewer))
            ->assertDontSee('0 rooms open');
    });
});

describe('the invite code', function () {
    it('joins from the folded form and lands in the clubhouse', function () {
        $group = Group::factory()->create();
        $admin = pickemAdmin();

        Livewire::actingAs($admin)->test('pickem-home')
            ->set('code', strtolower($group->code))
            ->call('join')
            ->assertRedirect(route('pickem.group', $group));

        expect(GroupMember::where(['group_id' => $group->id, 'user_id' => $admin->id])->exists())->toBeTrue();
    });

    it('answers a bad code in Voice, with the form already open', function () {
        $admin = pickemAdmin();

        Livewire::actingAs($admin)->test('pickem-home')
            ->set('code', 'NOPENOPE')
            ->call('join')
            ->assertHasErrors('code')
            ->assertSee(Voice::line('groups.join.bad_code', for: $admin))
            // The disclosure opens itself on an error — an error inside a
            // folded panel is an error nobody reads.
            ->assertSee('{ open: true }', escape: false);
    });
});

it('lights My Picks, and lets the Lobby chip keep the store', function () {
    $this->actingAs(pickemAdmin());

    $sections = collect(Navigation::areas())->firstWhere('key', 'picks')['sections'];

    expect(collect($sections)->pluck('label')->all())->toBe(['My Picks', 'Lobby', 'Leaderboard', 'History'])
        ->and($sections[0]['route'])->toBe('pickem.home');
});
