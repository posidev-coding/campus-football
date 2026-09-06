<?php

use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\Team;
use App\Models\User;
use App\Models\Week;
use App\Support\GameRanks;
use App\Support\Voice;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * THE CLUBHOUSE — the design reference for the pick'em front-end rebuild.
 * These tests hold the screen's states (draft wait, published surface,
 * locked rows, results, season ledger) and the two disciplines the rebuild
 * exists for: the picked side carries the team's ACTUAL palette, and the
 * query count stays flat however big the slate gets.
 */

it('renders the clubhouse: hero, plate tabs, tiers and progress', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    expect($slate->fresh()->status)->toBe(Slate::PUBLISHED);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSee($group->name)
        ->assertSee('Triple Option')
        ->assertSee('Tier 1')
        ->assertSee('Tier 3')
        ->assertSee('0 of 15')
        ->assertSee('to kickoff');
});

it('fills a tapped side with that team\'s computed palette', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $slateGame = $slate->games()->first();
    // Tennessee orange: the palette must arrive as CUSTOM PROPERTIES the
    // team-accent utility reads, not as a hardcoded blue.
    $slateGame->game->homeTeam->update(['color' => 'FF8200', 'alt_color' => 'FFFFFF', 'header_style' => null]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->call('pick', $slateGame->id, $slateGame->game->home_team_id)
        ->assertSee('--team-accent: #ff8200', escape: false)
        ->assertSee('team-accent team-keyline', escape: false);

    expect(Pick::query()
        ->where('user_id', $commissioner->id)
        ->where('slate_game_id', $slateGame->id)
        ->value('picked_team_id')
    )->toBe($slateGame->game->home_team_id);
});

it('locks a kicked game — the row says so and the tap writes nothing', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $slateGame = $slate->games()->first();
    $slateGame->game->update(['kickoff_at' => now()->subHour()]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        // "Kicked off", not "Locked" — on screen the word Lock belongs to
        // the Woodshed wager alone.
        ->assertSee('Kicked off')
        ->assertDontSee('· Locked')
        ->assertSee('No pick')
        ->call('pick', $slateGame->id, $slateGame->game->home_team_id);

    expect(Pick::query()->where('slate_game_id', $slateGame->id)->exists())->toBeFalse();
});

it('shows the commissioner a build prompt and a member the waiting room on a draft week', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    pickemDraftSlate($contest);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSee('Build the slate');

    $member = User::factory()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    Livewire::actingAs($member)->test('group', ['group' => $group])
        ->assertSee(Voice::line('group.slate.waiting'))
        ->assertDontSee('Build the slate');
});

it('takes the build door away on a Saturday that cannot seat the mode', function () {
    /*
     * WEEK 0. The split week's first card holds seven lined games, which
     * fills neither Shotgun's ten nor the Woodshed's fifteen — and a
     * group never downsizes the way a house room does, because its mode
     * is a season-long promise its members chose.
     *
     * A "Build the slate" button over that is a door into a wizard whose
     * publish can only refuse, so the clubhouse states both numbers and
     * the Saturday the ritual reopens on instead.
     */
    $this->travelTo('2026-08-26 12:00:00');

    [, $week] = splitPickemWeek();

    foreach (Game::query()->whereDate('kickoff_at', '2026-08-29')->get() as $game) {
        pickemOdd($game);
    }

    [$commissioner, $group] = pickemContest(ContestMode::Classic);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSee('Not enough games this Saturday.')
        // Literal template text, so the apostrophe is never escaped.
        ->assertSee("Shotgun needs 10 games and this Saturday's card has 7.", escape: false)
        ->assertSee('The next slate can go up Saturday, Sep 5.')
        ->assertSee(Voice::line('group.slate.thin', for: $commissioner))
        ->assertDontSee('Build the slate');
});

it('keeps the build door on a Saturday the mode fits, thin week or not', function () {
    // The same fixture one week on: 9/5 carries twelve lined games, so
    // Shotgun's ten fits and nothing is taken away. The gate has to be
    // the SATURDAY's answer, not a launch-window switch.
    $this->travelTo('2026-09-02 12:00:00');

    [, $week] = splitPickemWeek();

    foreach (Game::query()->whereDate('kickoff_at', '2026-09-05')->get() as $game) {
        pickemOdd($game);
    }

    [$commissioner, $group] = pickemContest(ContestMode::Classic);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSee('Build the slate')
        ->assertDontSee('Not enough games this Saturday');
});

it('keeps a practice week off the season ledger while still paying its week', function () {
    /*
     * What "practice" has to MEAN, end to end. The exhibition flag has
     * existed since the Saturday anchor landed and nothing read it: a
     * rehearsal week settled straight into the season table, so the
     * launch's practice Saturdays would have decided real standings.
     *
     * The week itself is untouched — it grades, it crowns its winner and
     * it pays XP. It just never reaches the ledger.
     */
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $slate->update(['status' => Slate::SETTLED, 'exhibition' => true, 'settled_at' => now()]);
    SlateEntry::factory()->create([
        'slate_id' => $slate->id,
        'user_id' => $commissioner->id,
        'final_points' => 90,
        'won' => true,
    ]);

    // A fresh component each read: computed properties memoize, and the
    // second answer is the one that matters here.
    $ledger = fn () => Livewire::actingAs($commissioner)
        ->test('group', ['group' => $group])
        ->instance();

    // cells => [wins, season points, this week]
    expect($ledger()->seasonStandings->first()['cells'])->toBe([0, 0, 90])
        ->and($ledger()->seasonHasHistory)->toBeFalse();

    // The same week, counted: the ledger takes both.
    $slate->update(['exhibition' => false]);

    expect($ledger()->seasonStandings->first()['cells'])->toBe([1, 90, 90])
        ->and($ledger()->seasonHasHistory)->toBeTrue();
});

