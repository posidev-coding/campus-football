<?php

use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Support\WeekTrends;
use Illuminate\Support\Facades\DB;

/*
 * CFB-30: a member's recent weeks as places, and whether each was the top
 * half of the room. Top half rather than the weekly win, because `won`
 * crowns one person a slate and would read as five losses for nearly
 * everybody; rather than beat_bear, because only a Woodshed slate fields a
 * Bear.
 */

/**
 * One settled week of the contest, with each user's points.
 *
 * @param  array<int, int>  $points  user id => final points
 */
function trendWeek(Contest $contest, string $saturday, array $points, array $slate = []): Slate
{
    [, $week] = pickemSeasonWeek();

    $row = Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id, 'saturday' => $saturday,
        'status' => Slate::SETTLED, 'settled_at' => now(), ...$slate,
    ]);

    foreach ($points as $userId => $total) {
        SlateEntry::factory()->create(['slate_id' => $row->id, 'user_id' => $userId, 'final_points' => $total]);
    }

    return $row;
}

/** @return list<User> */
function trendPeople(int $count): array
{
    return User::factory()->count($count)->create()->all();
}

it('calls a week top half when the place is inside half the field', function () {
    [, , $contest] = pickemContest();
    [$a, $b, $c, $d, $e] = trendPeople(5);

    trendWeek($contest, '2026-09-05', [$a->id => 20, $b->id => 15, $c->id => 10, $d->id => 5]);
    trendWeek($contest, '2026-09-12', [$a->id => 20, $b->id => 15, $c->id => 10, $d->id => 5, $e->id => 1]);

    $trends = WeekTrends::for($contest);
    $half = fn (User $user, int $week): bool => $trends[$user->id][$week]['top_half'];

    // Four: 1st and 2nd are the top half, 3rd is not.
    expect($half($a, 0))->toBeTrue()
        ->and($half($b, 0))->toBeTrue()
        ->and($half($c, 0))->toBeFalse()
        // Five: the middle place is not the top half, because 3 of 5 has
        // two people above it and two below.
        ->and($half($b, 1))->toBeTrue()
        ->and($half($c, 1))->toBeFalse()
        ->and($trends[$c->id][1])->toMatchArray(['place' => 3, 'field' => 5, 'tied' => false]);
});

it('shares a place the way the week band does', function () {
    [, , $contest] = pickemContest();
    [$a, $b, $c, $d] = trendPeople(4);

    trendWeek($contest, '2026-09-05', [$a->id => 10, $b->id => 8, $c->id => 8, $d->id => 5]);

    $trends = WeekTrends::for($contest);

    // Competition rank: nobody is ahead of the second of two on 8, so both
    // hold 2nd of 4, which is the top half.
    expect($trends[$c->id][0])->toMatchArray(['place' => 2, 'tied' => true, 'top_half' => true])
        ->and($trends[$b->id][0]['place'])->toBe(2)
        ->and($trends[$d->id][0])->toMatchArray(['place' => 4, 'top_half' => false]);
});

it('leaves out a week with nobody to place against, rather than calling it a loss', function () {
    // "1st of 1" is a trophy for turning up, and a red pill for the same
    // week would be a verdict on nobody. Placing::of() says null; the run
    // skips it.
    [, , $contest] = pickemContest();
    [$solo, $other] = trendPeople(2);

    trendWeek($contest, '2026-09-05', [$solo->id => 12]);
    trendWeek($contest, '2026-09-12', [$solo->id => 12, $other->id => 3]);

    $run = WeekTrends::for($contest)[$solo->id];

    expect($run)->toHaveCount(1)
        ->and($run[0]['saturday'])->toBe('2026-09-12');
});

it('keeps practice weeks, open weeks and other contests off the run', function () {
    [, , $contest] = pickemContest();
    [, , $elsewhere] = pickemContest(ContestMode::Tiered);
    [$a, $b] = trendPeople(2);

    trendWeek($contest, '2026-08-29', [$a->id => 9, $b->id => 1], ['exhibition' => true]);
    trendWeek($contest, '2026-09-05', [$a->id => 9, $b->id => 1]);
    trendWeek($contest, '2026-09-12', [$a->id => 9, $b->id => 1], ['status' => Slate::PUBLISHED, 'settled_at' => null]);
    trendWeek($elsewhere, '2026-09-19', [$a->id => 9, $b->id => 1]);

    expect(array_column(WeekTrends::for($contest)[$a->id], 'saturday'))->toBe(['2026-09-05']);
});

it('keeps the last five weeks, oldest first', function () {
    [, , $contest] = pickemContest();
    [$a, $b] = trendPeople(2);

    foreach (['2026-08-29', '2026-09-05', '2026-09-12', '2026-09-19', '2026-09-26', '2026-10-03'] as $saturday) {
        trendWeek($contest, $saturday, [$a->id => 9, $b->id => 1]);
    }

    expect(array_column(WeekTrends::for($contest)[$a->id], 'saturday'))
        ->toBe(['2026-09-05', '2026-09-12', '2026-09-19', '2026-09-26', '2026-10-03']);
});

it('reads the whole contest in one query, however many members it has', function () {
    [, , $contest] = pickemContest();
    $people = trendPeople(12);

    foreach (['2026-09-05', '2026-09-12', '2026-09-19'] as $saturday) {
        trendWeek($contest, $saturday, collect($people)->mapWithKeys(fn (User $user, int $i) => [$user->id => $i])->all());
    }

    DB::enableQueryLog();
    $trends = WeekTrends::for($contest);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1)
        ->and($trends)->toHaveCount(12);
});
