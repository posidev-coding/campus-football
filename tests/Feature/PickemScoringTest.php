<?php

use App\Actions\EnterTiebreaker;
use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Jobs\GradeGamePicks;
use App\Models\Game;
use App\Models\GameTeamStat;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\Contests\PickGrader;
use App\Services\Espn\Sync\SyncGames;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * Settlement dispatches AnnounceSlateResults now, so this fake is not
 * decoration: without it every settle in this file sends real results mail
 * while asserting about points.
 *
 * Notification only, never Bus::fake() — these tests ride REAL job dispatch
 * for grading (GradeGamePicks off the score events), and faking the bus
 * swallows that too. The symptom is a pick whose result stays null.
 */
beforeEach(function () {
    Notification::fake();
});

/*
 * Phase 5 slice 6: live scoring and two-phase settlement. Grading rides
 * the sync's own events from the second a game kicks; every game final
 * turns the slate PRELIMINARY; the official-final sweep re-grades,
 * answers the tiebreaker, and pays — keyed, once, ever.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

/**
 * A published slate with two picking members. Returns everything the
 * scenarios below poke at.
 *
 * @return array{0: Slate, 1: User, 2: User}
 */
function pickemContestants(): array
{
    [$commissioner, $group, $contest] = pickemContest();
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    $alice = User::factory()->create(['handle' => 'alice', 'admin' => true]);
    $bob = User::factory()->create(['handle' => 'bob']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $alice->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $bob->id]);

    return [$slate, $alice, $bob];
}

/** Kick and score one slate game, then regrade it the way the events do. */
function pickemScore(Slate $slate, int $position, int $home, int $away, bool $final = false): void
{
    $game = $slate->games()->orderBy('position')->skip($position - 1)->first()->game;

    $game->update([
        'home_score' => $home,
        'away_score' => $away,
        'status' => $final ? 'post' : 'in',
        'completed' => $final,
    ]);

    (new GradeGamePicks($game->id))->handle(app(PickGrader::class));
}

// ---------------------------------------------------------- the job's shape

it('carries the retry shape that keeps a killed worker from stranding a game', function () {
    $job = new GradeGamePicks(1);

    expect($job->uniqueFor)->toBe(120)
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(60)
        // timeout < uniqueFor, deliberately: a timed-out run is already dead
        // before its unique lock lapses, so the retry can take the lock.
        ->and($job->timeout)->toBeLessThan($job->uniqueFor);
});

// ------------------------------------------------------------ live grading

it('grades a pick the second its game kicks, and regrades on every swing', function () {
    [$slate, $alice] = pickemContestants();
    $slateGame = $slate->games()->with('game')->first();
    app(MakePick::class)->handle($alice, $slateGame, $slateGame->game->home_team_id);

    // Pre-kick: ungraded, and null means exactly that.
    expect(Pick::sole()->result)->toBeNull();

    $this->travelTo('2026-09-05 20:00:00');

    // Home (laying 6.5) up 7: covering. Shotgun pays ten a game.
    pickemScore($slate, 1, 7, 0);
    expect(Pick::sole()->result)->toBe(Pick::WIN)
        ->and(Pick::sole()->points)->toBe(10);

    // The dog claws inside the number: the same pick is now losing.
    pickemScore($slate, 1, 7, 3);
    expect(Pick::sole()->result)->toBe(Pick::LOSS)
        ->and(Pick::sole()->points)->toBe(0);
});

it('rides the real sync events end to end', function () {
    [$slate, $alice] = pickemContestants();
    $slateGame = $slate->games()->with('game')->first();
    $game = $slateGame->game;
    app(MakePick::class)->handle($alice, $slateGame, $game->home_team_id);

    $this->travelTo('2026-09-05 20:00:00');

    // The live tier's payload for this one game, home covering by 10.
    Http::fake(['*scoreboard*' => fn () => Http::response(['events' => [[
        'id' => (string) $game->id,
        'date' => '2026-09-05T19:30Z',
        'name' => 'Away at Home',
        'season' => ['year' => 2026, 'type' => 2],
        'status' => ['period' => 4, 'displayClock' => '0:00',
            'type' => ['state' => 'post', 'completed' => true, 'shortDetail' => 'Final']],
        'competitions' => [[
            'neutralSite' => false,
            'conferenceCompetition' => false,
            'competitors' => [
                ['id' => (string) $game->home_team_id, 'homeAway' => 'home', 'score' => '24'],
                ['id' => (string) $game->away_team_id, 'homeAway' => 'away', 'score' => '14'],
            ],
        ]],
    ]]])]);

    config()->set('espn.http.rate_limit', 0);
    app(SyncGames::class)->week($slate->week);

    // The event fired, the listener dispatched, the sync queue ran it:
    // the pick is graded without anything having polled.
    expect(Pick::sole()->result)->toBe(Pick::WIN);
});

