<?php

use App\Enums\Poll;
use App\Enums\SeasonPhase;
use App\Models\Game;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\CfbCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
 * Traversing the college football calendar automatically is the whole point of
 * this service, so these tests travel through a real year rather than checking
 * one convenient moment.
 */

beforeEach(function () {
    Cache::flush();

    /*
     * All four ESPN season types, with the real ranges verified live. The
     * labels are misleading and the fixture keeps them verbatim on purpose:
     * ESPN's "Preseason" is six months (February to kickoff) and its
     * "Off Season" is an eleven-day bridge after the playoff.
     */
    Season::factory()->create([
        'year' => 2025, 'type' => Season::PRESEASON, 'name' => 'Preseason',
        'start_date' => '2025-02-01', 'end_date' => '2025-08-23',
    ]);

    $this->regular = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR, 'name' => 'Regular Season',
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    $this->postseason = Season::factory()->create([
        'year' => 2025, 'type' => Season::POSTSEASON, 'name' => 'Postseason',
        'start_date' => '2025-12-13', 'end_date' => '2026-01-21',
    ]);

    $this->offseason = Season::factory()->create([
        'year' => 2025, 'type' => Season::OFFSEASON, 'name' => 'Off Season',
        'start_date' => '2026-01-21', 'end_date' => '2026-02-01',
    ]);

    Season::factory()->create([
        'year' => 2026, 'type' => Season::PRESEASON, 'name' => 'Preseason',
        'start_date' => '2026-02-01', 'end_date' => '2026-08-22',
    ]);

    $this->next = Season::factory()->create([
        'year' => 2026, 'type' => Season::REGULAR, 'name' => 'Regular Season',
        'start_date' => '2026-08-22', 'end_date' => '2026-12-13',
    ]);

    /*
     * Contiguous ranges, as ESPN publishes them — week 1 runs
     * 2025-08-23T07:00Z to 2025-09-02T06:59Z, with no gap to week 2. Giving
     * weeks date-only end values leaves the last day of each week belonging to
     * no week at all.
     */
    for ($i = 1; $i <= 16; $i++) {
        $start = CarbonImmutable::parse('2025-08-23 07:00', 'UTC')->addWeeks($i - 1);

        Week::create([
            'season_id' => $this->regular->id,
            'number' => $i,
            'name' => "Week {$i}",
            'start_date' => $start,
            'end_date' => $start->addWeek()->subSecond(),
        ]);
    }

    $this->calendar = app(CfbCalendar::class);
});

it('reports the regular season mid-season', function () {
    $this->travelTo('2025-10-08 16:00');

    expect($this->calendar->phase())->toBe(SeasonPhase::Regular)
        ->and($this->calendar->currentYear())->toBe(2025)
        ->and($this->calendar->week()?->number)->toBe(7);
});

it('reports the postseason during bowls', function () {
    $this->travelTo('2026-01-05');

    expect($this->calendar->phase())->toBe(SeasonPhase::Postseason)
        ->and($this->calendar->currentYear())->toBe(2025);
});

it('has no week during the postseason, without blowing up', function () {
    // Bowl games fall outside every regular-season week. v3 resolved this with
    // ->first()->id and no null guard, which killed the sync outright.
    $this->travelTo('2026-01-05');

    expect($this->calendar->week())->toBeNull()
        ->and($this->calendar->label())->toBe('Postseason');
});

it('reports preseason in the run-up to kickoff', function () {
    $this->travelTo('2026-08-04');

    expect($this->calendar->phase())->toBe(SeasonPhase::Preseason)
        // Chronologically we are heading into 2026.
        ->and($this->calendar->currentYear())->toBe(2026);
});

it('reports offseason in the dead months', function () {
    // April sits inside ESPN's type 1 "Preseason", which runs from February.
    // Reporting that label verbatim would tell a user it is preseason in
    // spring, so type 1 is split by proximity to kickoff.
    $this->travelTo('2026-04-15');

    expect($this->calendar->phase())->toBe(SeasonPhase::Offseason);
});

it('treats ESPN\'s eleven-day "Off Season" bridge as offseason', function () {
    // Type 4 runs only from the playoff ending to Feb 1.
    $this->travelTo('2026-01-25');

    expect($this->calendar->phase())->toBe(SeasonPhase::Offseason)
        ->and($this->calendar->currentYear())->toBe(2025);
});

