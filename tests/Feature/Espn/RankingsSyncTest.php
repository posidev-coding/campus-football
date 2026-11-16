<?php

use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Services\Espn\Sync\SyncRankings;
use App\Support\CoverageReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The scheduled rankings pass, `cfb:sync --only=rankings-current`, Sunday and
 * Tuesday at 19:00 ET.
 *
 * ESPN files a poll under the week it ranks teams FOR and posts it the Sunday
 * before that week opens. Measured against 2025: "AP Poll Week 11" is dated
 * Sunday Nov 2, posted 22:11Z, while week 11 opens Monday Nov 3 08:00Z. The
 * Sunday run asked only for the week it was inside, re-read the poll it
 * already held, and left the new one waiting until Tuesday.
 *
 * The week rows reproduce 2026's real ranges: Monday 08:00Z to Monday 07:59Z.
 */

const AP_WEEK_11 = 'seasons/2026/types/2/weeks/11/rankings';
const AP_WEEK_10 = 'seasons/2026/types/2/weeks/10/rankings';

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2026, 'type' => Season::REGULAR,
        'start_date' => '2026-08-22 07:00:00', 'end_date' => '2026-12-13 07:59:00',
    ]);

    $this->week10 = Week::create([
        'season_id' => $this->season->id, 'number' => 10, 'name' => 'Week 10',
        'start_date' => '2026-11-02 08:00:00', 'end_date' => '2026-11-09 07:59:00',
    ]);

    $this->week11 = Week::create([
        'season_id' => $this->season->id, 'number' => 11, 'name' => 'Week 11',
        'start_date' => '2026-11-09 08:00:00', 'end_date' => '2026-11-16 07:59:00',
    ]);

    Team::factory()->create(['id' => 2633, 'display_name' => 'Tennessee Volunteers']);
    Team::factory()->create(['id' => 333, 'display_name' => 'Alabama Crimson Tide']);

    // The poll we already hold: week 10's AP, synced the Tuesday before.
    foreach ([[2633, 4], [333, 7]] as [$teamId, $rank]) {
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week10->id,
            'poll' => 'ap', 'team_id' => $teamId, 'rank' => $rank, 'points' => 1000,
        ]);
    }

    // A core collection of one AP poll, and the poll itself.
    $this->collection = fn (string $path) => [
        'items' => [['$ref' => "http://sports.core.api.espn.com/v2/sports/football/leagues/college-football/{$path}/1?lang=en"]],
    ];

    $this->apPoll = fn (int $tennessee, int $alabama) => [
        'id' => 1,
        'ranks' => [
            ['current' => $tennessee, 'previous' => 4, 'points' => 1400, 'firstPlaceVotes' => 0,
                'team' => ['$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/college-football/seasons/2026/teams/2633?lang=en'],
                'record' => ['summary' => '9-1']],
            ['current' => $alabama, 'previous' => 7, 'points' => 1100, 'firstPlaceVotes' => 0,
                'team' => ['$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/college-football/seasons/2026/teams/333?lang=en'],
                'record' => ['summary' => '7-3']],
        ],
    ];

    // Sunday Nov 8, 19:00 ET: the scheduled slot, after Saturday's games and
    // after the new AP poll went up.
    $this->sundayRun = CarbonImmutable::parse('2026-11-08 19:00:00', 'America/New_York');
});

it('asks for the poll week ESPN just released on a Sunday evening, and stores it', function () {
    $this->travelTo($this->sundayRun);

    Http::fake([
        '*'.AP_WEEK_11 => Http::response(($this->collection)(AP_WEEK_11)),
        '*'.AP_WEEK_11.'/1*' => Http::response(($this->apPoll)(2, 9)),
        '*'.AP_WEEK_10 => Http::response(($this->collection)(AP_WEEK_10)),
        '*'.AP_WEEK_10.'/1*' => Http::response(($this->apPoll)(4, 7)),
        '*' => Http::response([], 404),
    ]);

    app(SyncRankings::class)->current(2026);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), AP_WEEK_11));

    expect(Ranking::where('week_id', $this->week11->id)->where('poll', 'ap')->pluck('rank', 'team_id')->all())
        ->toEqual([2633 => 2, 333 => 9]);

    // The week we are inside is still read: the CFP for it posts after the
    // Tuesday run, and Sunday is the only scheduled pass that collects it.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), AP_WEEK_10));
});

it('writes nothing and keeps what it holds when the new poll is not out yet', function () {
    $this->travelTo($this->sundayRun);

    $held = Ranking::orderBy('id')->get(['id', 'week_id', 'rank', 'points', 'updated_at'])->toArray();

    Http::fake([
        // ESPN answers a week with no polls yet as an empty collection.
        '*'.AP_WEEK_11 => Http::response(['items' => []]),
        '*'.AP_WEEK_10 => Http::response(['items' => []]),
        '*' => Http::response([], 404),
    ]);

    expect(app(SyncRankings::class)->current(2026))->toBe(0);

    // No poll yet is no data: nothing for week 11, and week 10 is not copied
    // forward or touched.
    expect(Ranking::where('week_id', $this->week11->id)->exists())->toBeFalse()
        ->and(Ranking::orderBy('id')->get(['id', 'week_id', 'rank', 'points', 'updated_at'])->toArray())->toEqual($held);
});

it('reads one week on a Tuesday, once the poll week has opened', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 19:00:00', 'America/New_York'));

    Http::fake([
        '*'.AP_WEEK_11 => Http::response(($this->collection)(AP_WEEK_11)),
        '*'.AP_WEEK_11.'/1*' => Http::response(($this->apPoll)(2, 9)),
        '*' => Http::response([], 404),
    ]);

    app(SyncRankings::class)->current(2026);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), AP_WEEK_11));
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), AP_WEEK_10));
});

it('resolves the poll week from the calendar, a day ahead of the week we are in', function () {
    $calendar = app(CfbCalendar::class);

    // Saturday night: the week's own poll is still the newest.
    expect($calendar->pollWeek(CarbonImmutable::parse('2026-11-07 22:00:00', 'America/New_York'))?->id)
        ->toBe($this->week10->id);

    // Sunday evening: next week's poll is out, though next week is not open.
    expect($calendar->week($this->sundayRun)?->id)->toBe($this->week10->id)
        ->and($calendar->pollWeek($this->sundayRun)?->id)->toBe($this->week11->id);

    // Midweek: the two agree again.
    expect($calendar->pollWeek(CarbonImmutable::parse('2026-11-11 12:00:00', 'America/New_York'))?->id)
        ->toBe($this->week11->id);
});

it('says on the coverage row whether the newest poll week is held', function () {
    $rankings = fn () => collect(app(CoverageReport::class)->checks())->firstWhere('key', 'rankings');

    $this->travelTo($this->sundayRun);

    // Week 10 is held and freshly reconciled, so the age reads healthy. The
    // row must not let that pass for the week 11 poll we do not have.
    expect($rankings()['detail'])->toContain('no Week 11 poll held yet');

    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week11->id,
        'poll' => 'ap', 'team_id' => 2633, 'rank' => 2, 'points' => 1400,
    ]);

    CfbCalendar::flush();

    expect($rankings()['detail'])->toContain('; Week 11 poll held');
});
