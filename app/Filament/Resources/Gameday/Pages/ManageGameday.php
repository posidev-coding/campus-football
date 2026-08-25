<?php

namespace App\Filament\Resources\Gameday\Pages;

use App\Filament\Resources\Gameday\GamedayResource;
use Filament\Resources\Pages\ManageRecords;

/**
 * One page, modals for the rest — the ManageWorkbook shape.
 *
 * No CreateAction on purpose. A GameDay week is created by `cfb:gameday`
 * against a real Saturday on the calendar; a hand-made row for a date nobody
 * plays on is a card that renders a week that does not exist.
 */
class ManageGameday extends ManageRecords
{
    protected static string $resource = GamedayResource::class;
}
