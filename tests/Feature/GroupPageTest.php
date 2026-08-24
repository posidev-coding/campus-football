<?php

use App\Actions\PublishSlate;
use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
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
        ->assertSee('Locked')
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

it('renders result marks and the week standings once games grade', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    foreach ($slate->games()->with('game')->get() as $slateGame) {
        $slateGame->game->update([
            'kickoff_at' => now()->subDay(),
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

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSee('Preliminary')
        ->assertSee('This week')
        // The winner leads the loser in the room.
        ->assertSeeInOrder(['@'.$commissioner->handle, '@'.$member->handle])
        ->assertSee('+1')
        ->assertSee('No pick');
});

it('aggregates settled weeks on the Season tab, wins before points', function () {
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
        ->set('view', 'season')
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
        ->assertDontSee('No pick');
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