it('renders result marks and the week standings once games grade', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    /*
     * Saturday evening, after the card. This used to move every kickoff to
     * `now()->subDay()` and leave `kickoff_day` saying 'Sat' — so on a
     * Saturday the games landed on FRIDAY wearing a Saturday's weekday,
     * and the test's answer depended on the hour it ran at (before noon ET
     * the hour check excluded them and it passed; after noon it did not).
     * The games are already pinned to this Saturday; only the clock has to
     * move for them to be played.
     */
    $this->travelTo('2026-09-05 23:30:00');

    foreach ($slate->games()->with('game')->get() as $slateGame) {
        $slateGame->game->update([
            'completed' => true,
            'status' => 'post',
            'home_score' => 31,
            'away_score' => 17,
        ]);
    }

    $games = $slate->games()->with('game')->get();
    Pick::factory()->won()->create([
        'slate_game_id' => $games[0]->id,
        'user_id' => $commissioner->id,
        'picked_team_id' => $games[0]->game->home_team_id,
    ]);
    Pick::factory()->lost()->create([
        'slate_game_id' => $games[1]->id,
        'user_id' => $member->id,
        'picked_team_id' => $games[1]->game->away_team_id,
    ]);
    SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $commissioner->id]);
    SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $member->id]);
    $slate->update(['status' => Slate::PRELIM]);

    /*
     * The play tab answers picks; the standings moved to their own tab.
     *
     * That decision was about the VERTICAL stack — "the first pickable card
     * is the first thing this tab says" — so what it forbids is a standings
     * table pushing the cards down the page. It was pinned as
     * `assertDontSee('This week')`, which also forbade the desktop sidecar
     * that now carries the running week beside the cards at `lg`. The
     * guarantee is re-stated here rather than dropped: the table may exist
     * on this tab only inside the `lg`-gated column, and the pick surface
     * still comes first in the flow.
     */
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'slate')
        ->assertSee('Preliminary')
        ->assertSee('+1')
        ->assertSee('No pick')
        // Present, but desktop-only and in the sidecar — never in the stack.
        ->assertSee('hidden flex-col gap-4 lg:flex', escape: false)
        ->assertSeeInOrder(['Preliminary', 'hidden flex-col gap-4 lg:flex', 'This week'], escape: false)
        ->set('view', 'standings')
        ->assertSee('This week')
        // The winner leads the loser. NAMES, because this is a private group
        // and all three of the clubhouse's identity surfaces print names
        // there — the ordering is what this line is for, not the identity.
        ->assertSeeInOrder([$commissioner->name, $member->name]);
});

it('opens no sidecar column on a slate that has not kicked off', function () {
    /*
     * The blank-column guard, and the reason the sidecar is conditional at
     * all. Before kickoff everybody is on zero and no picks are revealed,
     * so there is no table to put beside the cards — and a reserved-but-
     * empty 320px track is precisely the bug App\Support\Rail's docblock
     * was written to name. Most of a pick'em week is spent in this state.
     *
     * It also pins the query trade: `surfaceStatus` is tested before
     * `weekStandings`, so a slate nobody has played costs exactly what it
     * cost before the sidecar existed.
     */
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'slate')
        ->assertDontSee('lg:grid-cols-[minmax(0,1fr)_20rem]', escape: false)
        ->assertDontSee('This week');
});

it('opens to Standings once the entry is in and the card is playing', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    // A complete entry: every game picked, the tiebreaker answered.
    $games = $slate->games()->with('game')->get();

    foreach ($games as $slateGame) {
        Pick::factory()->create([
            'slate_game_id' => $slateGame->id,
            'user_id' => $commissioner->id,
            'picked_team_id' => $slateGame->game->home_team_id,
        ]);
    }
    SlateEntry::factory()->create([
        'slate_id' => $slate->id,
        'user_id' => $commissioner->id,
        'tiebreaker_total' => 48,
    ]);

    // Not playing yet: a bare visit still opens to the pick surface.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'slate');

    // Kickoff: the card is live, the entry is in — the answers tab wins.
    $games->first()->game->update(['kickoff_at' => now()->subHour()]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'standings');

    // An explicit address is somebody's stated intent and always wins.
    Livewire::withQueryParams(['view' => 'slate'])
        ->actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'slate');

    // An INCOMPLETE entry keeps the pick surface up even mid-game.
    Pick::query()->where('user_id', $commissioner->id)->latest('id')->first()->delete();

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'slate');
});

it('normalizes the three-tab era\'s addresses onto the one strip', function () {
    [$commissioner, $group] = pickemContest(ContestMode::Classic);

    // Mount half: bookmarked ?view= values. `members` is a real stop
    // again since the strip went four-up, so only `season` still folds.
    Livewire::withQueryParams(['view' => 'members'])
        ->actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'members');

    Livewire::withQueryParams(['view' => 'nonsense'])
        ->actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'slate');

    // Hook half: an in-session set.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'season')
        ->assertSet('view', 'standings')
        ->set('view', 'garbage')
        ->assertSet('view', 'slate');
});

it('navigates the clubhouse from ONE strip of five stops', function () {
    /*
     * There was briefly a plate of Slate|Standings with a gutter of
     * Standings|Members|Invite beneath it — three rows of navigation
     * once the area nav is counted, saying "Standings" on two of them.
     * One strip, and the word appears once.
     */
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);

    $html = Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertSee('Slate')
        ->assertSee('Standings')
        ->assertSee('Members')
        ->assertSee('Invite')
        // The viewer's own line, and the standings' own content: nothing
        // has been played, so every figure is a dash, never a zero.
        ->assertSee('Wk place')
        ->assertSee('—')
        ->assertSee('Triple Option')
        ->assertSee('Talk')
        ->html();

    // Exactly ONE navigation strip: five buttons, one set of keys. A
    // second strip is the regression this test exists to catch, and it
    // would pass every assertion above.
    expect(substr_count($html, 'wire:key="group-tab-'))->toBe(5)
        ->and($html)->not->toContain('group-pane-')
        // The switcher in the hero is not a strip: its rows are keyed
        // `switch-`, and there is exactly one of it.
        ->and(substr_count($html, 'data-group-switcher'))->toBe(1);
});

