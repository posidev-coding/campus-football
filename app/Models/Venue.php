<?php

namespace App\Models;

use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'name', 'city', 'state', 'capacity', 'indoor', 'grass', 'image_url', 'image_checked_at'])]
class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return ['indoor' => 'boolean', 'grass' => 'boolean', 'image_checked_at' => 'datetime'];
    }

    /** "Atlanta, GA" — the line under a venue name, without a dangling comma. */
    public function place(): ?string
    {
        return collect([$this->city, $this->state])->filter()->implode(', ') ?: null;
    }
}
