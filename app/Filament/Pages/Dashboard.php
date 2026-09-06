<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Analytics\ActivesStats;
use App\Filament\Widgets\Analytics\RouteTreemap;
use App\Filament\Widgets\Analytics\TodayPickem;
use App\Filament\Widgets\Analytics\TrafficArea;
use App\Support\AnalyticsWindow;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Overview — what the week actually did.
 *
 * TWELVE COLUMNS, not two. The old two-column grid was right when every
 * widget was a full-width stat row or a ten-row horizontal bar; an area chart
 * beside a stat block needs an eight-and-four, and twelve is the only grid
 * that divides into halves, thirds and quarters without a widget having to
 * round.
 *
 * The widgets are LISTED, never discovered. Discovery decided this page's
 * contents for as long as there was nothing to decide; now that a widget can
 * belong to Overview or to Health or to neither, "which page is this on" is a
 * question the page should answer out loud. The five widgets that used to
 * land here by discovery are parked (`$isDiscovered = false`) rather than
 * deleted — they come back converted in phases 6 and 7.
 *
 * The filters are read by every widget through {@see AnalyticsWindow::from()},
 * so no two of them can disagree about what "28d" means.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Overview';

    public function getColumns(): int|array
    {
        return 12;
    }

    /** @return list<class-string> */
    public function getWidgets(): array
    {
        return [
            ActivesStats::class,
            TrafficArea::class,
            TodayPickem::class,
            RouteTreemap::class,
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('window')
                        ->label('Range')
                        ->options(AnalyticsWindow::options())
                        ->default(AnalyticsWindow::DEFAULT_DAYS)
                        ->selectablePlaceholder(false),

                    /*
                     * OFF by default, and that is the honest default at pilot
                     * scale: the founder's own browsing is most of the
                     * traffic, so a chart that silently includes it is a chart
                     * of one person's afternoon.
                     */
                    Toggle::make('staff')
                        ->label('Include staff')
                        ->default(false),
                ]),
        ]);
    }
}