/*
 * THE NAME IS THE SWITCHER. The hero's title is the same group switcher
 * My Picks carries above its fork, so a reader inside one seat reaches
 * any other in one tap instead of back out through the overview.
 */
it('wears the group switcher as its title, and lists the reader\'s other seats', function () {
    [$commissioner, $a] = pickemContest(ContestMode::Classic);
    $a->update(['name' => 'Rocky Top Rejects']);

    $b = Group::factory()->create(['name' => 'The Back Porch']);
    GroupMember::factory()->commissioner()->create(['group_id' => $b->id, 'user_id' => $commissioner->id]);
    Contest::factory()->create(['group_id' => $b->id]);

    $html = Livewire::actingAs($commissioner)->test('group', ['group' => $a->fresh()])->html();

    // The visible name is the trigger, clamped rather than clipped; the
    // heading survives sr-only; the old truncating h1 is gone.
    $trigger = (string) str($html)->after('data-group-switcher')->before('<ui-menu');

    expect($trigger)->toContain('Rocky Top Rejects')
        ->toContain('line-clamp-2')
        ->and($html)->toContain('<h1 class="sr-only">Rocky Top Rejects</h1>')
        ->not->toContain('<h1 class="min-w-0 truncate');

    $menu = (string) str($html)->after('<ui-menu')->before('</ui-menu>');

    expect($menu)->toContain('All my groups and rooms')
        ->toContain(route('pickem.home'))
        ->toContain('My Groups')
        ->toContain('The Back Porch')
        ->toContain(route('pickem.group', $b))
        ->toContain('Browse the Lobby')
        ->toContain(route('pickem.lobby'));

    // The page you are ON is the bold row; the other seat is not.
    $rowOf = function (string $key) use ($menu): string {
        $at = strpos($menu, 'wire:key="'.$key.'"');
        $start = strrpos(substr($menu, 0, $at), '<');

        return substr($menu, $start, strpos($menu, '>', $at) - $start + 1);
    };

    expect($rowOf('switch-g-'.$a->id))->toContain('font-semibold')
        ->and($rowOf('switch-g-'.$b->id))->not->toContain('font-semibold')
        ->and($rowOf('switch-all'))->not->toContain('font-semibold');
});

it('keeps a previewed lobby\'s name on the trigger without a seat in it', function () {
    // A lobby is readable from outside. The reader holds no seat there,
    // so it is in none of the switcher's lists — and the trigger must
    // still say where they are, never "My groups and rooms".
    $outsider = pickemAdmin();
    $lobby = Group::factory()->lobby()->create(['name' => 'Walk-Ons Welcome']);
    Contest::factory()->create(['group_id' => $lobby->id]);

    $html = Livewire::actingAs($outsider)->test('group', ['group' => $lobby])->html();
    $trigger = (string) str($html)->after('data-group-switcher')->before('<ui-menu');

    expect($trigger)->toContain('Walk-Ons Welcome')
        ->not->toContain('My groups and rooms')
        ->and($html)->toContain('All my groups and rooms')
        ->toContain('Browse the Lobby')
        ->not->toContain('My Groups');
});

it('keeps a played room\'s own name on its clubhouse, outside the week it is not in', function () {
    $this->travelTo('2026-09-02 12:00:00');

    $viewer = pickemAdmin();
    [$season, $week] = pickemSeasonWeek();
    $gone = Week::factory()->create(['season_id' => $season->id, 'number' => 0]);

    $room = Group::factory()->room($gone->id)->create(['name' => 'The 8/29 Room']);
    GroupMember::factory()->create(['group_id' => $room->id, 'user_id' => $viewer->id]);
    Contest::factory()->create(['group_id' => $room->id]);

    $html = Livewire::actingAs($viewer->fresh())->test('group', ['group' => $room])->html();
    $trigger = (string) str($html)->after('data-group-switcher')->before('<ui-menu');
    $menu = (string) str($html)->after('<ui-menu')->before('</ui-menu>');

    expect($trigger)->toContain('The 8/29 Room')
        // Spliced in as a bare row right after the overview, ahead of
        // any Contests heading — a played room is not this Saturday's.
        ->and(strpos($menu, 'The 8/29 Room'))->toBeGreaterThan(strpos($menu, 'All my groups and rooms'))
        ->and(strpos($menu, 'The 8/29 Room'))->toBeLessThan(strpos($menu, 'Browse the Lobby'))
        ->and(substr_count($menu, 'wire:key="switch-g-'.$room->id.'"'))->toBe(1);

    if (($contests = strpos($menu, 'Contests')) !== false) {
        expect(strpos($menu, 'The 8/29 Room'))->toBeLessThan($contests);
    }
});

it('keeps the hero out of the invite business, now that a stop owns it', function () {
    /*
     * The hero carried a copy-link button back when the invite was a
     * disclosure buried in the standings and worth a shortcut. It now
     * has a stop of its own carrying the link, the code, a QR and three
     * ready-to-send messages, so the hero button was a second, worse
     * door to the same place.
     *
     * Guarded on the CLIPBOARD rather than the word "Invite", which is
     * still on the screen as a tab label: the button and the tab read
     * the same to assertSee, and only the copy handler tells them apart.
     */
    [$commissioner, $group] = pickemContest(ContestMode::Tiered);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'slate')
        ->assertSee('Invite')
        ->assertDontSee('cfbClipboard', escape: false);

    // And the real door still works.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'invite')
        ->assertSee('cfbClipboard', escape: false)
        ->assertSee($group->code);
});

