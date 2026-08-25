<?php

use App\Actions\PublishSlate;
use App\Enums\ContestMode;
use App\Models\Group;
use App\Models\User;
use App\Support\PickemPreflight;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/*
 * THE FLIP CHECK. `pickem` is one line in AppServiceProvider, and flipping
 * it is the cheap part — everything underneath it has to already be true,
 * because a new user who lands on an empty lobby is a first
 * impression you do not get back.
 *
 * The discipline these hold: the command READS. It never stocks a room,
 * never publishes a slate, and above all never flips the flag it reports
 * on.
 */

function preflight(): array
{
    return collect(app(PickemPreflight::class)->checks())->keyBy('key')->all();
}

it('fails the calendar check rather than inventing a week', function () {
    /*
     * With no seasons at all there is no current week — and the honest answer
     * is a red row with the sync command beside it, never a substituted year.
     * This is the no-defaults rule at the top of the readiness report.
     */
    $checks = preflight();

    expect($checks['calendar']['status'])->toBe(PickemPreflight::FAIL)
        ->and($checks['calendar']['remedy'])->toStartWith('cfb:sync');

    // And the checks that hang off a week fail WITH it, rather than throwing.
    expect($checks['rooms']['status'])->toBe(PickemPreflight::FAIL)
        ->and($checks['lines']['status'])->toBe(PickemPreflight::FAIL);
});

it('passes the calendar and rooms checks once the lobby is actually stocked', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    // Five more lined games so the FIFTEEN-game modes are feasible on this
    // Saturday — otherwise the check would EXEMPT them ("not enough games")
    // instead of naming them missing, and this test would pass hollow.
    [$season, $week] = pickemSeasonWeek();
    foreach (range(1, 5) as $i) {
        pickemOdd(pickemGame($season, $week));
    }

    // A published slate in a PRIVATE group is not a stocked lobby.
    expect(preflight()['rooms']['status'])->toBe(PickemPreflight::FAIL);

    // Turn the group into this week's open room for its mode; the other two
    // modes are still missing, so the row stays red and NAMES them.
    $group->update([
        'kind' => Group::KIND_LOBBY,
        'week_id' => $slate->week_id,
        'filled_at' => null,
    ]);

    $rooms = preflight()['rooms'];

    expect($rooms['status'])->toBe(PickemPreflight::FAIL)
        ->and($rooms['detail'])->toContain('Triple Option')
        ->and($rooms['detail'])->toContain('The Woodshed')
        // The mode that IS stocked is not listed as missing.
        ->and($rooms['detail'])->not->toContain('Shotgun');
});

it('counts a full room as unavailable inventory', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $group->update([
        'kind' => Group::KIND_LOBBY,
        'week_id' => $slate->week_id,
        // filled_at is the spawn claim: a stamped room has no seats left, so
        // it is inventory that cannot be joined and must not count.
        'filled_at' => now(),
    ]);

    expect(preflight()['rooms']['detail'])->toContain('Shotgun');
});

it('reports the flag as closed, and never resolves Pennant to find out', function () {
    User::factory()->create(['admin' => false]);

    $flag = preflight()['flag'];

    expect($flag['status'])->toBe(PickemPreflight::WARN)
        ->and($flag['detail'])->toContain('PICKEM_OPEN');

    /*
     * The report is a READ. Resolving the flag would PERSIST a features row
     * as a side effect of asking a question — which is why the check reads
     * config instead, and why this assertion is the point of the test.
     */
    expect(DB::table('features')->count())->toBe(0);
});

it('reports the flag as open once the config actually says so', function () {
    config(['cfb.pickem_open' => true]);

    expect(preflight()['flag']['status'])->toBe(PickemPreflight::OK)
        ->and(DB::table('features')->count())->toBe(0);
});

it('opens the real surfaces to a non-admin AND to a guest when the config is flipped', function () {
    /*
     * The config is not decoration on the flag — it IS the flag, and this is
     * the assertion that would fail if the two ever drifted apart.
     *
     * The GUEST half is the one that matters commercially. Pennant resolves a
     * signed-out visitor to a null scope, and /join/{CODE} bounces its whole
     * screen when this flag is inactive — so a flag that excluded the null
     * scope would send everybody who clicked a shared invite link to the
     * coming-soon page. Open means open. InviteTest holds the other end.
     */
    $ordinary = User::factory()->create(['admin' => false]);

    expect(Feature::for($ordinary)->active('pickem'))->toBeFalse()
        ->and(Feature::for(null)->active('pickem'))->toBeFalse();

    config(['cfb.pickem_open' => true]);

    // Asking the two questions above PERSISTED both answers — so this test
    // has to purge for the same reason launch does. Without it the flip
    // reaches neither of them and this assertion fails, which is precisely
    // the production failure the next test pins.
    $this->artisan('pennant:purge', ['features' => ['pickem']])->assertSuccessful();
    Feature::flushCache();

    expect(Feature::for($ordinary)->active('pickem'))->toBeTrue()
        ->and(Feature::for(null)->active('pickem'))->toBeTrue();
});

