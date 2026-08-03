<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'name', 'city', 'state', 'capacity', 'indoor', 'grass'])]
class Venue extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return ['indoor' => 'boolean', 'grass' => 'boolean'];
    }
}