it('keeps the invite off the standings and on a stop of its own', function () {
    // It carries a link, a code, a QR and three ready-to-send messages
    // now — as a disclosure on top of the standings it buried them.
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertDontSee('Or read them the code')
        ->set('view', 'invite')
        ->assertSee($group->code)
        ->assertSee('Or read them the code');
});

it('puts the roster and its management on the Members stop', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'members')
        ->assertSee('Commissioner')
        ->assertSee('Leave group');
});

it('gives a public room three stops, never an invite one', function () {
    /*
     * Rooms are joined from the lobby, never by invitation — so the
     * strip must not offer a stop the screen refuses to draw, and an
     * address asking for one lands on the standings rather than an
     * empty box.
     */
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $group->update(['kind' => Group::KIND_LOBBY]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertDontSee('Invite')
        ->set('view', 'invite')
        ->assertSet('view', 'standings')
        ->assertDontSee('Or read them the code');
});

/**
 * The you-strip's own element, cut out of the page.
 *
 * The strip and the standings table below it both print an identity, so an
 * assertion over the whole document cannot tell which one it read.
 */
function youStripOf(string $html, int $chars = 400): string
{
    $at = strpos($html, 'data-you-strip');

    expect($at)->not->toBeFalse('the you-strip did not render');

    $slice = substr($html, $at, $chars);

    /*
     * ...and never as far as the table under it, whatever the window. Both
     * print an identity and both print a place, so a slice that reached the
     * table would answer for the wrong one — which is the bug this helper
     * exists to stop, not one it may reintroduce by growing.
     */
    $table = strpos($slice, '<table');

    return $table === false ? $slice : substr($slice, 0, $table);
}

it('prints real names in a private group and handles in a public room', function () {
    /*
     * The seam is the KIND of room, not a preference. A private group is
     * people who invited each other by text; a public room is strangers
     * who walked in off the lobby, and their legal names are not the
     * room's to publish.
     *
     * Asserted BOTH ways round on the same fixture, because a test that
     * only checks the name appears passes just as happily when the room
     * prints it too.
     */
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create(['first_name' => 'Dale', 'last_name' => 'Trickett', 'handle' => 'shedhand']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    [, $week] = pickemSeasonWeek();
    $slate = Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id,
        'status' => Slate::SETTLED, 'settled_at' => now(),
    ]);
    SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $member->id, 'final_points' => 9, 'won' => true]);

    // Private: the name, and not the handle.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertSee('Dale Trickett')
        ->assertDontSee('@shedhand');

    // The same people, in public: the handle, and not the name.
    $group->update(['kind' => Group::KIND_LOBBY]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertSee('@shedhand')
        ->assertDontSee('Dale Trickett');
});

it('says the same thing in the you-strip that the table two rows below says', function () {
    /*
     * PR #91 gave the standings table real names inside a private group and
     * the strip above it did not come along, so the clubhouse read "@taylorc"
     * in the strip and "Taylor Cox" in the table — one screen, one person,
     * two identities.
     *
     * Scoped to the strip's own element, because the TABLE prints an identity
     * too: a bare assertSee would pass on the table while the strip went on
     * disagreeing with it, which is the failure this test exists to catch.
     *
     * Asserted both ways round on one fixture, the way #91 asserted its half.
     */
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $commissioner->update(['first_name' => 'Taylor', 'last_name' => 'Cox', 'handle' => 'taylorc']);

    [, $week] = pickemSeasonWeek();
    $slate = Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id,
        'status' => Slate::SETTLED, 'settled_at' => now(),
    ]);
    SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $commissioner->id, 'final_points' => 9]);

    $strip = fn (Group $for) => youStripOf(
        Livewire::actingAs($commissioner->fresh())->test('group', ['group' => $for])->set('view', 'standings')->html()
    );

    // Private: the name, matching the table.
    expect($strip($group))->toContain('Taylor Cox')
        ->and($strip($group))->not->toContain('@taylorc');

    // The same person, in public: the handle, matching the table there.
    $group->update(['kind' => Group::KIND_LOBBY]);

    expect($strip($group->fresh()))->toContain('@taylorc')
        ->and($strip($group->fresh()))->not->toContain('Taylor Cox');
});

it('says it in the picks grid too, which is the third identity on the screen', function () {
    /*
     * The clubhouse prints a person three times: the strip, the standings
     * table and the picks grid. Two of them agreeing is still a screen
     * disagreeing with itself — the grid went on saying "@taylorc" underneath
     * a strip and a table both saying the name.
     *
     * Asserted on the GRID's own rows, because the other two print an
     * identity on the same page. Fixture shape copied from 'reveals the picks
     * grid per game': the grid needs two entrants and a kicked game.
     */
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $commissioner->update(['first_name' => 'Taylor', 'last_name' => 'Cox', 'handle' => 'taylorc']);
    $member = User::factory()->create(['admin' => true, 'handle' => 'gridwatcher']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    $games = $slate->games()->with('game')->orderBy('position')->get();

    foreach ([$commissioner, $member] as $player) {
        foreach ($games->take(2) as $slateGame) {
            Pick::factory()->create([
                'slate_game_id' => $slateGame->id,
                'user_id' => $player->id,
                'picked_team_id' => $slateGame->game->home_team_id,
            ]);
        }

        SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $player->id]);
    }

    $games[0]->game->update(['kickoff_at' => now()->subHour()]);

    /*
     * Read off the grid's own rows rather than the page. The strip and the
     * standings table print an identity too, so a page-wide assertion could
     * not say which one it read — and the grid sits behind a view gate the
     * other two do not share.
     */
    $gridNames = fn (Group $for) => collect(
        Livewire::actingAs($commissioner->fresh())->test('group', ['group' => $for])->instance()->picksGrid['rows']
    )->pluck('name')->all();

    expect($gridNames($group))->toContain('Taylor Cox')
        ->and($gridNames($group))->not->toContain('@taylorc');

    $group->update(['kind' => Group::KIND_LOBBY]);

    expect($gridNames($group->fresh()))->toContain('@taylorc')
        ->and($gridNames($group->fresh()))->not->toContain('Taylor Cox');
});

