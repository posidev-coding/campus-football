<?php

use App\Actions\AutoPublishStandardSlate;
use App\Enums\TiebreakerMetric;
use App\Models\PickemSetting;
use App\Models\Slate;
use App\Models\User;
use App\Models\Week;
use App\Services\Contests\ContestLine;
use App\Support\Cadence;

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

it('reads the shipped clock: Tuesday end-of-day in, Sunday noon out', function () {
    [, $week] = pickemSeasonWeek();
    $tz = config('cfb.timezone');

    // The fixture week's Saturday is 2026-09-05.
    expect(Cadence::saturdayOf($week)->toDateString())->toBe('2026-09-05')
        ->and(Cadence::slateDeadline($week)->timezone($tz)->format('D Y-m-d H:i:s'))->toBe('Tue 2026-09-01 23:59:59')
        ->and(Cadence::officialFinal($week)->timezone($tz)->format('D Y-m-d H:i:s'))->toBe('Sun 2026-09-06 12:00:00');
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

    expect(Cadence::saturdayOf($november)->toDateString())->toBe('2026-11-07')
        ->and($deadline->format('D H:i:s'))->toBe('Tue 23:59:59')
        // 23:59 EST is 04:59 UTC — one hour later than an EDT evening.
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

    // Before the deadline the sweep must not touch the week.
    $this->travelTo('2026-09-01 12:00:00');
    $this->artisan('pickem:publish-boards')->assertSuccessful();
    expect(Slate::query()->count())->toBe(0);

    // Past Tuesday midnight EASTERN — travelTo speaks UTC, and 01:00 UTC
    // is still Tuesday evening in Knoxville. 06:00 UTC is 2 AM Wednesday ET.
    $this->travelTo('2026-09-02 06:00:00');
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
