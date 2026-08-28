<?php

use App\Enums\ContestMode;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use App\Support\Cadence;
use App\Support\LiveState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * `cfb:state` answers "what is running", which no other report does —
 * OpsReport, CoverageReport and PickemPreflight all answer "is it working".
 *
 * The guarantee under test alongside the numbers is that it reports SHAPE
 * and never people: counts and distributions, one nameable field, and that
 * field droppable for any machine-facing skin.
 */
const STATE_SATURDAY = '2026-09-12';

/** A published slate on STATE_SATURDAY with $games games, all lined. */
function stateSlate(ContestMode $mode = ContestMode::Classic, array $overrides = []): Slate
{
    [$season, $week] = pickemSeasonWeek();
    [, , $contest] = pickemContest($mode);

    $slate = Slate::factory()->create([
        'contest_id' => $contest->id,
        'week_id' => $week->id,
        'saturday' => STATE_SATURDAY,
        'status' => Slate::PUBLISHED,
        'published_at' => '2026-09-08 12:00:00',
        ...$overrides,
    ]);

    foreach (range(1, 3) as $position) {
        // 16:00 UTC is noon ET — inSlateWindow()'s floor, and the reason the
        // window cannot be asked as a UTC date.
        $game = pickemGame($season, $week, ['kickoff_at' => STATE_SATURDAY.' 16:00:00']);
        pickemOdd($game);

        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => $game->id,
            'position' => $position,
            'spread' => -6.5,
            'favorite_team_id' => $game->home_team_id,
        ]);
    }

    return $slate->refresh();
}

/** Seat $user and give them $picks of the slate's games. */
function stateEntry(Slate $slate, User $user, int $picks = 0): SlateEntry
{
    $entry = SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $user->id]);

    foreach ($slate->games()->orderBy('position')->take($picks)->get() as $slateGame) {
        Pick::factory()->create([
            'slate_game_id' => $slateGame->id,
            'user_id' => $user->id,
            'picked_team_id' => $slateGame->favorite_team_id,
        ]);
    }

    return $entry;
}

function stateOf(bool $names = true): array
{
    return app(LiveState::class)->build(CarbonImmutable::parse(STATE_SATURDAY), $names);
}

describe('the board on one Saturday', function () {
    it('reads a published slate and its card', function () {
        $slate = stateSlate();

        $row = stateOf()['contests'][0];

        expect($row['slate_id'])->toBe($slate->id)
            ->and($row['status'])->toBe(Slate::PUBLISHED)
            ->and($row['mode_label'])->toBe('Shotgun')
            ->and($row['games'])->toBe(3)
            ->and($row['lined'])->toBe(3)
            ->and($row['exhibition'])->toBeFalse();
    });

    it('counts the Saturday window through ET noon, not a UTC date', function () {
        [$season, $week] = pickemSeasonWeek();

        // 02:00 UTC Sunday IS Saturday 10pm in Knoxville. Matching the UTC
        // date drops the whole night window.
        pickemGame($season, $week, ['kickoff_at' => '2026-09-13 02:00:00']);
        // 15:00 UTC is 11am ET — Saturday, but under the noon floor.
        pickemGame($season, $week, ['kickoff_at' => STATE_SATURDAY.' 15:00:00']);

        expect(stateOf()['games']['in_window'])->toBe(1);
    });

    it('reports picks as a distribution, never as people', function () {
        $slate = stateSlate();

        stateEntry($slate, User::factory()->create(), picks: 3);   // complete
        stateEntry($slate, User::factory()->create(), picks: 1);   // partial
        stateEntry($slate, User::factory()->create(), picks: 0);   // empty

        $row = stateOf()['contests'][0];

        expect($row['entries'])->toBe(3)
            ->and($row['picks_made'])->toBe(4)
            ->and($row['picks_possible'])->toBe(9)
            ->and($row['entries_complete'])->toBe(1)
            ->and($row['entries_empty'])->toBe(1);
    });

    it('leaves a Saturday nobody slated as an empty list, not a shrug', function () {
        stateSlate();

        expect(app(LiveState::class)->build(CarbonImmutable::parse('2026-09-19'))['contests'])->toBe([]);
    });

    it('defaults to the Saturday this week is on', function () {
        // Friday looks FORWARD; Sunday and Monday look back.
        $this->travelTo(CarbonImmutable::parse('2026-09-11 09:00:00', config('cfb.timezone')));

        expect(app(LiveState::class)->build()['saturday'])->toBe(STATE_SATURDAY)
            ->and(Cadence::currentSaturday()->toDateString())->toBe(STATE_SATURDAY);
    });
});

