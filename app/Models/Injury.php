<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['athlete_id', 'team_id', 'status', 'type', 'detail', 'side', 'return_date', 'reported_at'])]
class Injury extends Model
{
    protected function casts(): array
    {
        return ['return_date' => 'date', 'reported_at' => 'datetime'];
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
