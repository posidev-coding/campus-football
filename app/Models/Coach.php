<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'first_name', 'last_name', 'display_name'])]
class Coach extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    public function seasons(): HasMany
    {
        return $this->hasMany(CoachTeamSeason::class);
    }
}