describe('a missing stamp stays missing', function () {
    it('reports null for every wave and settlement a slate has not reached', function () {
        stateSlate(overrides: ['status' => Slate::DRAFT, 'published_at' => null]);

        $row = stateOf()['contests'][0];

        expect($row['published_at'])->toBeNull()
            ->and($row['picks_reminded_at'])->toBeNull()
            ->and($row['last_call_sent_at'])->toBeNull()
            ->and($row['settled_at'])->toBeNull()
            ->and($row['results_announced_at'])->toBeNull();
    });

    it('renders a missing stamp as a dash, never as now', function () {
        stateSlate(overrides: ['status' => Slate::DRAFT, 'published_at' => null]);

        $this->artisan('cfb:state', ['--saturday' => STATE_SATURDAY])
            ->expectsOutputToContain('—')
            ->assertSuccessful();
    });
});

describe('shape, never people', function () {
    it('drops the one nameable field for a machine-facing skin', function () {
        stateSlate();

        expect(stateOf(names: true)['contests'][0]['group'])->not->toBeNull()
            ->and(stateOf(names: false)['contests'][0]['group'])->toBeNull();
    });

    it('never carries an address or a handle, whichever shape it is asked for', function () {
        $slate = stateSlate();
        stateEntry($slate, User::factory()->create([
            'email' => 'rehearsal-canary@example.test',
            'handle' => 'canaryhandle',
        ]), picks: 2);

        foreach ([true, false] as $names) {
            $payload = json_encode(stateOf($names));

            expect($payload)
                ->not->toContain('rehearsal-canary@example.test')
                ->not->toContain('canaryhandle')
                ->not->toContain('"email"')
                ->not->toContain('"handle"')
                ->not->toContain('"user_id"');
        }
    });

    it('counts people without naming any of them', function () {
        User::factory()->create(['email_verified_at' => now()]);
        User::factory()->create(['email_verified_at' => null]);

        $people = stateOf()['people'];

        expect($people['users'])->toBeGreaterThanOrEqual(2)
            ->and($people)->toHaveKeys(['users', 'verified', 'admins', 'onboarded', 'push_devices', 'push_people']);
    });
});

describe('the command', function () {
    it('emits valid JSON and nothing but JSON', function () {
        // An /ops/state skin would serve this verbatim; a stray line of console
        // output makes it unparseable at the other end.
        stateSlate();

        Artisan::call('cfb:state', ['--saturday' => STATE_SATURDAY, '--json' => true]);

        $output = Artisan::output();

        expect(json_decode($output, true))->toBeArray()
            ->and(trim($output))->toStartWith('{');
    });

    it('names the practice flag in words, because it is the thing you check', function () {
        stateSlate(overrides: ['exhibition' => true]);

        $this->artisan('cfb:state', ['--saturday' => STATE_SATURDAY])
            ->expectsOutputToContain('practice')
            ->assertSuccessful();
    });

    it('refuses a --saturday it cannot read rather than guessing one', function () {
        $this->artisan('cfb:state', ['--saturday' => 'not-a-date'])
            ->expectsOutputToContain('must be a date')
            ->assertExitCode(Command::INVALID);
    });

    it('says so when nobody can be reached by push', function () {
        stateSlate();

        $this->artisan('cfb:state', ['--saturday' => STATE_SATURDAY])
            ->expectsOutputToContain('nobody has granted push')
            ->assertSuccessful();
    });
});