// -------------------------------------------------------- two-phase final

it('turns preliminary when the last game finals, and not one game sooner', function () {
    [$slate, $alice] = pickemContestants();
    $slateGame = $slate->games()->with('game')->first();
    app(MakePick::class)->handle($alice, $slateGame, $slateGame->game->home_team_id);

    $this->travelTo('2026-09-05 20:00:00');

    foreach (range(1, 9) as $position) {
        pickemScore($slate, $position, 21, 7, final: true);
    }
    expect($slate->fresh()->status)->toBe(Slate::PUBLISHED);

    pickemScore($slate, 10, 21, 7, final: true);
    expect($slate->fresh()->status)->toBe(Slate::PRELIM)
        // Preliminary means NOBODY has been paid.
        ->and(WalletEntry::where('reason', 'pickem-win')->count())->toBe(0);
});

it('holds settlement until the week turns official, then pays keyed, once', function () {
    [$slate, $alice, $bob] = pickemContestants();
    [$first, $second] = $slate->games()->with('game')->orderBy('position')->take(2)->get();

    // Alice takes the favorite in both; Bob fades her in game two.
    app(MakePick::class)->handle($alice, $first, $first->game->home_team_id);
    app(MakePick::class)->handle($alice, $second, $second->game->home_team_id);
    app(MakePick::class)->handle($bob, $second->fresh(), $second->game->away_team_id);

    $this->travelTo('2026-09-05 20:00:00');
    foreach (range(1, 10) as $position) {
        // Favorites cover everywhere: Alice 2-0, Bob 0-1.
        pickemScore($slate, $position, 28, 7, final: true);
    }
    expect($slate->fresh()->status)->toBe(Slate::PRELIM);

    // Saturday night: official-final is still hours away.
    $this->artisan('pickem:settle')->assertSuccessful();
    expect($slate->fresh()->status)->toBe(Slate::PRELIM);

    // Sunday 12:01 ET (16:01 UTC): the week turns official.
    $this->travelTo('2026-09-06 16:01:00');
    $this->artisan('pickem:settle')->assertSuccessful();

    $settled = $slate->fresh();
    expect($settled->status)->toBe(Slate::SETTLED)
        ->and($settled->settled_at)->not->toBeNull()
        // Combined points of the tiebreaker game (28 + 7).
        ->and($settled->tiebreaker_actual)->toBe(35);

    $aliceEntry = $settled->entries()->where('user_id', $alice->id)->sole();
    $bobEntry = $settled->entries()->where('user_id', $bob->id)->sole();
    expect($aliceEntry->final_points)->toBe(20)->and($aliceEntry->won)->toBeTrue()
        ->and($bobEntry->final_points)->toBe(0)->and($bobEntry->won)->toBeFalse();

    // 20 points × 10 XP, plus the win: 100 XP + 1 latte. Keyed.
    expect(WalletEntry::where(['user_id' => $alice->id, 'reason' => 'pickem-points'])->sole()->xp)->toBe(200)
        ->and(WalletEntry::where(['user_id' => $alice->id, 'reason' => 'pickem-win'])->sole()->lattes)->toBe(1)
        ->and(WalletEntry::where(['user_id' => $bob->id, 'reason' => 'pickem-points'])->count())->toBe(0);

    // Run the sweep again: settled is settled, nobody is paid twice.
    $this->artisan('pickem:settle')->assertSuccessful();
    expect(WalletEntry::where('reason', 'pickem-win')->count())->toBe(1);
});