it('falls back the way the table does when half the identity is missing', function () {
    /*
     * Both directions of the seam, so neither half can block the other: a
     * private group with no name to print falls back to the handle, and a
     * public room with no handle yet falls back to the name. The table's own
     * fallbacks, character for character.
     */
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);

    [, $week] = pickemSeasonWeek();
    $slate = Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id,
        'status' => Slate::SETTLED, 'settled_at' => now(),
    ]);
    SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $commissioner->id, 'final_points' => 9]);

    $strip = fn (Group $for) => youStripOf(
        Livewire::actingAs($commissioner->fresh())->test('group', ['group' => $for])->set('view', 'standings')->html()
    );

    // Private, no name: the handle rather than an empty line.
    $commissioner->update(['first_name' => '', 'last_name' => '', 'handle' => 'nameless']);
    expect($strip($group))->toContain('@nameless');

    // Public, no handle: the name rather than an empty line.
    $commissioner->update(['first_name' => 'Handle', 'last_name' => 'Less', 'handle' => null]);
    $group->update(['kind' => Group::KIND_LOBBY]);
    expect($strip($group->fresh()))->toContain('Handle Less');
});

it('keeps a public room\'s roster on handles too, not just its tables', function () {
    [$commissioner, $group] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create(['first_name' => 'Dale', 'last_name' => 'Trickett', 'handle' => 'shedhand']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);
    $group->update(['kind' => Group::KIND_LOBBY]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'members')
        ->assertSee('@shedhand')
        ->assertDontSee('Dale Trickett');
});

it('gives a room four stops and a group five, and sends ?view=invite back', function () {
    /*
     * The strip and the content must not disagree about which stops
     * exist: a room has no invite to advertise, so the tab is absent AND
     * the address is refused. Talk is the last stop of both for a member.
     */
    [$commissioner, $group] = pickemContest(ContestMode::Classic);

    $html = Livewire::withQueryParams(['view' => 'invite'])
        ->actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'invite')
        ->html();

    expect(substr_count($html, 'wire:key="group-tab-'))->toBe(5)
        ->and(strpos($html, 'wire:key="group-tab-talk"'))->toBeGreaterThan(strpos($html, 'wire:key="group-tab-invite"'));

    $group->update(['kind' => Group::KIND_LOBBY]);

    $html = Livewire::withQueryParams(['view' => 'invite'])
        ->actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'standings')
        ->html();

    expect(substr_count($html, 'wire:key="group-tab-'))->toBe(4)
        ->and($html)->not->toContain('wire:key="group-tab-invite"')
        ->and(strpos($html, 'wire:key="group-tab-talk"'))->toBeGreaterThan(strpos($html, 'wire:key="group-tab-members"'));
});

it('polls the Standings tab only while the card is live', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    // Upcoming: nothing to poll, no poll.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertDontSee('wire:poll.30s.visible', escape: false);

    $slate->games()->with('game')->get()->first()->game->update(['kickoff_at' => now()->subHour()]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertSee('wire:poll.30s.visible', escape: false);
});

it('aggregates settled weeks on the Standings tab, wins before points', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    [$season, $week] = pickemSeasonWeek();
    $slate = Slate::factory()->create([
        'contest_id' => $contest->id,
        'week_id' => $week->id,
        'status' => Slate::SETTLED,
        'settled_at' => now(),
    ]);
    SlateEntry::factory()->create([
        'slate_id' => $slate->id, 'user_id' => $member->id,
        'final_points' => 7, 'won' => true,
    ]);
    SlateEntry::factory()->create([
        'slate_id' => $slate->id, 'user_id' => $commissioner->id,
        'final_points' => 3, 'won' => false,
    ]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertSee('Wins')
        // The week's winner outranks the commissioner's seat order — and
        // a private group prints NAMES: these are people who invited each
        // other, where a handle is the worse answer.
        ->assertSeeInOrder([$member->name, $commissioner->name]);
});

it('previews the surface read-only for a lobby outsider', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $group->update(['kind' => Group::KIND_LOBBY]);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $outsider = pickemAdmin();

    Livewire::actingAs($outsider)->test('group', ['group' => $group])
        ->assertSee('Join this lobby')
        ->assertDontSee('optimistic(', escape: false)
        ->assertDontSee('No pick')
        // No seat, no Talk stop.
        ->assertDontSeeHtml('wire:key="group-tab-talk"');
});

it('301s the old nested URL to the clubhouse', function () {
    [$commissioner, $group] = pickemContest(ContestMode::Classic);

    $this->actingAs($commissioner)
        ->get('/picks/groups/'.$group->id)
        ->assertMovedPermanently()
        ->assertRedirect(route('pickem.group', $group));
});

it('keeps the query count flat however big the slate gets', function () {
    // One commissioner, two rooms: a 10-game Shotgun and a 15-game Triple
    // Option. Every concern is one query across all rows, so five more
    // games may not cost a single extra read.
    [$commissioner, $groupA, $contestA] = pickemContest(ContestMode::Classic);
    app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contestA));

    $groupB = Group::factory()->create();
    GroupMember::factory()->commissioner()->create(['group_id' => $groupB->id, 'user_id' => $commissioner->id]);
    $contestB = Contest::factory()->tiered()->create(['group_id' => $groupB->id]);
    app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contestB));

    $queries = function (Group $group) use ($commissioner): int {
        GameRanks::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($commissioner)->test('group', ['group' => $group]);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // First render pays the SHARED cache warms (calendar weeks, poll
    // releases) that every screen amortizes; discard it so the comparison
    // is about slate size, not cache temperature.
    $queries($groupA);

    expect($queries($groupB))->toBe($queries($groupA));
});

