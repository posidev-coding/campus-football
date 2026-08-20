<?php

use App\Actions\AutoPublishStandardSlate;
use App\Enums\TiebreakerMetric;
use App\Models\PickemSetting;
use App\Models\Season;
use App\Models\Slate;
use App\Models\User;
use App\Models\Week;
use App\Services\Contests\ContestLine;
use App\Support\Cadence;
use Carbon\CarbonImmutable;

/*
 * The league's weekly clock: the Saturday noon-to-midnight ET window, the
 * commissioner's slate deadline, the official-final moment, and the
 * standard slate that publishes itself when a commissioner loses track of
 * Tuesday.
 */

beforeEach(function () {
    $this->travelTo('2026-09-01 12:00:00');
});

// ------------------------------------------------------------- the window

it('holds the slate window to Saturday noon-to-midnight Eastern', function () {
    [$season, $week] = pickemSeasonWeek();

    // 19:30 UTC is 3:30 PM ET — squarely inside.
    expect(pickemGame($season, $week)->inSlateWindow())->toBeTrue();

    // Noon ET exactly (16:00 UTC in September) — "on/after noon" holds.
    expect(pickemGame($season, $week, ['kickoff_at' => '2026-09-05 16:00:00'])->inSlateWindow())->toBeTrue();

    // A Dublin-style breakfast kickoff: Saturday, but 9:30 AM ET — out.
    expect(pickemGame($season, $week, ['kickoff_at' => '2026-09-05 13:30:00'])->inSlateWindow())->toBeFalse();

    // Friday night — out, whatever the hour.
    expect(pickemGame($season, $week, ['kickoff_at' => '2026-09-04 23:30:00'])->inSlateWindow())->toBeFalse();
});

it('refuses a board carrying a pre-noon Saturday game', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);

    $slate->games()->first()->game->update(['kickoff_at' => '2026-09-05 13:30:00']);

    expect($contest->mode->engine()->validateForPublish($slate->fresh()))
        ->toContain('picks.publish.not_saturday');
});

// -------------------------------------------------------------- the clock

it('reads the shipped clock: Thursday noon in, Sunday noon out', function () {
    /*
     * The founders' cycle, adopted app-wide 2026-08-20: the week turns over
     * Tuesday, the card is available by Thursday, results go official
     * Sunday. Moved here from a Tuesday end-of-day publish deadline.
     */
    [, $week] = pickemSeasonWeek();
    $tz = config('cfb.timezone');

    // The fixture week's Saturday is 2026-09-05.
    expect(Cadence::saturdayOf($week)->toDateString())->toBe('2026-09-05')
        ->and(Cadence::slateDeadline($week)->timezone($tz)->format('D Y-m-d H:i:s'))->toBe('Thu 2026-09-03 12:00:00')
        ->and(Cadence::officialFinal($week)->timezone($tz)->format('D Y-m-d H:i:s'))->toBe('Sun 2026-09-06 12:00:00');
});

it('turns the pick\'em week over on Tuesday, not at the weekend', function () {
    $tz = config('cfb.timezone');
    $at = fn (string $utc) => Cadence::currentSaturday(CarbonImmutable::parse($utc))->toDateString();

    // Sunday's results and Monday's arguing still belong to the Saturday
    // just played — 16:00 UTC is noon ET, safely inside the day either way.
    expect($at('2026-09-06 16:00:00'))->toBe('2026-09-05')
        ->and($at('2026-09-07 16:00:00'))->toBe('2026-09-05')
        // Tuesday moves on to the next card.
        ->and($at('2026-09-08 16:00:00'))->toBe('2026-09-12')
        ->and($at('2026-09-10 16:00:00'))->toBe('2026-09-12')
        ->and($at('2026-09-12 16:00:00'))->toBe('2026-09-12');

    // The boundary is EASTERN. 02:00 UTC Tuesday is still Monday night in
    // Knoxville, so the week has not turned yet.
    expect(CarbonImmutable::parse('2026-09-08 02:00:00')->timezone($tz)->dayOfWeek)->toBe(CarbonImmutable::MONDAY);
    expect($at('2026-09-08 02:00:00'))->toBe('2026-09-05');
});

it('finds BOTH Saturdays in a split ESPN week, and picks the busier as primary', function () {
    /*
     * The defect this whole change exists for. ESPN's 2026 Week 1 runs
     * 8/22 → 9/8 and holds games on 8/29 and 9/5 — and NONE on the 8/22 its
     * range opens with. The old saturdayOf() walked the range and took the
     * first calendar Saturday, so Week 1's deadline resolved to a Tuesday
     * already in the past and its official-final to a Sunday before a
     * single game had been played.
     */
    $season = Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);
    $week = Week::factory()->create([
        'season_id' => $season->id,
        'number' => 1,
        'start_date' => '2026-08-22 04:00:00',
        'end_date' => '2026-09-08 03:59:59',
    ]);

    // Seven on the 29th, sixty-eight on the 5th — the real shape of it.
    foreach (range(1, 7) as $i) {
        pickemGame($season, $week, ['kickoff_at' => '2026-08-29 20:00:00']);
    }

    foreach (range(1, 12) as $i) {
        pickemGame($season, $week, ['kickoff_at' => '2026-09-05 19:30:00']);
    }

    expect(collect(Cadence::saturdaysIn($week))->map->toDateString()->all())
        ->toBe(['2026-08-29', '2026-09-05']);

    // Primary is the main card, never the first date on the calendar.
    expect(Cadence::saturdayOf($week)->toDateString())->toBe('2026-09-05');

    // And each Saturday carries its OWN clock, resolved from the date.
    $tz = config('cfb.timezone');

    expect(Cadence::slateDeadline(CarbonImmutable::parse('2026-08-29'))->timezone($tz)->format('D Y-m-d H:i'))
        ->toBe('Thu 2026-08-27 12:00')
        ->and(Cadence::officialFinal(CarbonImmutable::parse('2026-08-29'))->timezone($tz)->format('D Y-m-d H:i'))
        ->toBe('Sun 2026-08-30 12:00');
});

