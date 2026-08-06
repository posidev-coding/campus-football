<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['id', 'name', 'abbreviation', 'slug', 'parent_id'])]
class Position extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * The position as a heading — "Quarterbacks", "Offensive Linemen".
     *
     * `Str::plural` handles every real name ESPN publishes, including the
     * irregular one (Lineman -> Linemen). Acronyms are left alone: EDGE would
     * otherwise become "EDGES", and a name that is just its own abbreviation
     * belongs to a legacy row that `descriptive()` exists to avoid picking.
     */
    public function pluralName(): string
    {
        $name = (string) $this->name;

        if ($name === '' || $name === Str::upper($name)) {
            return $name;
        }

        return Str::plural($name);
    }

    /**
     * Prefer the row that actually names the position.
     *
     * ESPN's ids duplicate — `WR` is both id 1 ("Wide Receiver") and id 24
     * ("WR"), `RB` both id 9 ("Running Back") and id 19 ("RB") — and the junk
     * row sorts arbitrarily, so a plain `first()` returns "WR" about half the
     * time. Rows whose name differs from their abbreviation come first.
     */
    public function scopeDescriptive(Builder $query): Builder
    {
        return $query->orderByRaw('name = abbreviation');
    }
}
