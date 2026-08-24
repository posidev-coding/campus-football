<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day's count of one funnel signal — the persisted rollup, never the
 * event itself. See the migration for why there is no row per event, and
 * App\Enums\UxSignal for the vocabulary.
 */
#[Fillable(['day', 'signal', 'count'])]
class UxEvent extends Model
{
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'count' => 'integer',
        ];
    }
}
