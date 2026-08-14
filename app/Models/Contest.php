<?php

namespace App\Models;

use App\Enums\ContestMode;
use Database\Factories\ContestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One group's season-long game. A group fields exactly ONE contest per
 * season (the unique index holds it) — the mode is chosen at creation and
 * changed at most once, deliberately, with the group told.
 *
 * `season_year` is the YEAR, never a season_id — a CFB year spans several
 * `seasons` rows and a contest slating a postseason Saturday would cross
 * ids mid-run. Written from CfbCalendar at creation, never from config.
 *
 * `settings` is null for "mode defaults" — never an empty object standing
 * in for one. It is also The Woodshed's landing pad: the founders' rules
 * arrive as engine code reading these knobs, zero schema churn.
 *
 * `mode_changed_at` null means the group has never pivoted — the
 * once-per-season rule is ChangeGroupMode's whereNull-style guard on this
 * stamp, never a counter that can drift.
 */
#[Fillable(['group_id', 'season_year', 'mode', 'settings', 'mode_changed_at'])]
class Contest extends Model
{
    /** @use HasFactory<ContestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'season_year' => 'integer',
            'mode' => ContestMode::class,
            'settings' => 'array',
            'mode_changed_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function slates(): HasMany
    {
        return $this->hasMany(Slate::class);
    }
}
