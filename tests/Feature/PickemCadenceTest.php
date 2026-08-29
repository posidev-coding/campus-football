<?php

use App\Actions\AutoPublishStandardSlate;
use App\Actions\PublishSlate;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Enums\TiebreakerMetric;
use App\Models\Game;
use App\Models\PickemSetting;
use App\Models\Season;
use App\Models\Slate;
use App\Models\User;
use App\Models\Week;
use App\Services\Contests\ContestLine;
use App\Support\Cadence;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

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

it('refuses a slate carrying a pre-noon Saturday game', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftSlate($contest);

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

it('numbers the split week the way fans do: the first card is Week 0', function () {
    [, $week] = splitPickemWeek();
    $tz = config('cfb.timezone');

    // The boundary is the Tuesday turnover before the MAIN card, ET
    // midnight — the same clock the whole league turns over on.
    expect(Cadence::splitBoundary($week)->timezone($tz)->format('D Y-m-d H:i'))->toBe('Tue 2026-09-01 00:00');

    // By date string (how slates.saturday arrives), by Carbon, by default.
    expect(Cadence::displayWeekNumber($week, '2026-08-29'))->toBe(0)
        ->and(Cadence::displayWeekNumber($week, '2026-09-05'))->toBe(1)
        ->and(Cadence::displayWeekNumber($week, CarbonImmutable::parse('2026-08-29', $tz)))->toBe(0)
        // No Saturday named means the week's primary card.
        ->and(Cadence::displayWeekNumber($week))->toBe(1)
        ->and(Cadence::displayWeekLabel($week, '2026-08-29'))->toBe('Week 0');
});

it('sells the cards in order: the lobby never skips ahead of an unplayed Saturday', function () {
    /*
     * Thursday 8/20: the pick'em clock's "current Saturday" is the empty
     * 8/22, which is nobody's card. The lobby's next card is 8/29 —
     * falling back to the week's BUSIEST Saturday would sell 9/5 rooms
     * for five days and then flip BACK to 8/29 at the Tuesday turnover,
     * cards out of order.
     */
    $this->travelTo('2026-08-20 16:00:00');

    [, $week] = splitPickemWeek();

    expect(Cadence::activeSaturday($week)->toDateString())->toBe('2026-08-29');

    // At the turnover the current Saturday IS the card; nothing changes.
    $this->travelTo('2026-08-26 16:00:00');
    Cadence::flush();
    expect(Cadence::activeSaturday($week)->toDateString())->toBe('2026-08-29');

    // And the Tuesday after the first card, the lobby moves on.
    $this->travelTo('2026-09-01 16:00:00');
    Cadence::flush();
    expect(Cadence::activeSaturday($week)->toDateString())->toBe('2026-09-05');
});

it('answers the deadline for the card being played, not the week\'s busiest', function () {
    /*
     * The split-week trap, on the surface that shows it. Passing the WEEK
     * resolves through saturdayOf() — the busiest card, 9/5 — so on the 8/29
     * Saturday every group was shown a deadline a week late, on the one
     * rehearsal Saturday before launch. The slate's own Saturday is the
     * answer, matching what the settle sweep already reads.
     */
    $this->travelTo('2026-08-26 16:00:00');

    [, $week] = splitPickemWeek();

    $weekForm = Cadence::slateDeadline($week);
    $cardForm = Cadence::slateDeadline(Cadence::activeSaturday($week));

    // Thursday noon before each card: 8/27 for the card in front, 9/3 for
    // the one behind it. They are a week apart, which is the whole bug.
    expect($cardForm->toDateString())->toBe('2026-08-27')
        ->and($weekForm->toDateString())->toBe('2026-09-03')
        ->and($cardForm->toDateString())->not->toBe($weekForm->toDateString());
});

it('leaves every ordinary week numbered as ESPN numbers it', function () {
    [$season, $week] = pickemSeasonWeek();
    pickemGame($season, $week);

    // One Saturday: no boundary, no renumbering.
    expect(Cadence::splitBoundary($week))->toBeNull()
        ->and(Cadence::displayWeekNumber($week))->toBe(1);

    // And a later week never splits, however many Saturdays it spans —
    // only the season's opening week folds a Week 0 in. TWO Saturdays of
    // games here, so the NUMBER guard is the only thing holding the line.
    $five = Week::factory()->create(['season_id' => $season->id, 'number' => 5, 'name' => 'Week 5']);
    pickemGame($season, $five, ['kickoff_at' => '2026-10-03 19:30:00']);
    pickemGame($season, $five, ['kickoff_at' => '2026-10-10 19:30:00']);

    expect(Cadence::splitBoundary($five))->toBeNull()
        ->and(Cadence::displayWeekNumber($five))->toBe(5);
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
    $this->artisan('pickem:publish-slates')->assertSuccessful();
    expect(Slate::query()->count())->toBe(0);

    // Past Thursday NOON Eastern — travelTo speaks UTC, and 16:00 UTC is
    // noon in Knoxville, so 17:00 is an hour past the deadline.
    $this->travelTo('2026-09-03 17:00:00');
    $this->artisan('pickem:publish-slates')->assertSuccessful();

    $slate = Slate::query()->where('contest_id', $contest->id)->sole();
    expect($slate->status)->toBe(Slate::PUBLISHED)
        ->and($slate->games()->count())->toBe(10)
        ->and($slate->tiebreaker_metric)->toBe(TiebreakerMetric::CombinedPoints)
        ->and($slate->tiebreaker_slate_game_id)->not->toBeNull();

    // Every standard line obeys the half-point law, whole-number books included.
    $slate->games->each(fn ($slateGame) => expect(ContestLine::isHalfPoint((float) $slateGame->spread))->toBeTrue());

    // Idempotent: the next hour's run has nothing to do.
    $this->artisan('pickem:publish-slates')->assertSuccessful();
    expect(Slate::query()->count())->toBe(1);
});

it('never sweeps a slate into a house room — lobby slates are born at spawn', function () {
    // A Week 0 house room, its contest frozen for the seven-game card.
    $this->travelTo('2026-08-26 12:00:00');
    [, $week] = splitPickemWeek();

    foreach (Game::query()->where('week_id', $week->id)->get() as $game) {
        pickemOdd($game);
    }

    $room = app(SpawnPublicContest::class)->handle(
        ContestMode::Classic, $week, CarbonImmutable::parse('2026-08-29', config('cfb.timezone')),
    );
    $contest = $room->contests()->sole();

    expect(Slate::query()->where('contest_id', $contest->id)->count())->toBe(1);

    /*
     * Past the MAIN card's deadline. The sweep exists for private
     * commissioners who overslept — a dead Week 0 house room must never
     * receive a second slate, least of all one sized by its frozen seven
     * on a twelve-game Saturday.
     */
    $this->travelTo('2026-09-03 17:00:00');
    $this->artisan('pickem:publish-slates')->assertSuccessful();

    expect(Slate::query()->where('contest_id', $contest->id)->count())->toBe(1);
});

it('replaces a stale partial draft but never a published slate', function () {
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

    // A published slate is beyond the fallback's reach.
    expect(app(AutoPublishStandardSlate::class)->handle($contest, $week))->toBeNull();
});

// ------------------------------------------------------ the practice window

it('publishes a Saturday inside the practice window as an exhibition', function () {
    /*
     * The launch's rehearsal weeks. `slates.exhibition` has meant "real
     * picks, real grading, real XP, no season credit" since the Saturday
     * anchor landed, and nothing ever wrote it — so a launch that meant
     * to rehearse counted every week anyway.
     */
    PickemSetting::current()->update(['counts_from' => '2026-09-12']);

    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftSlate($contest);

    expect(app(PublishSlate::class)->handle($commissioner, $slate))->toBe([]);

    // The fixture Saturday is 9/5 — a week inside the window.
    expect($slate->fresh()->exhibition)->toBeTrue()
        ->and($slate->fresh()->counts())->toBeFalse();
});

it('counts the Saturday counting starts ON, and every Saturday after it', function () {
    // The boundary is INCLUSIVE: "counting starts 9/5" means 9/5 counts.
    PickemSetting::current()->update(['counts_from' => '2026-09-05']);

    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftSlate($contest);

    app(PublishSlate::class)->handle($commissioner, $slate);

    expect($slate->fresh()->exhibition)->toBeFalse()
        ->and($slate->fresh()->counts())->toBeTrue();
});

it('counts everything when no practice window is configured', function () {
    /*
     * Null is NO WINDOW, never a missing value quietly discounting a
     * week: an unconfigured league counts every slate, which is the
     * honest state of every season after a launch one.
     */
    expect(Cadence::countsFrom())->toBeNull()
        ->and(Cadence::isPractice(CarbonImmutable::parse('2026-09-05')))->toBeFalse();

    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftSlate($contest);

    app(PublishSlate::class)->handle($commissioner, $slate);

    expect($slate->fresh()->exhibition)->toBeFalse();
});

it('stamps practice on every publish door, the house rooms included', function () {
    /*
     * The stamp lives in PublishSlate::force(), which is the ONE door
     * every publish comes through — the commissioner's button, the
     * deadline fallback, and a room's own spawn. A room publishing real
     * results through the rehearsal weekend is exactly the failure this
     * pins.
     */
    PickemSetting::current()->update(['counts_from' => '2026-09-12']);

    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 12) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 90 - $i]);
    }

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $roomSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $room->id))->sole();

    expect($roomSlate->status)->toBe(Slate::PUBLISHED)
        ->and($roomSlate->exhibition)->toBeTrue();

    // And the commissioner who overslept gets the same answer.
    [, , $contest] = pickemContest();
    $auto = app(AutoPublishStandardSlate::class)->handle($contest, $week);

    expect($auto)->not->toBeNull()
        ->and($auto->exhibition)->toBeTrue();
});