it('lets the admin panel move the clock', function () {
    PickemSetting::current()->update([
        'slate_deadline_dow' => 3,
        'slate_deadline_time' => '17:00:00',
        'official_final_dow' => 1,
        'official_final_time' => '09:00:00',
    ]);

    [, $week] = pickemSeasonWeek();
    $tz = config('cfb.timezone');

    expect(Cadence::slateDeadline($week)->timezone($tz)->format('D Y-m-d H:i'))->toBe('Wed 2026-09-02 17:00')
        ->and(Cadence::officialFinal($week)->timezone($tz)->format('D Y-m-d H:i'))->toBe('Mon 2026-09-07 09:00');
});

it('keeps the clock in Eastern across the DST boundary', function () {
    [$season] = pickemSeasonWeek();
    // A November week: EST, not EDT — the ET wall time must not drift.
    $november = Week::factory()->create([
        'season_id' => $season->id,
        'number' => 11,
        // ET midnights, stored as the UTC instants they are.
        'start_date' => '2026-11-01 05:00:00',
        'end_date' => '2026-11-08 04:59:59',
    ]);

    $deadline = Cadence::slateDeadline($november)->timezone(config('cfb.timezone'));

    // No games synced for this week, so saturdayOf falls back to walking the
    // date range — the scheduled-but-unplayed path, which is a real state.
    expect(Cadence::saturdayOf($november)->toDateString())->toBe('2026-11-07')
        ->and($deadline->format('D H:i:s'))->toBe('Thu 12:00:00')
        // EST, not EDT: noon is 17:00 UTC rather than 16:00.
        ->and($deadline->utcOffset())->toBe(-300);
});

it('serves the settings page to admins only', function () {
    $this->actingAs(pickemAdmin())->get('/admin/pickem-settings')->assertOk();
    $this->actingAs(User::factory()->create())->get('/admin/pickem-settings')->assertForbidden();
});

// -------------------------------------------------- the standard slate

it('publishes the standard slate when the commissioner missed the deadline', function () {
    [, , $contest] = pickemContest();
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 12) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game, ['spread' => $i % 2 === 0 ? -7.0 : -6.5]);
        $game->predictor()->create(['matchup_quality' => 90 - $i]);
    }

    // Before the deadline the sweep must not touch the week. Wednesday now,
    // not Tuesday — the commissioner has until Thursday noon.
    $this->travelTo('2026-09-02 12:00:00');
    $this->artisan('pickem:publish-boards')->assertSuccessful();
    expect(Slate::query()->count())->toBe(0);

    // Past Thursday NOON Eastern — travelTo speaks UTC, and 16:00 UTC is
    // noon in Knoxville, so 17:00 is an hour past the deadline.
    $this->travelTo('2026-09-03 17:00:00');
    $this->artisan('pickem:publish-boards')->assertSuccessful();

    $slate = Slate::query()->where('contest_id', $contest->id)->sole();
    expect($slate->status)->toBe(Slate::PUBLISHED)
        ->and($slate->games()->count())->toBe(10)
        ->and($slate->tiebreaker_metric)->toBe(TiebreakerMetric::CombinedPoints)
        ->and($slate->tiebreaker_slate_game_id)->not->toBeNull();

    // Every standard line obeys the half-point law, whole-number books included.
    $slate->games->each(fn ($slateGame) => expect(ContestLine::isHalfPoint((float) $slateGame->spread))->toBeTrue());

    // Idempotent: the next hour's run has nothing to do.
    $this->artisan('pickem:publish-boards')->assertSuccessful();
    expect(Slate::query()->count())->toBe(1);
});

it('replaces a stale partial draft but never a published board', function () {
    [$commissioner, , $contest] = pickemContest();
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 11) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 80 - $i]);
    }

    // The commissioner started a draft and wandered off: two games, no more.
    $draft = Slate::factory()->create(['contest_id' => $contest->id, 'week_id' => $week->id]);
    $orphan = pickemGame($season, $week);
    pickemOdd($orphan);
    $draft->games()->create(['game_id' => $orphan->id, 'position' => 1, 'spread' => -6.5, 'favorite_team_id' => $orphan->home_team_id]);

    $published = app(AutoPublishStandardSlate::class)->handle($contest, $week);
    expect($published)->not->toBeNull()
        ->and($published->games()->count())->toBe(10);

    // A published board is beyond the fallback's reach.
    expect(app(AutoPublishStandardSlate::class)->handle($contest, $week))->toBeNull();
});
