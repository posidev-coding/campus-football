<?php

namespace App\Models;

use App\Support\Cadence;
use Illuminate\Database\Eloquent\Model;

/**
 * The one row of league-clock overrides. Null columns resolve to the
 * shipped defaults on App\Support\Cadence — a blank row IS the default
 * cadence, so creating it eagerly costs nothing and Reset is nulling
 * columns.
 */
class PickemSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'slate_deadline_dow' => 'integer',
            'official_final_dow' => 'integer',
            'lobby_member_cap' => 'integer',
            // The first Saturday whose slates count. Null is NO practice
            // window, not a missing value — see the migration.
            'counts_from' => 'immutable_date',
            // Whether the practice window reaches PUBLIC ROOMS as well as
            // private groups. False is the shipped decision, not a blank.
            'practice_includes_rooms' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /** Seats in a public room — the admin's number, or the shipped default. */
    public static function lobbyMemberCap(): int
    {
        return static::current()->lobby_member_cap ?? Group::DEFAULT_LOBBY_CAP;
    }

    protected static function booted(): void
    {
        // Cadence memoizes the row statically; an edit must not serve the
        // old clock for the rest of the request that made it.
        static::saved(fn () => Cadence::flush());
    }
}
