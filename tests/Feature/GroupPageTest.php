<?php

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

    // The play tab answers picks; the standings moved to their own tab.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'slate')
        ->assertSee('Preliminary')
        ->assertSee('+1')
        ->assertSee('No pick')
        ->assertDontSee('This week')
        ->set('view', 'standings')
        ->assertSee('This week')
        // The winner leads the loser in the room.
        ->assertSeeInOrder(['@'.$commissioner->handle, '@'.$member->handle]);
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

it('normalizes the three-tab era\'s addresses onto the merged plate', function () {
    [$commissioner, $group] = pickemContest(ContestMode::Classic);

    // Mount half: bookmarked ?view= values from before the merge.
    Livewire::withQueryParams(['view' => 'members'])
        ->actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSet('view', 'standings');

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

it('shows the Standings tab its whole room: you-strip, invite, members, rules', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('view', 'standings')
        // Nothing has been played: every figure is a dash, never a zero.
        ->assertSee('Wk rank')
        ->assertSee('—')
        // The invite panel carries the link and the spoken-word code.
        ->assertSee($group->code)
        ->assertSee('Or read them the code')
        // The roster disclosure and its management affordances.
        ->assertSee('Members')
        ->assertSee('Commissioner')
        // The scoring panel, sized from the contest.
        ->assertSee('Triple Option')
        // The thread's doors: the hero button and the foot link-row.
        ->assertSee('Group talk')
        ->assertSee(route('pickem.talk', $group), escape: false);
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
        // The week's winner outranks the commissioner's seat order.
        ->assertSeeInOrder(['@'.$member->handle, '@'.$commissioner->handle]);
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
        // No seat, no thread door.
        ->assertDontSee('Room talk');
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
        ->assertSee('@gridwatcher')
        ->assertSeeHtml('data-cell="hidden"')
        ->assertSeeHtml('data-cell="pick"');
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
        ->assertSee('@mover');
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