it('prefers the type that carries games where ranges touch', function () {
    // ESPN's ranges abut: one type's end date is the next one's start, so an
    // instant on a boundary matches two rows.
    $this->travelTo('2025-08-23 12:00');

    expect($this->calendar->phase())->toBe(SeasonPhase::Regular);
});

it('separates the chronological season from the season with results', function () {
    // The distinction that stops a dropdown defaulting to an empty screen: in
    // August the upcoming season has no games, but the last one does.
    $this->travelTo('2026-08-04');

    Team::factory()->count(2)->create();
    Game::factory()->finished()->create(['season_id' => $this->regular->id]);

    expect($this->calendar->currentYear())->toBe(2026)
        ->and($this->calendar->resultsYear())->toBe(2025);
});

it('opens on the week currently being played', function () {
    $this->travelTo('2025-10-01 16:00');

    expect($this->calendar->defaultWeekNumber(2025))->toBe(6);
});

it('falls back to the last week that has games, not the highest number', function () {
    $this->travelTo('2026-04-15');

    $week5 = Week::where('season_id', $this->regular->id)->where('number', 5)->sole();
    Team::factory()->count(2)->create();
    Game::factory()->finished()->create([
        'season_id' => $this->regular->id,
        'week_id' => $week5->id,
    ]);

    // Week 16 exists and is empty; landing there shows "nothing on the slate".
    expect($this->calendar->defaultWeekNumber(2025))->toBe(5);
});

it('picks the most recent season that actually has the poll', function () {
    $team = Team::factory()->create();
    $week = Week::where('season_id', $this->regular->id)->where('number', 1)->sole();

    Ranking::create([
        'season_id' => $this->regular->id, 'week_id' => $week->id,
        'poll' => 'ap', 'team_id' => $team->id, 'rank' => 1,
    ]);

    // 2026 exists and is chronologically later, but has no poll.
    expect($this->calendar->rankingsYear('ap'))->toBe(2025)
        ->and($this->calendar->latestRankingRelease(2025, 'ap'))->toBe($week->id);
});

it('defaults to AP until the CFP committee releases one', function () {
    $team = Team::factory()->create();
    $week = Week::where('season_id', $this->regular->id)->where('number', 5)->sole();

    Ranking::create([
        'season_id' => $this->regular->id, 'week_id' => $week->id,
        'poll' => 'ap', 'team_id' => $team->id, 'rank' => 1,
    ]);

    expect($this->calendar->defaultPoll(2025))->toBe(Poll::Ap);
});

it('switches the default to CFP once one exists', function () {
    // The committee does not publish until week 11; from that release on, the
    // CFP poll is the one people actually argue about.
    $team = Team::factory()->create();
    $week11 = Week::where('season_id', $this->regular->id)->where('number', 11)->sole();

    Ranking::create([
        'season_id' => $this->regular->id, 'week_id' => $week11->id,
        'poll' => 'cfp', 'team_id' => $team->id, 'rank' => 1,
    ]);

    expect($this->calendar->defaultPoll(2025))->toBe(Poll::Cfp);
});

it('labels releases across all three season types', function () {
    $team = Team::factory()->create();

    $preWeek = Week::create([
        'season_id' => Season::where('year', 2025)->where('type', Season::PRESEASON)->sole()->id,
        'number' => 1, 'name' => 'Week 1',
        'start_date' => '2025-08-01', 'end_date' => '2025-08-22',
    ]);
    $postWeek = Week::create([
        'season_id' => $this->postseason->id, 'number' => 1, 'name' => 'Bowls',
        'start_date' => '2025-12-14', 'end_date' => '2026-01-20',
    ]);
    $wk5 = Week::where('season_id', $this->regular->id)->where('number', 5)->sole();

    foreach ([[$preWeek, Season::where('year', 2025)->where('type', Season::PRESEASON)->sole()->id],
        [$wk5, $this->regular->id],
        [$postWeek, $this->postseason->id]] as [$w, $seasonId]) {
        Ranking::create([
            'season_id' => $seasonId, 'week_id' => $w->id,
            'poll' => 'ap', 'team_id' => $team->id, 'rank' => 1,
        ]);
    }

    $labels = collect($this->calendar->rankingReleases(2025, 'ap'))->pluck('label')->all();

    // Chronological, and week 1 of the preseason must not collide with week 1
    // of the postseason.
    expect($labels)->toBe(['Preseason', 'Week 5', 'Final Rankings'])
        // The default still points at the newest release, which is now last.
        ->and($this->calendar->latestRankingRelease(2025, 'ap'))->toBe($postWeek->id);
});