it('does NOT reach somebody whose value was already persisted — the flip landmine', function () {
    /*
     * Pennant's database driver stores every resolved value, so the closure
     * runs once per user and the answer is read from a row after that.
     * Flipping the config therefore reaches nobody who has already loaded a
     * page: they keep the false stored for them, and the launch silently does
     * nothing for exactly the people who were already here.
     *
     * This is pinned rather than fixed because it is Pennant working as
     * designed. The preflight's `stored` row is what says so out loud, and
     * pennant:purge is the remedy it prints.
     */
    $ordinary = User::factory()->create(['admin' => false]);

    expect(Feature::for($ordinary)->active('pickem'))->toBeFalse();

    config(['cfb.pickem_open' => true]);
    Feature::flushCache();

    expect(Feature::for($ordinary)->active('pickem'))->toBeFalse();

    // The preflight sees the stored row and names the command that clears it.
    $stored = preflight()['stored'];

    expect($stored['status'])->toBe(PickemPreflight::WARN)
        ->and($stored['remedy'])->toBe('pennant:purge pickem');

    $this->artisan('pennant:purge', ['features' => ['pickem']])->assertSuccessful();
    Feature::flushCache();

    // Checked BEFORE resolving again — asking re-persists, so the clean row
    // is only observable in the gap between the purge and the next read.
    expect(preflight()['stored']['status'])->toBe(PickemPreflight::OK);

    // And now the flip actually reaches them.
    expect(Feature::for($ordinary)->active('pickem'))->toBeTrue();
});

it('holds the three sweeps that keep a live league honest', function () {
    // A flag flipped without these looks fine for a day and then quietly
    // stops publishing slates on the Tuesday nobody was watching.
    //
    // NOTE this only proves the CONSOLE path. Pest boots the console kernel
    // before the first test, so the schedule is already populated here and
    // this passed throughout the bug below.
    expect(preflight()['schedule']['status'])->toBe(PickemPreflight::OK);
});

it('finds the sweeps over HTTP, where the schedule starts out empty', function () {
    /*
     * THE BUG THIS EXISTS FOR. routes/console.php loads only when the CONSOLE
     * kernel bootstraps, so in an HTTP request `app(Schedule::class)` resolves
     * with no events at all — and a raw read of it concluded that all four
     * sweeps were unscheduled. Every admin page load showed a red launch gate
     * over a schedule that was entirely correct, a week before the flip.
     *
     * The empty case cannot occur naturally in Pest: the kernel is bootstrapped
     * before the first test, and `commandsLoaded` stops a second bootstrap()
     * from re-reading the file. So it is STAGED — an empty Schedule, plus a
     * kernel that populates it when asked to bootstrap, which is precisely
     * what happens on the HTTP path.
     */
    $schedule = new Schedule;
    app()->instance(Schedule::class, $schedule);

    $kernel = Mockery::mock(Kernel::class)->shouldIgnoreMissing();
    $kernel->shouldReceive('bootstrap')->once()->andReturnUsing(function () use ($schedule): void {
        foreach (['pickem:publish-slates', 'pickem:settle', 'pickem:open-lobbies', 'pickem:remind'] as $command) {
            $schedule->command($command)->weekly();
        }
    });
    app()->instance(Kernel::class, $kernel);

    expect(preflight()['schedule']['status'])->toBe(PickemPreflight::OK);
});

it('reports the league clock from Cadence, not from a hardcoded weekday', function () {
    // The founders' cycle: the card is available by Thursday, results turn
    // official Sunday. Moved off Tuesday end-of-day 2026-08-20.
    $settings = preflight()['settings'];

    expect($settings['status'])->toBe(PickemPreflight::OK)
        ->and($settings['detail'])->toContain('Thu')
        ->and($settings['detail'])->toContain('Sun');
});

it('exits non-zero while anything is blocking, and says not to flip', function () {
    $this->artisan('pickem:preflight')
        ->expectsOutputToContain('do not flip the flag yet')
        ->assertExitCode(1);
});