it('absorbs a stat-window score correction before paying', function () {
    [$slate, $alice, $bob] = pickemContestants();
    $slateGame = $slate->games()->with('game')->first();

    app(MakePick::class)->handle($alice, $slateGame, $slateGame->game->home_team_id);
    app(MakePick::class)->handle($bob, $slateGame->fresh(), $slateGame->game->away_team_id);

    $this->travelTo('2026-09-05 20:00:00');
    foreach (range(1, 10) as $position) {
        pickemScore($slate, $position, 28, 7, final: true);
    }

    // Sunday morning, inside the window: the score is corrected to a
    // non-cover. Nobody has been paid, so nothing needs clawing back.
    $slateGame->game->update(['home_score' => 10, 'away_score' => 7]);

    $this->travelTo('2026-09-06 16:01:00');
    $this->artisan('pickem:settle')->assertSuccessful();

    // The official regrade saw the corrected score: Bob's dog covered.
    expect($slate->fresh()->entries()->where('user_id', $bob->id)->sole()->won)->toBeTrue()
        ->and(WalletEntry::where(['user_id' => $bob->id, 'reason' => 'pickem-win'])->count())->toBe(1)
        ->and(WalletEntry::where(['user_id' => $alice->id, 'reason' => 'pickem-win'])->count())->toBe(0);
});

// ------------------------------------------------------------- tiebreakers

it('settles a tied week by the closest call, with silence losing to any answer', function () {
    [$slate, $alice, $bob] = pickemContestants();
    $slateGame = $slate->games()->with('game')->first();

    // A genuine 1-1: they fade each other on the same two games, and the
    // results split — game one to the favorite, game two to the dog.
    [$first, $second] = $slate->games()->with('game')->orderBy('position')->take(2)->get();
    app(MakePick::class)->handle($alice, $first, $first->game->home_team_id);
    app(MakePick::class)->handle($bob, $first->fresh(), $first->game->away_team_id);
    app(MakePick::class)->handle($alice, $second->fresh(), $second->game->home_team_id);
    app(MakePick::class)->handle($bob, $second->fresh(), $second->game->away_team_id);

    // Alice calls the tiebreaker total; Bob never does.
    app(EnterTiebreaker::class)->handle($alice, $slate, 38);

    $this->travelTo('2026-09-05 20:00:00');
    pickemScore($slate, 1, 28, 7, final: true);  // favorite covers: Alice
    pickemScore($slate, 2, 10, 7, final: true);  // dog covers: Bob
    foreach (range(3, 10) as $position) {
        pickemScore($slate, $position, 28, 7, final: true);
    }

    $this->travelTo('2026-09-06 16:01:00');
    $this->artisan('pickem:settle')->assertSuccessful();

    // 1-1 on points; actual total 35; Alice answered, Bob stayed silent.
    $fresh = $slate->fresh();
    expect($fresh->entries()->where('user_id', $alice->id)->sole()->won)->toBeTrue()
        ->and($fresh->entries()->where('user_id', $bob->id)->sole()->won)->toBeFalse();
});

it('answers a passing-yards tiebreaker from the box score, and shares the week without one', function () {
    [$slate, $alice, $bob] = pickemContestants();
    $slate->loadMissing('tiebreakerGame.game');
    $tbGame = $slate->tiebreakerGame->game;

    // The week's question: passing yards for the home side.
    $slate->update([
        'tiebreaker_metric' => 'passing_yards',
        'tiebreaker_team_id' => $tbGame->home_team_id,
    ]);

    [$first, $second] = $slate->games()->with('game')->orderBy('position')->take(2)->get();
    app(MakePick::class)->handle($alice, $first, $first->game->home_team_id);
    app(MakePick::class)->handle($bob, $first->fresh(), $first->game->away_team_id);
    app(MakePick::class)->handle($alice, $second->fresh(), $second->game->home_team_id);
    app(MakePick::class)->handle($bob, $second->fresh(), $second->game->away_team_id);
    app(EnterTiebreaker::class)->handle($alice, $slate->fresh(), 250);
    app(EnterTiebreaker::class)->handle($bob, $slate->fresh(), 300);

    $this->travelTo('2026-09-05 20:00:00');
    pickemScore($slate, 1, 28, 7, final: true);
    pickemScore($slate, 2, 10, 7, final: true);
    foreach (range(3, 10) as $position) {
        pickemScore($slate, $position, 28, 7, final: true);
    }

    // The box score never synced: the tiebreak SKIPS and both are paid.
    $this->travelTo('2026-09-06 16:01:00');
    $this->artisan('pickem:settle')->assertSuccessful();

    $settled = $slate->fresh();
    expect($settled->tiebreaker_actual)->toBeNull()
        ->and($settled->entries()->where('won', true)->count())->toBe(2)
        ->and(WalletEntry::where('reason', 'pickem-win')->count())->toBe(2);
});