// ------------------------------------------------ one slate, one Saturday

it('reads the slate for the card being played, and both screens agree on it', function () {
    /*
     * ONE SLATE, ONE SATURDAY — the read half. 2026's Week 1 holds 8/29
     * and 9/5, and `slates` is unique on (contest_id, saturday), so a
     * group that carried a Week 0 draft legitimately owns TWO rows inside
     * one ESPN week.
     *
     * Both screens filtered on `week_id` alone and took a different one:
     * the clubhouse's ->first() returned the older row (the stale draft,
     * lower id) while My Picks' keyBy() kept the last (the published
     * card). On launch Saturday that group's clubhouse would have said
     * "no slate yet" while its card on My Picks showed a live one.
     *
     * The draft is created FIRST on purpose: the lower id is exactly what
     * ->first() used to hand back.
     */
    [$commissioner, $group, $contest] = pickemContest();
    [, $week] = splitPickemWeek();

    $stale = Slate::factory()->create([
        'contest_id' => $contest->id,
        'week_id' => $week->id,
        'saturday' => '2026-08-29',
        'status' => Slate::DRAFT,
    ]);

    $playing = pickemDraftSlate($contest);
    expect(app(PublishSlate::class)->handle($commissioner, $playing))->toBe([]);

    // The clock is on 9/5 — the card in front of everybody.
    expect(Cadence::activeSaturday($week)?->toDateString())->toBe('2026-09-05')
        ->and($stale->id)->toBeLessThan($playing->id);

    $clubhouse = Livewire::actingAs($commissioner)->test('group', ['group' => $group])->instance();

    expect($clubhouse->slate?->id)->toBe($playing->id)
        ->and($clubhouse->slate?->status)->toBe(Slate::PUBLISHED);

    $card = Livewire::actingAs($commissioner)->test('pickem-home')
        ->instance()->cards->firstWhere('contest.id', $contest->id);

    // The same card, from the other screen: a published ten-game slate to
    // pick, never the empty draft's 'waiting'.
    expect($card['state'])->toBe('upcoming')
        ->and($card['total'])->toBe(10);
});
