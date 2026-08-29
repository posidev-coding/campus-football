<?php

use App\Actions\EnterTiebreaker;
use App\Actions\GrantWalletEntry;
use App\Actions\JoinGroup;
use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Actions\SpawnPublicContest;
use App\Enums\ContentRating;
use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use App\Models\Week;
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
                'Have an invite code?',
                'The Lobby',
            ])
            // The payoff is a FORK away now, not four zones down: the week
            // is what you can still act on, and it does not carry what
            // already happened.
            ->assertDontSee('Last week')
            ->set('view', 'results')
            ->assertSeeInOrder(['Last week', 'Season history'])
            // ...and the fork holds in the other direction.
            ->assertDontSee('Needs your picks');
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

    it('states the condition instead of selling a build the Saturday cannot seat', function () {
        /*
         * Week 0: seven lined games, which fills neither Shotgun's ten
         * nor the Woodshed's fifteen. The card's blue "Build the slate"
         * would send the commissioner to a wizard that now refuses to
         * open, and the deadline beside it would be a clock on work
         * nobody can do — so the card says the condition in the lobby's
         * own words and the clubhouse says the numbers.
         */
        $this->travelTo('2026-08-26 16:00:00');

        [, $week] = splitPickemWeek();

        foreach (Game::query()->whereDate('kickoff_at', '2026-08-29')->get() as $game) {
            pickemOdd($game);
        }

        [$commissioner, $group] = pickemContest(ContestMode::Classic);

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee($group->name)
            ->assertSee('Not enough games this Saturday')
            ->assertDontSee('Build the slate');
    });

    it('names both ways to play on a first run, and the modes under one of them', function () {
        /*
         * The doors ARE the pitch and the create door both. The old screen
         * carried a second full-width "Start a group" card underneath
         * them, which is the same destination drawn twice.
         *
         * What the doors could never say is what they were doors TO: a
         * reader with no groups was shown three modes and no word about
         * the container they were about to create, nor about the store
         * selling the other, weekly, public product.
         */
        Livewire::actingAs(pickemAdmin())->test('pickem-home')
            ->assertSeeInOrder([
                'Two ways to play',
                'Start your own group',
                'Shotgun',
                'The Woodshed',
                'Or take a seat this Saturday',
                'The Lobby',
            ])
            ->assertSee(route('pickem.create'), escape: false)
            ->assertSee(route('pickem.lobby'), escape: false)
            ->assertDontSee('Your groups');
    });

    it('draws the lobby door exactly once on a first run', function () {
        /*
         * The door is hoisted up beside the mode doors when there are no
         * groups, so the two ways to play sit together. Left rendering at
         * the foot of the screen as well, it would be the same
         * destination twice — the mistake the first-run block exists to
         * retire — and the count would be read from one computed but
         * printed on two rows a reader has to reconcile.
         */
        $this->travelTo('2026-09-02 12:00:00');

        [, $week] = pickemHomeWeek();
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

        $html = Livewire::actingAs(pickemAdmin())->test('pickem-home')->html();

        expect(substr_count($html, '1 public room open this Saturday'))->toBe(1);
    });

    it('keeps a small escape to the wizard once the reader has groups', function () {
        [$commissioner] = pickemContest();

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee('Your groups')
            ->assertSee('Start a group')
            ->assertSee(route('pickem.create'), escape: false)
            // With groups in hand the doors are the wizard's job, not the
            // dashboard's.
            ->assertDontSee('Two ways to play')
            ->assertDontSee('Start your own group');
    });

    it('files a private group and a joined room under their own headings', function () {
        /*
         * THE BUG THIS RETIRES: one heading, "Your groups", over both
         * products — a public room joined an hour ago sat in the same
         * stack under the same word as a season-long group, and nothing
         * on the screen said either one was what it was.
         */
        $this->travelTo('2026-09-02 12:00:00');

        [$commissioner, $group] = pickemContest();
        $group->update(['name' => 'Rocky Top Rejects']);

        [, $week] = pickemHomeWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        app(JoinGroup::class)->handle($commissioner, $room);

        Livewire::actingAs($commissioner->fresh())->test('pickem-home')
            ->assertSeeInOrder([
                'Your groups',
                'Rocky Top Rejects',
                'Public rooms',
                $room->name,
            ])
            // And each heading carries its definition, which is the whole
            // point of splitting them.
            ->assertSee(Voice::line('picks.groups.subheading', for: $commissioner))
            ->assertSee(Voice::line('picks.rooms.subheading', for: $commissioner))
            ->assertSee(route('pickem.lobby'), escape: false);
    });

    it('shows the first-run pitch to a reader who holds only a room', function () {
        // The zones are split on KIND, so "no groups" is no PRIVATE
        // groups — a reader with one public seat and nothing else has
        // still never seen the season-long half of the product.
        $this->travelTo('2026-09-02 12:00:00');

        $viewer = pickemAdmin();
        [, $week] = pickemHomeWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        app(JoinGroup::class)->handle($viewer, $room);

        Livewire::actingAs($viewer->fresh())->test('pickem-home')
            ->assertSee('Two ways to play')
            ->assertSee('Start your own group')
            ->assertSee('Public rooms')
            ->assertDontSee('Your groups');
    });

    it('tells a room whose Saturday is gone from one waiting on a commissioner', function () {
        /*
         * A room keeps its URL forever and leaves the inventory when its
         * week ends, so it has no slate for the CURRENT week and falls
         * through the state match to 'waiting'. The waiting line names a
         * commissioner the room never had, on a week that is never
         * coming — over the very card meant to teach that rooms are
         * transient.
         */
        $this->travelTo('2026-09-02 12:00:00');

        $viewer = pickemAdmin();
        [$season, $week] = pickemHomeWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        app(JoinGroup::class)->handle($viewer, $room);

        // Same room, an earlier week — the room AND the slate it played,
        // which is what leaves it with nothing on the current week.
        $gone = Week::factory()->create(['season_id' => $season->id, 'number' => 0]);
        $room->update(['week_id' => $gone->id]);
        Slate::query()
            ->whereIn('contest_id', $room->contests()->pluck('id'))
            ->update(['week_id' => $gone->id]);

        Livewire::actingAs($viewer->fresh())->test('pickem-home')
            ->assertSee(Voice::line('group.room.past', for: $viewer))
            ->assertDontSee(Voice::line('group.slate.waiting', for: $viewer));
    });

    it('never tells a room to go rattle a commissioner it does not have', function () {
        /*
         * A room with no card on a week that has NOT gone by — its slate
         * never landed, or was taken away (a rehearsal purge is exactly
         * how this happened in production). `past` is false, so the card
         * fell through to the group waiting line and told the reader to
         * go rattle the cage of a commissioner the house never seats.
         *
         * The room keeps its membership and its URL, so the card still
         * travels; only the words change.
         */
        $this->travelTo('2026-09-02 12:00:00');

        $viewer = pickemAdmin();
        [, $week] = pickemHomeWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        app(JoinGroup::class)->handle($viewer, $room);

        // The slate goes, the room stays — on the CURRENT week, which is
        // what tells this apart from a room whose Saturday is done.
        Slate::query()->whereIn('contest_id', $room->contests()->pluck('id'))->delete();

        Livewire::actingAs($viewer->fresh())->test('pickem-home')
            ->assertSee(Voice::line('group.room.no_card', for: $viewer))
            ->assertDontSee(Voice::line('group.slate.waiting', for: $viewer))
            ->assertDontSee(Voice::line('group.room.past', for: $viewer));
    });

    /*
     * THE HERO. One card, not a stack of them: the zone used to render
     * every open slate as an identical compact row, so the card about to
     * lock looked exactly like the one that kicks on Sunday.
     */
    it('leads the zone with one hero card, and names the act on its button', function () {
        $this->travelTo('2026-09-02 12:00:00');

        [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
        $group->update(['name' => 'Rocky Top Rejects']);
        $slate = pickemDraftSlate($contest);
        app(PublishSlate::class)->handle($commissioner, $slate);

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee('Rocky Top Rejects')
            ->assertSee('0 of 10')
            ->assertSee('Make your picks')
            // Straight into the clubhouse, which is where picking happens.
            ->assertSee(route('pickem.group', $group), escape: false);

        // One pick in and it is the same card asking for a different act.
        // The label is the AFFORDANCE and stays plain in every register.
        Pick::factory()->create([
            'slate_game_id' => $slate->games->first()->id,
            'user_id' => $commissioner->id,
        ]);

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee('Finish your picks')
            ->assertDontSee('Make your picks');
    });

    it('reads its own count on the Woodshed\'s black tile', function () {
        /*
         * The tile is black in BOTH schemes, and zinc-500 on it is 3.4:1 —
         * unreadable exactly where the number is the point. The mode says
         * its tile is dark (`onDark`); x-slate-progress takes the weight
         * from that, opt-in, so no other caller moves. Verifying a color
         * was applied is not verifying it can be read.
         */
        $this->travelTo('2026-09-02 12:00:00');

        [$commissioner, , $contest] = pickemContest(ContestMode::Woodshed);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee('0 of 15')
            ->assertSeeHtml('text-zinc-300')
            // The light tiles keep the default weight.
            ->assertDontSeeHtml('bg-zinc-200 sm:w-24');

        expect(ContestMode::Woodshed->palette()['onDark'])->toBeTrue()
            ->and(ContestMode::Classic->palette()['onDark'])->toBeFalse()
            ->and(ContestMode::Tiered->palette()['onDark'])->toBeFalse();
    });

    it('gives the hero to the slate closest to locking, and live outranks a clock', function () {
        $this->travelTo('2026-09-02 12:00:00');

        [$reader, $late, $lateContest] = pickemContest(ContestMode::Classic);
        $late->update(['name' => 'The Late Window']);
        app(PublishSlate::class)->handle($reader, pickemDraftSlate($lateContest));

        $soon = Group::factory()->create(['name' => 'The Noon Kick']);
        GroupMember::factory()->commissioner()->create(['group_id' => $soon->id, 'user_id' => $reader->id]);
        $soonContest = Contest::factory()->create(['group_id' => $soon->id, 'mode' => ContestMode::Classic]);
        $soonSlate = pickemDraftSlate($soonContest);
        app(PublishSlate::class)->handle($reader, $soonSlate);

        Game::query()
            ->whereIn('id', $soonSlate->games->pluck('game_id'))
            ->update(['kickoff_at' => '2026-09-05 12:00:00']);

        // The earlier kickoff takes the hero; the other keeps its compact
        // row, which is what the zone has always drawn.
        Livewire::actingAs($reader)->test('pickem-home')
            ->assertSeeInOrder(['The Noon Kick', 'Make your picks', 'The Late Window'])
            ->assertSeeHtml('wire:key="hero-'.$soon->id.'"')
            ->assertSeeHtml('wire:key="needs-'.$late->id.'"');

        /*
         * A slate already under way outranks any clock: "Live" is the
         * fact that changes what you do next, and a game in progress
         * cannot be read off a kickoff time alone (the feed says so).
         */
        Game::query()
            ->whereIn('id', SlateGame::query()->where('slate_id', $soonSlate->id)->pluck('game_id'))
            ->update(['kickoff_at' => '2026-09-05 23:00:00']);

        $lateGame = Game::query()->whereIn(
            'id',
            SlateGame::query()->whereIn('slate_id', [$lateContest->slates->pluck('id')])->pluck('game_id')
        )->first();

        Game::query()->whereKey($lateGame->id)->update(['status' => 'in']);

        Livewire::actingAs($reader)->test('pickem-home')
            ->assertSeeHtml('wire:key="hero-'.$late->id.'"')
            ->assertSeeHtml('wire:key="needs-'.$soon->id.'"');
    });

    it('forks into This week and Results, and normalizes a nonsense tab both ways', function () {
        /*
         * BOTH halves: #[Url] hydrates without firing the update hook, so
         * a bookmarked ?view=nonsense reaches the fork through mount()
         * alone. Neither half may error.
         */
        [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSet('view', 'week')
            ->assertSee('This week')
            ->assertSee('Results');

        Livewire::actingAs($commissioner)
            ->withQueryParams(['view' => 'nonsense'])
            ->test('pickem-home')
            ->assertSet('view', 'week')
            ->assertSee('Needs your picks');

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->set('view', 'garbage')
            ->assertSet('view', 'week')
            ->assertSee('Needs your picks');
    });

    it('says so honestly when Results has nothing settled in it yet', function () {
        [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->set('view', 'results')
            ->assertSee(Voice::line('picks.results.empty', for: $commissioner))
            ->assertDontSee('Last week');
    });

    it('draws no fork on a first run, and a stale ?view=results lands on the week', function () {
        /*
         * Two tabs saying the same nothing is worse than the one scroll a
         * new reader already had. The ladder stays with them — XP is
         * earned before the first slate is, and they have no Results tab
         * to find it in.
         */
        $reader = pickemAdmin();

        // The stale tab is INERT, not obeyed: there is no plate to undo it
        // with, so the first run renders exactly as it always has.
        Livewire::actingAs($reader)
            ->withQueryParams(['view' => 'results'])
            ->test('pickem-home')
            ->assertSee('Two ways to play')
            ->assertDontSeeHtml('wire:key="picks-view-results"');

        Livewire::actingAs($reader)->test('pickem-home')
            ->assertSee('Two ways to play')
            ->assertDontSeeHtml('wire:key="picks-view-results"')
            // The ladder still reaches them.
            ->assertSee('Walk-On');
    });

    it('gives a rooms-only reader the fork too — a seat is a card', function () {
        $this->travelTo('2026-09-02 12:00:00');

        [, $week] = pickemHomeWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        $reader = pickemAdmin();
        app(JoinGroup::class)->handle($reader, $room);

        Livewire::actingAs($reader->fresh())->test('pickem-home')
            ->assertSee('This week')
            ->set('view', 'results')
            ->assertSee(Voice::line('picks.results.empty', for: $reader))
            ->assertSee('Season history');
    });

    it('says ENTRY IN on the card, and keeps the count while one is missing', function () {
        /*
         * A finished entry is not "15 of 15" — it is done, and the card
         * says the word rather than making a reader do the comparison.
         * Derived from the same rule the pick surface states, so the two
         * screens cannot disagree about one entry.
         */
        $this->travelTo('2026-09-02 12:00:00');

        [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
        $slate = pickemDraftSlate($contest);
        app(PublishSlate::class)->handle($commissioner, $slate);

        $games = $slate->games()->with('game')->orderBy('position')->get();

        foreach ($games->take($games->count() - 1) as $slateGame) {
            app(MakePick::class)->handle($commissioner, $slateGame, $slateGame->game->home_team_id);
        }

        app(EnterTiebreaker::class)->handle($commissioner, $slate, 45);

        // One game short: the count stands, and so does the hero's ask.
        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee('9 of 10')
            ->assertDontSee('Entry in');

        app(MakePick::class)->handle($commissioner, $games->last(), $games->last()->game->home_team_id);

        Livewire::actingAs($commissioner)->test('pickem-home')
            ->assertSee('Entry in')
            // ...and the zone that asks for picks has nothing left to ask.
            ->assertDontSee('Needs your picks');
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
            ->set('view', 'results')
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
            ->assertSee('2 public rooms open this Saturday')
            ->assertSee(route('pickem.lobby'), escape: false)
            // The store's inventory stays in the store: no room names, no
            // Join buttons, no blurbs on the dashboard.
            ->assertDontSee('Hail Mary');
    });

    it('counts one room in the singular', function () {
        [, $week] = pickemHomeWeek();
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

        Livewire::actingAs(pickemAdmin())->test('pickem-home')
            ->assertSee('1 public room open this Saturday');
    });

    it('never counts a seat the reader already holds', function () {
        [, $week] = pickemHomeWeek();
        $viewer = pickemAdmin();

        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        $seated = app(SpawnPublicContest::class)->handle(ContestMode::Tiered, $week);
        app(JoinGroup::class)->handle($viewer, $seated);

        Livewire::actingAs($viewer->fresh())->test('pickem-home')
            ->assertSee('1 public room open this Saturday');
    });

    it('says the honest empty line at zero rather than "0 rooms"', function () {
        $viewer = pickemAdmin();

        Livewire::actingAs($viewer)->test('pickem-home')
            ->assertSee('The Lobby')
            ->assertSee(Voice::line('lobby.publics.empty', for: $viewer))
            ->assertDontSee('0 public rooms open');
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
