<?php

use App\Services\CfbCalendar;
use App\Support\Brand;
use App\Support\Cadence;
use App\Support\ConferenceMarks;
use App\Support\GameRanks;
use App\Support\Navigation;
use App\Support\Networks;
use App\Support\PickemPulse;
use App\Support\Release;
use App\Support\TeamGlance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/PickemFixtures.php';

/*
 * The suite's default NOW. Wednesday of the 2026 opening week, inside the
 * 8/22 → 9/8 span `splitPickemWeek()` models: the 8/29 Saturday is played, the
 * 9/5 one is upcoming. It is the instant 66 tests already `travelTo()` by hand,
 * so this makes the suite's existing convention its default rather than
 * inventing a new one.
 *
 * It exists because the shared fixtures pin ABSOLUTE kickoffs — deliberately,
 * since `splitPickemWeek()` reproduces the real shape of ESPN's opening week
 * and a relative kickoff cannot express "two Saturdays in one week row". An
 * absolute fixture date is only pinned as long as the wall clock is behind it:
 * on 2026-09-05 the clock passed 19:30, nineteen tests that had never travelled
 * began reading their upcoming game as kicked, and the suite could not have
 * recovered on its own the next day. Freezing the default closes that for good.
 *
 * A test that calls `travelTo()` still wins — `beforeEach` runs first.
 */
const SUITE_NOW = '2026-09-02 12:00:00';

/*
 * Feature tests run against a real MySQL database (campusfootball_test) rather
 * than SQLite. The schema leans on MySQL behaviour — generated columns, JSON
 * columns, enum-backed strings — and a SQLite-vs-MySQL divergence is exactly
 * the class of bug that should fail in CI rather than in production.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // TeamGlance, GameRanks and Brand memoize their cached values in STATIC
    // properties, which outlive the per-test application the array cache dies
    // with. Without this reset a test inherits the previous test's league — or,
    // for Brand, the previous test's colors and wordmark.
    ->beforeEach(function () {
        $this->travelTo(SUITE_NOW);

        TeamGlance::flush();
        GameRanks::flush();
        Brand::flush();
        Cadence::flush();
        CfbCalendar::flush();
        Navigation::flush();
        PickemPulse::flush();
        Networks::flush();
        ConferenceMarks::flush();
        // Release memoizes the VERSION file the same way; a test that points
        // cfb.version_file at a fixture must not hand its stamp to the next.
        Release::flush();
    })
    ->in('Feature');

/**
 * A deadlock on the first write against a table, once — the shape MySQL
 * hands back to the loser of a lock cycle.
 *
 * Shared, because every writer that calls `App\Support\Deadlocks::retry()`
 * proves it the same way — the article pivots in NewsSyncTest and the
 * box-score transaction in FetchGameSummaryJobTest.
 *
 * `errorInfo` AND the code, because Laravel's QueryException copies both off
 * the PDOException underneath it: a fake that set only one would pass a
 * check that read the other, which is how a retry test proves nothing.
 */
function deadlockOnce(string $table, string $sqlstate = '40001'): void
{
    $fired = false;

    DB::beforeExecuting(function (string $sql) use ($table, $sqlstate, &$fired) {
        if ($fired || ! str_contains($sql, $table)) {
            return;
        }

        if (! str_starts_with(strtolower(ltrim($sql)), 'insert')) {
            return;
        }

        $fired = true;

        $previous = new class($sqlstate) extends PDOException
        {
            public function __construct(string $sqlstate)
            {
                parent::__construct("SQLSTATE[{$sqlstate}]: Deadlock found when trying to get lock");

                $this->code = $sqlstate;
                $this->errorInfo = [$sqlstate, 1213, 'Deadlock found when trying to get lock'];
            }
        };

        throw new QueryException('mysql', $sql, [], $previous);
    });
}