it('reveals the picks grid per game, and never before kickoff', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create(['admin' => true, 'handle' => 'gridwatcher']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    $games = $slate->games()->with('game')->orderBy('position')->get();

    foreach ([$commissioner, $member] as $player) {
        foreach ($games->take(2) as $slateGame) {
            Pick::factory()->create([
                'slate_game_id' => $slateGame->id,
                'user_id' => $player->id,
                'picked_team_id' => $slateGame->game->home_team_id,
            ]);
        }

        SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $player->id]);
    }

    // Nothing kicked: an upcoming card renders NO grid — nothing to reveal.
    expect(Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->instance()->picksGrid)->toBeNull();

    // The first game kicks; the second stays put.
    $games[0]->game->update(['kickoff_at' => now()->subHour()]);

    $grid = Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->instance()->picksGrid;

    $kicked = array_search($games[0]->id, array_column($grid['columns'], 'key'), true);
    $waiting = array_search($games[1]->id, array_column($grid['columns'], 'key'), true);

    // The viewer's row leads; the kicked column shows the side; the
    // unkicked column stays HIDDEN even though a pick exists on it —
    // the leak test.
    expect($grid['rows'][0]['viewer'])->toBeTrue()
        ->and($grid['rows'][0]['cells'][$kicked]['state'])->toBe('pick')
        ->and($grid['rows'][0]['cells'][$kicked]['abbr'])->not->toBeNull()
        ->and($grid['rows'][0]['cells'][$waiting]['state'])->toBe('hidden')
        ->and($grid['rows'][0]['cells'][$waiting]['abbr'])->toBeNull();

    // And the Standings tab carries it, reveal rule said out loud.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertSee('Picks show at kickoff.')
        // The member's row is present; a private group names them.
        ->assertSee($member->name)
        ->assertSeeHtml('data-cell="hidden"')
        ->assertSeeHtml('data-cell="pick"');
});

it('prints the picked school as a mark, dulls a missed call, and keeps the abbreviations on the header', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);

    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    $games = $slate->games()->with('game')->orderBy('position')->get();
    [$landed, $missed] = [$games[0], $games[1]];

    /*
     * TeamFactory mints no logo and a random abbreviation off a faker
     * city, so both are pinned here: an unpinned mark renders the
     * placeholder puck instead of the thing under test, and an unpinned
     * abbreviation is one draw away from colliding with the page.
     */
    foreach ([[$landed, 'landed'], [$missed, 'missed']] as [$slateGame, $tag]) {
        $slateGame->game->update(['kickoff_at' => now()->subHour()]);

        Team::whereKey($slateGame->game->home_team_id)->update([
            'logo' => "https://cdn.example.com/{$tag}-mark.png",
            'logo_dark' => null,
            'abbreviation' => strtoupper($tag),
        ]);
    }

    Pick::factory()->won()->create([
        'slate_game_id' => $landed->id,
        'user_id' => $commissioner->id,
        'picked_team_id' => $landed->game->home_team_id,
    ]);

    Pick::factory()->lost()->create([
        'slate_game_id' => $missed->id,
        'user_id' => $commissioner->id,
        'picked_team_id' => $missed->game->home_team_id,
    ]);

    SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $commissioner->id]);

    $page = Livewire::actingAs($commissioner)->test('group', ['group' => $group])->set('view', 'standings');
    $grid = $page->instance()->picksGrid;
    $html = $page->html();

    /*
     * Scoped to the ONE cell by its wire:key — a logo URL or a utility
     * class matched against the whole document is satisfied by the pick
     * cards on the slate tab, and the assertion would survive the grid
     * being reverted to text.
     */
    $cell = function (int $column) use ($html): string {
        $start = strpos($html, 'wire:key="grid-cell-0-'.$column.'"');

        expect($start)->not->toBeFalse();

        return substr($html, $start, strpos($html, '</td>', $start) - $start);
    };

    expect($grid['rows'][0]['viewer'])->toBeTrue();

    $landedCell = $cell(array_search($landed->id, array_column($grid['columns'], 'key'), true));
    $missedCell = $cell(array_search($missed->id, array_column($grid['columns'], 'key'), true));

    // The call that landed wears its school at full strength, and says so.
    expect($landedCell)
        ->toContain('data-tone="win"')
        ->toContain('https://cdn.example.com/landed-mark.png')
        ->toContain('LANDED, correct')
        ->not->toContain('grayscale');

    // The one that missed is desaturated and faded — disabled, not red.
    expect($missedCell)
        ->toContain('data-tone="loss"')
        ->toContain('https://cdn.example.com/missed-mark.png')
        ->toContain('grayscale')
        ->toContain('opacity-40')
        ->toContain('MISSED, wrong');

    // The matchup header still identifies the fixture in words, no marks.
    $headStart = strpos($html, 'wire:key="grid-col-'.$landed->id.'"');
    $header = substr($html, $headStart, strpos($html, '</th>', $headStart) - $headStart);

    expect($header)
        ->toContain('LANDED')
        ->not->toContain('<img');
});

