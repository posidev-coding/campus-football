<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The panel's front page, which used to be Filament's own two placeholder
 * widgets — an account card and a link to Filament's docs.
 *
 * Two columns, because every widget here is either a full-width stat row or a
 * horizontal bar chart, and three columns squeezes a ten-row chart into a
 * width where the labels wrap.
 *
 * The widgets themselves are DISCOVERED (`app/Filament/Widgets`), unlike the
 * five Sync Health ones which set `$isDiscovered = false` and stay scoped to
 * their page — that is the switch that decides what lands here.
 */
class Dashboard extends BaseDashboard
{
    public function getColumns(): int|array
    {
        return 2;
    }
}
