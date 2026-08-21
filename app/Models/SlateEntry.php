<?php

namespace App\Models;

use Database\Factories\SlateEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's participation in one weekly slate, created on their first
 * pick. Holds the tiebreaker prediction; totals and ranks are deliberately
 * NOT stored — leaderboards are SUMs over picks, materialized only behind a
 * measurement.
 *
 * `tiebreaker_total` null means never entered, and at settlement a null
 * loses to any non-null prediction — never substituted with a 0 that would
 * read as an accidentally strong guess.
 *
 * `beat_bear` is the settlement verdict of the Woodshed's strict
 * comparison — null when the slate had no Bear (every non-Woodshed week),
 * true/false once the week went official. `final_points` includes the +5
 * when it landed, and can be NEGATIVE: a backfired Lock is a real result.
 */
#[Fillable(['slate_id', 'user_id', 'tiebreaker_total', 'final_points', 'won', 'beat_bear'])]
class SlateEntry extends Model
{
    /** @use HasFactory<SlateEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tiebreaker_total' => 'integer',
            'final_points' => 'integer',
            'won' => 'boolean',
            'beat_bear' => 'boolean',
        ];
    }

    public function slate(): BelongsTo
    {
        return $this->belongsTo(Slate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
