<?php

use App\Actions\PublishSlate;
use App\Actions\SpawnPublicContest;
use App\Enums\ContentRating;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\Cadence;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * THE LOBBY — the contest browser, pass 4. Outside the `pickem` flag it
 * keeps the coming-soon promise the Picks tab shipped with, verbatim;
 * inside it a store: the Saturday pinned in a sticky band, then named
 * shelves of uniform rows, the dashed rows for what this Saturday could
 * not seat, and the house rules.
 *
 * The personal half lives on MY PICKS now (PickemHomeTest), and the last
 * describe here exists to keep it from creeping back.
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

describe('the store (inside the flag)', function () {
    beforeEach(function () {
        $this->travelTo('2026-09-02 12:00:00');
    });

    /** A week with enough suggestible games for any mode's standard slate. */
    function lobbyScreenWeek(): array
    {
        [$season, $week] = pickemSeasonWeek();

        foreach (range(1, 16) as $i) {
            $game = pickemGame($season, $week);
            pickemOdd($game);
            $game->predictor()->create(['matchup_quality' => 95 - $i]);
        }

        return [$season, $week];
    }

    it('pins the Saturday being sold, counted the way the fans count it', function () {
        /*
         * The split opening week: ONE ESPN week row spanning 8/22 to 9/8,
         * with cards on 8/29 and 9/5 and nothing on the 8/22 the range
         * opens with. The band must say WEEK 0 and the card's own date —
         * the week's range never reaches a reader, because "AUG 22" is a
         * date nobody is playing.
         */
        $this->travelTo('2026-08-25 12:00:00');
        [, $week] = splitPickemWeek();

        foreach (Game::query()->whereDate('kickoff_at', '2026-08-29')->get() as $game) {
            pickemOdd($game);
            $game->predictor()->create(['matchup_quality' => 90.0]);
        }

        // The 8/29 card explicitly — the week's PRIMARY Saturday is the
        // busier 9/5, and the lobby sells the card in front of it.
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, Cadence::activeSaturday($week));

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('Week 0')
            ->assertSee('Sat Aug 29')
            ->assertSee('1 room open')
            ->assertDontSee('Aug 22');
    });

    it('shelves the rooms under plain headings, house first', function () {
        [, $week] = lobbyScreenWeek();
        $viewer = pickemAdmin();

        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::UpsetAlley);

        Livewire::actingAs($viewer)->test('lobby')
            ->assertSeeInOrder([
                'House rooms',
                'Hail Mary',
                'Quick hits',
                'Two-Minute Drill',
                'Spotlight',
                'Upset Alley',
            ])
            // Headings are plain — people navigate by them. The register
            // line rides underneath.
            ->assertSee(Voice::line('lobby.shelf.house', for: $viewer));
    });

    it('sells one uniform row: name, the facts, and a door into the room', function () {
        [, $week] = lobbyScreenWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        $flash = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('Hail Mary')
            ->assertSee('Shotgun')
            ->assertSee('10 games')
            ->assertSee('0 of 20 seats')
            ->assertSee('Join')
            // The row itself opens the room; Join is the one-tap seat.
            ->assertSee(route('pickem.room', $room), escape: false)
            ->assertSee(route('pickem.room', $flash), escape: false)
            // Blurbs and zingers moved to the room screen — a shelf of
            // thirteen pitches is an essay, not a shelf.
            ->assertDontSee('The flash card: 5 games, in and out.');
    });

    it('drops the Join CTA to a flat cue in a room the reader already sits in', function () {
        /*
         * The shelves are SEAT-INCLUSIVE — a room you joined an hour ago
         * must not render as closed — so the row has to tell "for sale"
         * from "yours". It shipped selling both: a seated reader was
         * offered a primary Join for a seat they already hold.
         */
        [, $week] = lobbyScreenWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        $viewer = pickemAdmin();

        Livewire::actingAs($viewer)->test('lobby')
            ->assertSee('wire:click="joinLobby('.$room->id.')"', escape: false)
            ->assertDontSee('View picks');

        GroupMember::factory()->create(['group_id' => $room->id, 'user_id' => $viewer->id]);

        Livewire::actingAs($viewer)->test('lobby')
            ->assertSee('View picks')
            // The row is still the door to the room; only the CTA goes.
            ->assertSee(route('pickem.room', $room), escape: false)
            ->assertDontSee('wire:click="joinLobby('.$room->id.')"', escape: false);
    });

    it('folds what this Saturday could not seat into one line — Conference keeps its names', function () {
        /*
         * Eight lined games: Shotgun downsizes and sells, the fifteen-game
         * modes cannot publish at all. Thirteen catalog shapes with three
         * stocked used to render TEN dashed rows — a gray wall, and the
         * first thing an invited user saw. The unstocked shapes now fold
         * into one muted line per shelf; the Conference shelf keeps named
         * rows because its entries are identities a fan scans for.
         */
        [$season, $week] = pickemSeasonWeek();

        foreach (range(1, 8) as $i) {
            $game = pickemGame($season, $week);
            pickemOdd($game);
            $game->predictor()->create(['matchup_quality' => 95 - $i]);
        }

        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

        $admin = pickemAdmin();

        Livewire::actingAs($admin)->test('lobby')
            ->assertSee('Hail Mary')
            // The House shelf's closed shapes, as one sentence...
            ->assertSee(Voice::line('lobby.shelf.also', ['list' => 'Triple Option · The Woodshed'], for: $admin))
            // ...never as their own dashed rows.
            ->assertDontSeeHtml('closed-tiered-standard')
            // The Conference shelf keeps the named rows, in the
            // preflight's words.
            ->assertSee('SEC Showdown')
            ->assertSee('Not enough games this Saturday');
    });

    it('seats a joiner and lands them at the room\'s own address', function () {
        [, $week] = pickemSeasonWeek();
        $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 20]);
        $admin = pickemAdmin();

        Livewire::actingAs($admin)->test('lobby')
            ->call('joinLobby', $room->id)
            ->assertRedirect(route('pickem.room', $room));

        expect(GroupMember::where(['group_id' => $room->id, 'user_id' => $admin->id])->exists())->toBeTrue();
    });

    it('sells the evergreen table after the Saturday, and lands it at its clubhouse', function () {
        $lobby = Group::factory()->lobby()->create(['name' => 'The Big Lobby']);
        $admin = pickemAdmin();

        Livewire::actingAs($admin)->test('lobby')
            ->assertSee('Always open')
            ->assertSee('The Big Lobby')
            ->call('joinLobby', $lobby->id)
            ->assertRedirect(route('pickem.group', $lobby));
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

    it('shows an honestly empty store', function () {
        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('No open rooms right now')
            // And never dashes the whole catalog out: with nothing
            // stocked, absence proves nothing.
            ->assertDontSee('Not enough games this Saturday');
    });

    it('names the other product, and what makes it other', function () {
        // "Rather run your own?" asked a question of somebody who had
        // never been told the season-long thing exists. The cross-link
        // says what it is now, and the store says what IT is up top.
        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('Want a season-long group?')
            ->assertSee('Private and invite-only')
            ->assertSee(route('pickem.create'), escape: false);
    });

    it('says what the store sells before the first shelf', function () {
        $viewer = pickemAdmin();

        Livewire::actingAs($viewer)->test('lobby')
            ->assertSeeInOrder([
                'Public rooms — anyone can take a seat, and each one plays a single Saturday.',
                Voice::line('lobby.intro.zinger', for: $viewer),
            ], escape: false);
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

    it('is nobody\'s dashboard: the personal zones are gone', function () {
        [$commissioner, , $contest] = pickemContest(ContestMode::Tiered);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        Livewire::actingAs($commissioner)->test('lobby')
            ->assertDontSee('Needs your picks')
            ->assertDontSee('Your groups')
            ->assertDontSee('Last week')
            ->assertDontSee('Have an invite code?')
            ->assertDontSee('Pick your mode');
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
