<?php

use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Support\RoomNames;

/*
 * The lobby's names: pools with personality per mode, marquees per
 * flavor, Roman numerals when a lobby exhausts either — and the hard
 * constraints (a VARCHAR(40) column, PG-safe data-not-Voice copy, never
 * a school) swept in one place.
 */

it('deals deterministic names from each mode pool, in order', function () {
    expect(RoomNames::next(ContestMode::Classic, collect()))->toBe('Hail Mary')
        ->and(RoomNames::next(ContestMode::Tiered, collect()))->toBe('Wishbone')
        ->and(RoomNames::next(ContestMode::Woodshed, collect()))->toBe('The Splinter')
        ->and(RoomNames::next(ContestMode::Classic, collect(['Hail Mary'])))->toBe('Flea Flicker');
});

it('cycles a spent pool with Roman numerals instead of running dry', function () {
    $taken = collect(RoomNames::POOLS['classic']);

    expect(RoomNames::next(ContestMode::Classic, $taken))->toBe('Hail Mary II')
        ->and(RoomNames::next(ContestMode::Classic, $taken->push('Hail Mary II')))->toBe('Flea Flicker II');
});

it('names a specialty by its marquee and numbers its successors', function () {
    expect(RoomNames::successor('Ranked Action', collect()))->toBe('Ranked Action')
        ->and(RoomNames::successor('Ranked Action', collect(['Ranked Action'])))->toBe('Ranked Action II')
        ->and(RoomNames::successor('Ranked Action', collect(['Ranked Action', 'Ranked Action II'])))->toBe('Ranked Action III');
});

it('keeps every name inside the column with numeral headroom, and off the schools', function () {
    $names = collect(RoomNames::POOLS)->flatten()
        ->merge(collect(LobbyFlavor::cases())->map(fn (LobbyFlavor $flavor) => $flavor->label()));

    // groups.name is VARCHAR(40); ' XXVIII' is a generous numeral tail.
    foreach ($names as $name) {
        expect(strlen($name.' XXVIII'))->toBeLessThanOrEqual(40);
    }

    // Names are DATA, not Voice — identical in every register, so they
    // must read PG-safe, and a school name is somebody's rival. Georgia
    // specifically never appears: the pilot room is Tennessee alumni.
    expect($names->join(' '))->not->toContain('Georgia');
});
