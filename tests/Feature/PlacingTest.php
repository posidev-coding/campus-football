<?php

use App\Support\Placing;

/*
 * Where one reader stands in a field — the arithmetic both My Picks and the
 * clubhouse read, so that a place said on two screens is one calculation
 * said twice rather than two calculations agreeing by luck.
 */

it('places a reader by who is strictly ahead, not by where a sort put them', function () {
    // Three totals, no ties: the place is the row number and that is fine.
    expect(Placing::of(20, [30, 20, 10]))
        ->toBe(['place' => 2, 'field' => 3, 'tied' => false]);

    expect(Placing::of(30, [30, 20, 10])['place'])->toBe(1)
        ->and(Placing::of(10, [30, 20, 10])['place'])->toBe(3);
});

it('shares a place rather than picking a winner out of a tie', function () {
    /*
     * THE REASON THIS CLASS EXISTS. weekStandings sorts and then numbers
     * 1..N, so two members on 20 come out 2 and 3 with the order between
     * them decided by whatever the sort did. Telling the second of them they
     * are 3rd names a place nobody holds.
     */
    $field = [30, 20, 20, 10];

    expect(Placing::of(20, $field))
        ->toBe(['place' => 2, 'field' => 4, 'tied' => true]);

    // ...and the place below skips the way a competition rank does: two
    // people occupy 2nd, so the next one down is 4th, never 3rd.
    expect(Placing::of(10, $field)['place'])->toBe(4);
});

it('counts the reader in their own field', function () {
    // The field is the standings column, not the other people in it. A
    // leader passed a field that excluded them would read "1st of 2" in a
    // room of three.
    expect(Placing::of(30, [30, 20, 10])['field'])->toBe(3);
});

it('refuses to place a field too small to be a standing', function () {
    // A field of one is not a standing, it is a person, and "1st of 1" is a
    // trophy for turning up. Null is no place; callers skip.
    expect(Placing::of(10, [10]))->toBeNull()
        ->and(Placing::of(0, []))->toBeNull();
});

it('labels a place with its field, and says out loud when it is shared', function () {
    expect(Placing::label(['place' => 2, 'field' => 10, 'tied' => false]))->toBe('2nd of 10')
        ->and(Placing::label(['place' => 2, 'field' => 10, 'tied' => true]))->toBe('T-2nd of 10')
        // The ordinal is Ordinal's, so 11th, 12th and 13th behave.
        ->and(Placing::label(['place' => 11, 'field' => 20, 'tied' => false]))->toBe('11th of 20')
        ->and(Placing::label(['place' => 1, 'field' => 3, 'tied' => false]))->toBe('1st of 3');
});

it('shortens to the place alone where a column has no width for the field', function () {
    expect(Placing::short(['place' => 3, 'field' => 9, 'tied' => false]))->toBe('3rd')
        ->and(Placing::short(['place' => 3, 'field' => 9, 'tied' => true]))->toBe('T-3rd');
});