it('reads the stat when the box score IS there', function () {
    [$slate] = pickemContestants();
    $slate->loadMissing('tiebreakerGame.game');
    $tbGame = $slate->tiebreakerGame->game;

    $slate->update([
        'tiebreaker_metric' => 'passing_yards',
        'tiebreaker_team_id' => $tbGame->home_team_id,
    ]);
    $tbGame->update(['completed' => true, 'status' => 'post']);

    GameTeamStat::create([
        'game_id' => $tbGame->id,
        'team_id' => $tbGame->home_team_id,
        'stats' => ['netPassingYards' => '287', 'rushingYards' => '154'],
        'display_stats' => [],
    ]);

    expect($slate->fresh()->tiebreaker_metric->resolveActual($slate->fresh()->loadMissing('tiebreakerGame.game')))
        ->toBe(287);
});

// ----------------------------------------------------------------- rescue

it('rescues a game that went final without its event', function () {
    [$slate, $alice] = pickemContestants();
    $slateGame = $slate->games()->with('game')->first();
    app(MakePick::class)->handle($alice, $slateGame, $slateGame->game->home_team_id);

    $this->travelTo('2026-09-05 23:00:00');

    // Finals written straight to the rows — no event ever fired.
    foreach ($slate->games()->with('game')->get() as $sg) {
        $sg->game->update(['home_score' => 28, 'away_score' => 7, 'status' => 'post', 'completed' => true]);
    }
    expect(Pick::sole()->result)->toBeNull();

    $this->artisan('pickem:settle')->assertSuccessful();

    expect(Pick::sole()->result)->toBe(Pick::WIN)
        ->and($slate->fresh()->status)->toBe(Slate::PRELIM);
});

it('rescues a game shared by two slates with one dispatch, not one per slate', function () {
    Queue::fake();

    [$slateA] = pickemContestants();
    [$commissioner, , $contestB] = pickemContest();
    $slateB = pickemDraftSlate($contestB);
    app(PublishSlate::class)->handle($commissioner, $slateB);
    $slateB = $slateB->fresh();

    // Slate B's first row points at slate A's first game — the shape every
    // public room + private group pairing produces on a real Saturday.
    $shared = $slateA->games()->orderBy('position')->first()->game_id;
    $slateB->games()->orderBy('position')->first()->update(['game_id' => $shared]);

    // Final on the row, no event ever fired: the rescue pass's case.
    Game::query()->whereKey($shared)
        ->update(['home_score' => 28, 'away_score' => 7, 'status' => 'post', 'completed' => true]);

    $this->travelTo('2026-09-05 23:00:00');

    $this->artisan('pickem:settle')
        ->expectsOutputToContain('1 final game(s)')
        ->assertSuccessful();

    Queue::assertPushed(GradeGamePicks::class, 1);
});

it('shows the room its standings: live, then final with the winner named', function () {
    [$slate, $alice, $bob] = pickemContestants();
    $group = Group::query()->findOrFail($slate->contest->group_id);
    $slateGame = $slate->games()->with('game')->first();
    app(MakePick::class)->handle($alice, $slateGame, $slateGame->game->home_team_id);

    $this->travelTo('2026-09-05 20:00:00');
    pickemScore($slate, 1, 28, 7);

    Livewire\Livewire::actingAs($alice)->test('group', ['group' => $group])
        ->assertSee('This week')
        ->assertSee('Live')
        ->assertSee('@alice');

    foreach (range(1, 10) as $position) {
        pickemScore($slate, $position, 28, 7, final: true);
    }
    $this->travelTo('2026-09-06 16:01:00');
    $this->artisan('pickem:settle')->assertSuccessful();

    Livewire\Livewire::actingAs($bob)->test('group', ['group' => $group])
        ->assertSee('Final')
        ->assertSee('Winner');
});