it('labels the current week during play', function () {
    $this->travelTo('2025-10-08 16:00');

    expect($this->calendar->label())->toBe('Week 7');
});

it('survives a second read of the cached week list', function () {
    /*
     * Regression: weekReleases() cached CarbonImmutable instances, which come
     * back out of the cache as __PHP_Incomplete_Class and fatal the moment a
     * method is called on them.
     *
     * The failure mode is why this test calls TWICE. The first call populates
     * the cache and returns live objects, so it always passes; only the second
     * read hits the serialized copy. A single-call test would have shipped this.
     */
    $calendar = app(CfbCalendar::class);

    $calendar->weekReleases(2025);
    $calendar->defaultWeekId(2025);

    expect(fn () => $calendar->defaultWeekId(2025))->not->toThrow(Throwable::class);

    foreach ($calendar->weekReleases(2025) as $week) {
        expect($week['starts_at'])->toBeInt();
    }
});

it('opens the scoreboard on the upcoming season once it is scheduled', function () {
    /*
     * In August the current season is scheduled but unplayed, so resultsYear()
     * points at LAST season. A scoreboard defaulting to that shows bowl games
     * from eight months ago instead of week 1.
     */
    $this->travelTo('2026-08-04');

    $week = Week::create([
        'season_id' => $this->next->id, 'number' => 1, 'name' => 'Week 1',
        'start_date' => '2026-08-22 07:00', 'end_date' => '2026-09-02 06:59',
    ]);

    Game::factory()->create(['season_id' => $this->next->id, 'week_id' => $week->id]);

    expect($this->calendar->scoreboardYear())->toBe(2026)
        ->and($this->calendar->defaultWeekId(2026))->toBe($week->id);
});

it('orders results year by year, not by season id', function () {
    /*
     * Regression: this read Game::max('season_id'), which only worked while
     * seasons happened to be inserted in chronological order. Backfilling older
     * seasons gave them HIGHER ids and moved every default season in the app
     * backwards — the whole app quietly fell back a year.
     */
    Game::factory()->create([
        'season_id' => $this->regular->id,
        'week_id' => Week::where('season_id', $this->regular->id)->value('id'),
    ]);

    // Inserted last, so 2019 holds the HIGHEST season id in the table.
    $old = Season::factory()->create([
        'year' => 2019, 'type' => Season::REGULAR,
        'start_date' => '2019-08-24', 'end_date' => '2019-12-14',
    ]);

    $oldWeek = Week::create([
        'season_id' => $old->id, 'number' => 1, 'name' => 'Week 1',
        'start_date' => '2019-08-24 07:00', 'end_date' => '2019-09-02 06:59',
    ]);

    Game::factory()->create(['season_id' => $old->id, 'week_id' => $oldWeek->id]);

    expect($this->calendar->resultsYear())->toBe(2025);
});

it('never builds a season whose dates disagree with its year', function () {
    /*
     * The calendar reads date RANGES and never the `year` column, so a row
     * where the two disagree is not a cosmetic problem — it becomes "the
     * season we are heading into" and pulls every default year in the app back
     * with it.
     *
     * SeasonFactory used to compute its dates in `definition()` from the random
     * faker year, so overriding only `year` left the old dates in place. Home's
     * featured games served last season's bowls about one run in twelve: often
     * enough to see, rare enough to blame on anything but the fixture.
     *
     * Every type is checked, because the postseason is the one that crosses
     * into the next calendar year and so is the easiest to get wrong.
     */
    foreach ([Season::PRESEASON, Season::REGULAR, Season::POSTSEASON, Season::OFFSEASON] as $type) {
        $season = Season::factory()->create(['year' => 2031, 'type' => $type]);

        expect($season->start_date->year)->toBeGreaterThanOrEqual(2031)
            ->and($season->start_date->year)->toBeLessThanOrEqual(2032)
            ->and($season->end_date->year)->toBeGreaterThanOrEqual(2031)
            ->and($season->end_date->year)->toBeLessThanOrEqual(2032)
            ->and($season->end_date->gt($season->start_date))->toBeTrue()
            ->and($season->name)->toContain('2031');
    }

    // And an explicitly pinned range is still left exactly as given.
    $pinned = Season::factory()->create([
        'year' => 2032, 'type' => Season::REGULAR,
        'start_date' => '2032-01-01', 'end_date' => '2032-01-02',
    ]);

    expect($pinned->start_date->toDateString())->toBe('2032-01-01');
});