it('shows the movement and the member colors once two weeks have settled', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create(['admin' => true, 'handle' => 'mover']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $vols = Team::factory()->create([
        'location' => 'Tennessee',
        'display_name' => 'Tennessee Volunteers',
        'short_display_name' => 'Tennessee',
        'logo' => 'https://cdn.example.com/vols.png',
    ]);
    $member->followedTeams()->attach([$vols->id => ['position' => 1]]);

    [, $week] = pickemSeasonWeek();

    $weekOne = Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id,
        'saturday' => '2026-09-05', 'status' => Slate::SETTLED, 'settled_at' => now(),
    ]);
    $weekTwo = Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id,
        'saturday' => '2026-09-12', 'status' => Slate::SETTLED, 'settled_at' => now(),
    ]);

    // Week one: the member leads. Week two: the commissioner runs it down.
    SlateEntry::factory()->create(['slate_id' => $weekOne->id, 'user_id' => $member->id, 'final_points' => 10, 'won' => true]);
    SlateEntry::factory()->create(['slate_id' => $weekOne->id, 'user_id' => $commissioner->id, 'final_points' => 3, 'won' => false]);
    SlateEntry::factory()->create(['slate_id' => $weekTwo->id, 'user_id' => $member->id, 'final_points' => 1, 'won' => false]);
    SlateEntry::factory()->create(['slate_id' => $weekTwo->id, 'user_id' => $commissioner->id, 'final_points' => 20, 'won' => true]);

    $rows = Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->instance()->seasonStandings;

    // Wins tie one apiece; points decide — the commissioner climbed one
    // from last week's baseline, and the member gave one back. The chip
    // beside the member's handle is their own first-followed team.
    expect($rows[0]['user']->id)->toBe($commissioner->id)
        ->and($rows[0]['delta'])->toBe(1)
        ->and($rows[0]['team'])->toBeNull()
        ->and($rows[1]['delta'])->toBe(-1)
        ->and($rows[1]['team']->id)->toBe($vols->id);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->assertSee('https://cdn.example.com/vols.png', escape: false)
        ->assertSee($member->name);
});

it('keeps the movement quiet with only one settled week behind the table', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    [, $week] = pickemSeasonWeek();

    $only = Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id,
        'saturday' => '2026-09-05', 'status' => Slate::SETTLED, 'settled_at' => now(),
    ]);
    SlateEntry::factory()->create(['slate_id' => $only->id, 'user_id' => $commissioner->id, 'final_points' => 12, 'won' => true]);

    // One week has no "before" worth inventing: null, never a zero.
    expect(Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->instance()->seasonStandings->first()['delta'])->toBeNull();
});

/*
 * THE LIGHT BAND, AND ONE CONTROL ON IT (2026-09-01). The hero was a deep
 * zinc surface in both modes, which gave an uploaded mark nothing to sit
 * against; it is white with a zinc-200 border now, the grammar of every
 * card on the screen. The Talk icon left the row for a gutter tab and the
 * cog is the only button left — and a member, whose slot is EMPTY, gets no
 * wrapper at all: a passed slot is an object, an object is truthy, and
 * `?? false` used to render an empty flex div that spent its gap on the
 * title row.
 */
it('paints the hero band light, with the dark surface only behind dark:', function () {
    $source = file_get_contents(resource_path('views/components/group-hero.blade.php'));

    // The root's own class list, from the component's one attribute bag.
    $root = (string) str($source)->after('<div {{ $attributes->class([\'')->before('\']) }}>');

    expect($root)->not->toBe('')
        ->toContain('bg-white')
        ->toContain('border-zinc-200')
        ->toContain('dark:bg-zinc-900')
        ->toContain('dark:border-zinc-800')
        ->and($root)->not->toMatch('/(?<!dark:)bg-zinc-900/')
        ->not->toContain('text-white');

    [$commissioner, $group] = pickemContest(ContestMode::Classic);

    // And the rendered band wears it: the root's class list, verbatim.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSeeHtml('rounded-xl border border-zinc-200 bg-white px-4 py-4 text-zinc-900');
});

it('gives a commissioner one button on the band, and a member no wrapper at all', function () {
    [$commissioner, $group] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $heroOf = fn (User $viewer): string => (string) str(
        Livewire::actingAs($viewer)->test('group', ['group' => $group])->html()
    )->before('wire:key="group-tab-slate"');

    $commissionerHero = $heroOf($commissioner);

    // Exactly the cog: the pivot modal's trigger, once, and no thread door.
    expect(substr_count($commissionerHero, 'aria-label="Change the group\'s game"'))->toBe(1)
        ->and($commissionerHero)->not->toContain('aria-label="Group talk"')
        ->not->toContain('aria-label="Room talk"')
        ->and(substr_count($commissionerHero, 'flex shrink-0 items-center gap-2'))->toBe(1);

    // A member has nothing to put on the band, so the band renders no
    // actions wrapper — not an empty one.
    $memberHero = $heroOf($member);

    expect($memberHero)->not->toContain('aria-label="Change the group\'s game"')
        ->not->toContain('aria-label="Group talk"')
        ->not->toContain('flex shrink-0 items-center gap-2');
});

/**
 * The place band's own element, cut out of the page.
 *
 * "2nd of 10" is a phrase the standings table could grow tomorrow, and a
 * needle matched against the whole document is satisfied by whoever prints
 * it. `data-week-place` is on the band and nothing else.
 */
function weekPlaceOf(string $html): string
{
    $at = strpos($html, 'data-week-place');

    expect($at)->not->toBeFalse('the place band did not render');

    return substr($html, $at, 900);
}

/**
 * A published card two members have played into and been scored on, so the
 * room has an order in it: Alice 20, Bob 10, and the commissioner not
 * playing at all.
 *
 * @return array{0: Slate, 1: User, 2: User, 3: Group}
 */
