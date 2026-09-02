<?php

namespace App\Models;

use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A broadcast network and its mark, keyed by the short name ESPN writes
 * into `games.broadcasts` — "ESPN", "SEC Network", "FOX".
 *
 * Written by SyncGames off the scoreboard's `geoBroadcasts[].media`, on the
 * same request the scores arrive on. `logo` and `logo_dark` are null for
 * every network ESPN has never sent artwork for, and a payload naming a
 * network without a logo never nulls one we hold. Read through
 * App\Support\Networks, never per card.
 */
#[Fillable(['name', 'logo', 'logo_dark'])]
class Network extends Model
{
    /** @use HasFactory<NetworkFactory> */
    use HasFactory;
}
