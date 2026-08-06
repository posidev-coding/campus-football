<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['year', 'type', 'name', 'start_date', 'end_date'])]
class Season extends Model
{
    use HasFactory;

    /** ESPN season types. */
    public const PRESEASON = 1;

    public const REGULAR = 2;

    public const POSTSEASON = 3;

    public const OFFSEASON = 4;

    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(Week::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