function scoredWeek(): array
{
    [$slate, $alice, $bob] = pickemContestants();
    $games = $slate->games()->with('game')->orderBy('position')->get();

    // Home lays 6.5 in the fixture, so a 21-0 home win covers both.
    app(MakePick::class)->handle($alice, $games[0], $games[0]->game->home_team_id);
    app(MakePick::class)->handle($alice, $games[1], $games[1]->game->home_team_id);
    app(MakePick::class)->handle($bob, $games[0], $games[0]->game->home_team_id);

    test()->travelTo('2026-09-05 20:00:00');

    pickemScore($slate, 1, 21, 0);
    pickemScore($slate, 2, 21, 0);

    return [$slate->fresh(), $alice, $bob, $slate->contest->group];
}

it('says where the reader stands, above the fork, on the slate and the standings both', function () {
    /*
     * The card asked for the place on BOTH stops. One line above the tab
     * strip is one line — the alternative was printing the same fact twice,
     * once per tab, which is how two renders of one number start to
     * disagree.
     */
    [, $alice, $bob] = scoredWeek();
    $group = Group::first();

    $on = fn (User $user, string $view) => weekPlaceOf(
        Livewire::actingAs($user)->test('group', ['group' => $group])
            ->set('view', $view)
            ->html()
    );

    // Alice took two of two; Bob one. Two entries in the field — the
    // commissioner is a member who never picked, so he has no entry and is
    // not in it.
    expect($on($alice, 'slate'))->toContain('1st of 2')
        ->and($on($alice, 'standings'))->toContain('1st of 2')
        ->and($on($bob, 'slate'))->toContain('2nd of 2')
        ->and($on($bob, 'standings'))->toContain('2nd of 2');
});

it('shares a place rather than ordering two people who are level', function () {
    /*
     * The table numbers its rows 1..N after a sort, so on a shared total one
     * of these two would read as 2nd purely because the sort put them there.
     * The band counts who is strictly ahead instead.
     */
    [$slate, $alice, $bob] = pickemContestants();
    $game = $slate->games()->with('game')->orderBy('position')->first();

    app(MakePick::class)->handle($alice, $game, $game->game->home_team_id);
    app(MakePick::class)->handle($bob, $game, $game->game->home_team_id);

    $this->travelTo('2026-09-05 20:00:00');
    pickemScore($slate, 1, 21, 0);

    $group = $slate->contest->group;

    foreach ([$alice, $bob] as $level) {
        expect(weekPlaceOf(
            Livewire::actingAs($level)->test('group', ['group' => $group])->html()
        ))->toContain('T-1st of 2');
    }
});

it('invents no place over a board nobody has scored on', function () {
    /*
     * Ten members on nothing are not tied for first, they are a week that
     * has not started. Null is no place and the band does not render — this
     * is the missing-data rule, and a substituted "1st of 2" here would be
     * the whole screen lying before kickoff.
     */
    [$slate, $alice, $bob] = pickemContestants();
    $game = $slate->games()->with('game')->orderBy('position')->first();

    /*
     * BOTH of them, deliberately. With one entrant the field is too small to
     * place at all, and this test would then pass with the kickoff gate
     * deleted — green for the field rule rather than for the thing it names.
     * Two entrants on nothing is the case only the gate can answer.
     */
    app(MakePick::class)->handle($alice, $game, $game->game->home_team_id);
    app(MakePick::class)->handle($bob, $game, $game->game->home_team_id);

    $html = Livewire::actingAs($alice)->test('group', ['group' => $slate->contest->group])
        ->set('view', 'standings')
        ->html();

    expect($html)->not->toContain('data-week-place')
        // ...and the strip's own column is a dash rather than a place.
        ->and(youStripOf($html, 1200))->toContain('Wk place');
});

it('keeps the place off the errand stops', function () {
    // Members, Invite and Talk are errands. A place over a chat thread is
    // furniture, and the band costs height on the tab that has least of it.
    [, $alice] = scoredWeek();
    $group = Group::first();

    $html = Livewire::actingAs($alice)->test('group', ['group' => $group])
        ->set('view', 'members')
        ->html();

    expect($html)->not->toContain('data-week-place');
});

it('says the same place in the band and in the strip beside it', function () {
    /*
     * The two halves of the clubhouse's answer, held together the way PR
     * #91's identity seam had to be: the band carries the field, the strip's
     * column carries the place alone, and they read one computation.
     */
    [, , $bob] = scoredWeek();
    $group = Group::first();

    $html = Livewire::actingAs($bob)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->html();

    expect(weekPlaceOf($html))->toContain('2nd of 2')
        ->and(youStripOf($html, 1200))->toContain('2nd');
});

it('counts the PEOPLE in the field, and not the Bear beside them', function () {
    /*
     * The Bear is ranked in the standings table because a Woodshed reader
     * wants to see whether the house is ahead of them. A PLACE is a
     * different question: where you came among the others who played, which
     * is the reading SlateResults::ranked() already takes for the recap that
     * lands on Sunday. Counting him would make the live band and that recap
     * disagree about the same week for the same reader.
     */
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Woodshed);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    $alice = User::factory()->create(['handle' => 'alice', 'admin' => true]);
    $bob = User::factory()->create(['handle' => 'bob']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $alice->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $bob->id]);

    $game = $slate->games()->with('game')->orderBy('position')->first();
    app(MakePick::class)->handle($alice, $game, $game->game->home_team_id);
    app(MakePick::class)->handle($bob, $game, $game->game->away_team_id);

    $this->travelTo('2026-09-05 20:00:00');
    pickemScore($slate, 1, 21, 0);

    $html = Livewire::actingAs($alice)->test('group', ['group' => $group])
        ->set('view', 'standings')
        ->html();

    // The Bear is on the table — without this the assertion below would be
    // green on a slate that simply fielded no Bear at all.
    expect($html)->toContain('The Bear')
        ->and(weekPlaceOf($html))->toContain('1st of 2')
        ->and(weekPlaceOf($html))->not->toContain('of 3');
});
