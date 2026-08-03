<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'name', 'abbreviation', 'slug', 'parent_id'])]
class Position extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';
}
