<?php

use App\Actions\PublishSlate;
use App\Actions\SpawnPublicContest;
use App\Enums\ContentRating;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Support\Cadence;
use App\Support\Voice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
         *
         * The absence to assert is the dismiss CONTROL, not the storage key:
         * the callout defines its Alpine scope on every render now, because
         * keying the scope to the prop left `dismissed` undefined here and
         * put a bare ReferenceError in production.
         */
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('pickem.lobby'))
            ->assertOk()
            ->assertSee('get in the game')
            ->assertDontSee('aria-label="Dismiss"', escape: false);
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

describe('the store for a guest (inside the flag)', function () {
    beforeEach(function () {
        $this->travelTo('2026-09-02 12:00:00');
        config()->set('cfb.pickem_open', true);
    });

    it('opens the rooms to a guest the moment the flag opens', function () {
        // Launch day: a guest read "Coming soon" here with the flag open,
        // because the store once required a session as well as the flag.
        $this->get(route('pickem.lobby'))
            ->assertOk()
            ->assertDontSee('Coming soon')
            ->assertSee('Public rooms')
            // The invite foot shares the READER's own link — members only.
            ->assertDontSee('Invite a friend');
    });

    it('walks a guest Join to register, with the Lobby as the way back', function () {
        [, $week] = pickemSeasonWeek();
        $room = Group::factory()->lobby()->create(['week_id' => $week->id, 'member_cap' => 20]);

        Livewire::test('lobby')
            ->call('joinLobby', $room->id)
            ->assertRedirect(route('register'));

        expect(session('url.intended'))->toBe(route('pickem.lobby', absolute: false))
            ->and(GroupMember::query()->where('group_id', $room->id)->exists())->toBeFalse();
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

    /*
     * WHEN the Saturday starts. A store selling one Saturday never said
     * when that Saturday began, so "open" and "opens in forty minutes"
     * read the same to a shopper. One aggregate is the whole cost, and
     * every assertion here is END-STATE DOM — the automated tab produces
     * no rendering frames, so nothing waits on a tick.
     */
    it('pins the first kickoff inside the band', function () {
        [, $week] = lobbyScreenWeek();
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

        $html = Livewire::actingAs(pickemAdmin())->test('lobby')->html();

        expect($html)->toContain('data-kick-at="'.Carbon::parse('2026-09-05 19:30:00')->getTimestamp().'"')
            // The band's own words for it — the hero says "kicks", a store
            // says when its Saturday opens.
            ->and($html)->toMatch('/data-kick-at="\d+".*?x-text="label\(\)"\s*>First kick Sat 3:30pm</s');
    });

    it('says nothing about a kickoff when the store is empty', function () {
        /*
         * The Saturday is real and the band renders — this is the case
         * that matters, because a band with a row missing is where a
         * substituted time would hide. Null is NO DATA: no rooms, no
         * slate, no clock, and never a countdown to the epoch.
         */
        lobbyScreenWeek();

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('0 rooms open')
            ->assertSee('No open rooms right now')
            ->assertDontSeeHtml('data-kick-at');
    });

    it('locks a room whose Saturday is under way, and keeps it on the shelf', function () {
        /*
         * A room closes at the FIRST kickoff — but CLOSED IS NOT GONE. The
         * row still reads in full and still opens the slate; only the door
         * changes, so the reader can see what is already running instead of
         * watching rooms vanish through the afternoon.
         *
         * It is not counted, though: "rooms open" sells seats, and this one
         * has none to sell.
         *
         * The clock half is the original guarantee and survives: FUTURE-ONLY,
         * never counting up from zero.
         */
        [, $week] = lobbyScreenWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

        Game::query()
            ->whereIn('id', SlateGame::query()
                ->whereIn('slate_id', Slate::query()->whereIn('contest_id', $room->contests()->pluck('id'))->pluck('id'))
                ->pluck('game_id'))
            ->update(['kickoff_at' => '2026-09-01 19:30:00']);

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee($room->name)
            ->assertSee('Kicked off')
            ->assertSee('0 rooms open')
            ->assertDontSeeHtml('data-kick-at');
    });

    it('locks it on the FIRST kickoff, not the last', function () {
        /*
         * The line the card turns on. One game started, the rest still to
         * come: the old guard asked `every()` and kept the room for sale
         * through the whole afternoon.
         */
        [, $week] = lobbyScreenWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

        $gameIds = SlateGame::query()
            ->whereIn('slate_id', Slate::query()->whereIn('contest_id', $room->contests()->pluck('id'))->pluck('id'))
            ->orderBy('position')
            ->pluck('game_id');

        // Exactly one of them kicks; everything else stays ahead of us.
        Game::query()->whereIn('id', $gameIds)->update(['kickoff_at' => '2026-09-12 19:30:00']);
        Game::query()->where('id', $gameIds->first())->update(['kickoff_at' => '2026-09-01 19:30:00']);

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee($room->name)
            ->assertSee('Kicked off')
            ->assertSee('0 rooms open')
            /*
             * And NO countdown, though this room still holds games to come.
             * The clock says "to first kick", which this card has had — left
             * reading off a started room, the real lobby showed "1:25 to
             * first kick" above eleven rows reading "Kicked off".
             */
            ->assertDontSeeHtml('data-kick-at');
    });

    it('asks for the first kickoff exactly once, and not at all with nothing open', function () {
        /*
         * The +1 pinned query is the ENTIRE cost of this clock. The slate
         * ids come off the relations openRooms() already eager-loads, so
         * a per-room read here would be the class of drift the shelves
         * were built to avoid — and with nothing open there is nothing to
         * aggregate, so the question is never asked.
         */
        $kickReads = function (): int {
            DB::enableQueryLog();

            Livewire::actingAs(pickemAdmin())->test('lobby');

            $count = collect(DB::getQueryLog())
                ->filter(fn (array $query) => str_contains($query['query'], 'min(')
                    && str_contains($query['query'], 'kickoff_at'))
                ->count();

            DB::disableQueryLog();

            return $count;
        };

        // The Saturday exists in both halves, so what changes between
        // them is the STOCK and nothing else.
        [, $week] = lobbyScreenWeek();

        expect($kickReads())->toBe(0);

        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

        // TWO rooms, still one question.
        expect($kickReads())->toBe(1);
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

    it('sells one uniform row: name, its pitch, the facts, and a door into the room', function () {
        [, $week] = lobbyScreenWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        $flash = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

        $html = Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('Hail Mary')
            ->assertSee('Shotgun')
            ->assertSee('10 games')
            ->assertSee('0 of 20 seats')
            ->assertSee('Join')
            // The row itself opens the room; Join is the one-tap seat.
            ->assertSee(route('pickem.room', $room), escape: false)
            ->assertSee(route('pickem.room', $flash), escape: false)
            /*
             * THE REVERSAL, made deliberately on 2026-08-31. This was an
             * assertDontSee: the blurbs moved to the room screen because
             * thirteen stacked pitches is an essay, not a shelf. That was
             * right about PARAGRAPHS and wrong about the shelf — ten
             * flavored rooms shipped with ten personalities and the store
             * rendered none of them, so two names sat over two identical
             * rows. The pitch is back, capped at ONE truncating line,
             * which is what keeps the rows uniform.
             *
             * And it is sized from the CONTEST, never the mode: the flash
             * room deals five, whatever Shotgun's default says.
             */
            ->assertSee('The flash card: 5 games, in and out.')
            ->html();

        /*
         * The unflavored room pitches its MODE, and the mode's blurb has
         * always ridden the rules card too — so the fact to hold is the
         * SECOND occurrence, which is the row's.
         */
        expect(substr_count($html, e(ContestMode::Classic->blurb(10))))->toBe(2);
    });

    it('reads a room\'s pitch off state it already holds', function () {
        /*
         * The whole cost claim for thirteen pitches: an enum read of the
         * `flavor` column the inventory already loaded, sized by the count
         * the shelf already passes. A shelf asking the database once per
         * row for its own sentence is exactly the shape the store was
         * built to avoid.
         *
         * Pinned on the expression rather than on a render: a render's
         * total moves with the fixture, and a number that moves for
         * reasons unrelated to the change is a number nobody can read.
         */
        $room = Group::factory()->lobby()->create(['flavor' => LobbyFlavor::TwoMinuteDrill->value]);

        DB::enableQueryLog();

        $pitch = $room->flavorEnum()?->blurb(5);

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        // Sized from the CONTEST's slate, never the mode's default.
        expect($pitch)->toBe('The flash card: 5 games, in and out. 10 points a game.')
            ->and($queries)->toBe(0);
    });

    it('pitches an evergreen table nothing, because it has no Saturday to pitch', function () {
        /*
         * The pitch is a room's one-Saturday personality. An always-open
         * table has no week, no flavor and no card, so its row carries no
         * pitch at all rather than a sentence the data cannot back — one
         * occurrence of the mode blurb on the whole screen, and it is the
         * rules card's.
         */
        Group::factory()->lobby()->create(['name' => 'The Big Lobby']);

        $html = Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('Always open')
            ->assertSee('The Big Lobby')
            ->assertDontSee('The flash card')
            ->html();

        expect(substr_count($html, e(ContestMode::Classic->blurb(10))))->toBe(1);
    });

    it('says the last seats in weight, and only to somebody who could take one', function () {
        /*
         * Break-it-back, both directions. Rows repeat, and the amber
         * budget is one per viewport — thirteen amber rows is a store
         * shouting at itself, and dark mode un-brands color anyway. So
         * the scarcity signal is WEIGHT, and a roomy room keeps the plain
         * seat count it always had.
         */
        [, $week] = lobbyScreenWeek();
        $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        $room->update(['member_cap' => 1]);

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('1 seat left')
            ->assertSeeHtml('font-semibold text-zinc-900 dark:text-zinc-100')
            ->assertDontSee('0 of 1 seats');

        // A roomy one says the plain count, with no weight on it.
        $room->update(['member_cap' => 20]);

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('0 of 20 seats')
            ->assertDontSee('seats left')
            ->assertDontSee('seat left');
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
            ->assertSee(Voice::line('lobby.shelf.also', ['list' => 'Triple Option · Woodshed'], for: $admin))
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

    it('spells every mode\'s rules, stakes included — folded away until asked', function () {
        /*
         * Sixty-five lines of foot matter stood between a shopper and the
         * bottom of every visit, on a screen whose job is to seat them in
         * a room. The rules are FOLDED now, not cut: every string is
         * still in the DOM (x-show, never removed), which is what lets a
         * test drive the reactive end state — and what the automated tab,
         * with no rendering frames, could not otherwise hold.
         */
        $html = Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee("How it's played", escape: false)
            ->assertSee('every one worth 10 points')
            ->assertSee('Tier 1 pays 9')
            ->assertSee('8, 6 and 4')
            ->assertSee('+6 right, −4 wrong')
            ->assertSee('101')
            ->assertSee('no pushes, ever')
            // The heading is a disclosure BUTTON now, not a subheading —
            // which is what tells the new fold apart from the three mode
            // cards that always had one.
            ->assertSeeHtml('<p class="font-semibold">How it\'s played</p>')
            ->html();

        /*
         * Collapsed by default, server-rendered as collapsed, and honest
         * before Alpine boots. FOUR closed disclosures now: the three
         * mode cards, which always had theirs, plus the fold around them.
         */
        expect(substr_count($html, 'aria-expanded="false"'))->toBe(4);
    });

    it('hands the reader a codeless invite link, and no code to read aloud', function () {
        /*
         * The share affordance lives HERE because the lobby is the walk-on
         * destination and the only link that never goes stale: a room code
         * would rot inside a week and a private group cannot field a thin
         * Saturday. Codeless means there is nothing to read across a room,
         * so the group screen's spoken-word fallback has no counterpart.
         */
        $viewer = pickemAdmin();
        $viewer->update(['handle' => 'marcus', 'first_name' => 'Taylor']);

        Livewire::actingAs($viewer)->test('lobby')
            ->assertSee('Invite a friend')
            ->assertSee(Voice::line('join.app.hint', for: $viewer))
            ->assertSeeHtml('/join?by=marcus')
            // The share sheet composes from the SHARER — their own name is
            // what makes "somebody is inviting you" credible on a phone.
            ->assertSeeHtml('navigator.share')
            ->assertSee('Taylor')
            ->assertDontSee('Or read them the code');
    });

    it('still offers a working link to a member who never claimed a handle', function () {
        // A handle is optional, so an uncredited invite is a real shape.
        // It opens the same pitch; it just cannot say who sent it — which
        // beats a `?by=` with nothing after it.
        $viewer = pickemAdmin();
        $viewer->update(['handle' => null]);

        Livewire::actingAs($viewer)->test('lobby')
            ->assertSee('Invite a friend')
            // The link is printed without its scheme, the way group's
            // invite card prints it — match the same half.
            ->assertSeeHtml(Str::after(route('pickem.join'), '://'))
            ->assertDontSeeHtml('by=');
    });

    /*
     * THE ROOM-TYPE SUBTABS. A FILTER with an "All" default, which is the
     * whole decision: nothing is hidden until a reader asks for it, so the
     * stacked-shelf tests above keep passing untouched and the tabs are a
     * lens rather than a split.
     */
    it('defaults to All, and shows one shelf when a tab is picked', function () {
        [, $week] = lobbyScreenWeek();

        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSet('view', 'all')
            // The tab row itself, in the shelves' own case order.
            ->assertSeeInOrder(['All', 'House', 'Quick', 'Spotlight', 'Conference'])
            ->assertSee('House rooms')
            ->assertSee('Quick hits')
            ->set('view', 'quick_hits')
            ->assertSee('Quick hits')
            ->assertSee('Two-Minute Drill')
            ->assertDontSee('House rooms')
            ->assertDontSee('Hail Mary');
    });

    it('normalizes a nonsense tab back to All, from the URL and from the wire', function () {
        /*
         * BOTH halves: #[Url] hydrates without firing the update hook, so
         * a bookmarked ?view=nonsense reaches the filter through mount()
         * alone. Neither half may error — an unknown shelf is the whole
         * store.
         */
        [, $week] = lobbyScreenWeek();
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

        Livewire::actingAs(pickemAdmin())
            ->withQueryParams(['view' => 'nonsense'])
            ->test('lobby')
            ->assertSet('view', 'all')
            ->assertSee('House rooms');

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->set('view', 'garbage')
            ->assertSet('view', 'all')
            ->assertSee('House rooms');
    });

    it('says where the rooms went on a shelf this Saturday could not stock', function () {
        // The tab set is fixed, so a shelf with no stock is still a tab.
        // The line has to carry the way back out, in every register.
        [, $week] = lobbyScreenWeek();
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        $viewer = pickemAdmin();

        Livewire::actingAs($viewer)->test('lobby')
            ->set('view', 'conference')
            ->assertSee(Voice::line('lobby.shelf.empty', for: $viewer))
            ->assertDontSee('House rooms');
    });

    it('keeps the evergreens on All — an always-open table sits on no shelf', function () {
        [, $week] = lobbyScreenWeek();
        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
        Group::factory()->lobby()->create(['name' => 'The Big Lobby']);

        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('Always open')
            ->assertSee('The Big Lobby')
            ->set('view', 'house')
            ->assertDontSee('Always open')
            ->assertDontSee('The Big Lobby')
            // The unconditioned chrome stays put on every tab.
            ->assertSee('Invite a friend')
            ->assertSee('Want a season-long group?')
            ->assertSee("How it's played", escape: false);
    });

    it('lets a stale filter go inert when the Saturday has no shelves at all', function () {
        /*
         * A bookmarked ?view=house on a Saturday with nothing transient
         * open: there is no tab row to undo it with, so a filter left in
         * force would render a store with nothing in it and no way back.
         * The evergreen table is not a Saturday product and belongs to no
         * shelf, so it is what is honestly there.
         */
        Group::factory()->lobby()->create(['name' => 'The Big Lobby']);

        Livewire::actingAs(pickemAdmin())
            ->withQueryParams(['view' => 'house'])
            ->test('lobby')
            ->assertSee('Always open')
            ->assertSee('The Big Lobby')
            ->assertDontSeeHtml('wire:key="lobby-view-all"');
    });

    it('offers no tabs over an empty store', function () {
        // Nothing stocked is not a filtering problem, and five tabs over
        // one callout is chrome selling nothing.
        Livewire::actingAs(pickemAdmin())->test('lobby')
            ->assertSee('No open rooms right now')
            ->assertDontSeeHtml('wire:key="lobby-view-all"');
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
