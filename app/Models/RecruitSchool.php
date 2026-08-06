<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One school's interest in one prospect.
 *
 * ESPN ships this inside the recruiting payload as `schools[]` and names the
 * date `visit`, which oversells it: only 659 of 10,472 entries in the 2026
 * class have one. Most rows are "this school was in the running", carrying a
 * status and nothing else.
 *
 * `team_id` is nullable on purpose — an interest list that silently dropped
 * every school we do not carry would misreport who was in on a recruit. Which
 * is why `espn_team_id` exists and is the upsert key: MySQL never matches NULL
 * to NULL in a unique index, so keying on the nullable column re-inserted those
 * rows on every weekly run.
 */
#[Fillable(['recruit_id', 'espn_team_id', 'team_id', 'status', 'visited_on'])]
class RecruitSchool extends Model
{
    protected function casts(): array
    {
        return ['visited_on' => 'date'];
    }

    public function recruit(): BelongsTo
    {
        return $this->belongsTo(Recruit::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
