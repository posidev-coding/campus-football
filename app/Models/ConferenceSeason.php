<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conference_id', 'season_year', 'parent_group_id', 'classification'])]
class ConferenceSeason extends Model
{
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }
}
