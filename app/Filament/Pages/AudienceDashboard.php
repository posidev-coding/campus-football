<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Analytics\ActivesByCohortArea;
use App\Filament\Widgets\Analytics\AdoptionRadial;
use App\Filament\Widgets\Analytics\CohortRetentionHeatmap;
use App\Filament\Widgets\Analytics\DeviceMix;
use App\Filament\Widgets\Analytics\LifecycleFunnel;
use App\Filament\Widgets\Analytics\QuietScreens;
use App\Filament\Widgets\Analytics\TopGroupsBar;
use App\Filament\Widgets\Analytics\TopTeamsBar;
use App\Filament\Widgets\Analytics\WeekHeat;
use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Audience — who is here, whether they come back, and what they do.
 *
 * Overview answers "is anything happening". This page answers the harder
 * question underneath it: is the app HOLDING anybody, or is every good week a
 * different set of strangers? A flat total of actives looks identical in both
 * cases, which is why almost every widget here is split by cohort.
 *
 * Every widget resolves the filters through {@see AnalyticsWindow::from()} and
 * reads {@see AnalyticsCatalog}, so nothing on this page can
 * disagree with Overview, with Health, or with the payload the advisor reads.
 */
class AudienceDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Audience';

    protected static UnitEnum|string|null $navigationGroup = 'Analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static string $routePath = 'audience';

    public function getColumns(): int|array
    {
        return 12;
    }

    /** @return list<class-string> */
    public function getWidgets(): array
    {
        return [
            LifecycleFunnel::class,
            AdoptionRadial::class,
            CohortRetentionHeatmap::class,
            ActivesByCohortArea::class,
            DeviceMix::class,
            WeekHeat::class,
            TopTeamsBar::class,
            TopGroupsBar::class,
            QuietScreens::class,
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    Select::make('range')
                        ->label('Range')
                        ->options(AnalyticsWindow::options())
                        ->default(AnalyticsWindow::DEFAULT_RANGE)
                        ->selectablePlaceholder(false),

                    /*
                     * MEMBERS by default. "Do the people who are here come
                     * back" is a question about members; guests have no
                     * account to come back to, and folding them in makes
                     * retention look like whatever the week's traffic did.
                     */
                    Select::make('audience')
                        ->label('Audience')
                        ->options(['members' => 'Members', 'guests' => 'Guests', 'all' => 'Everyone'])
                        ->default('members')
                        ->selectablePlaceholder(false),

                    // Off, for the reason it is off on Overview: at pilot
                    // scale the founder's own browsing is most of the traffic.
                    Toggle::make('staff')
                        ->label('Include staff')
                        ->default(false),
                ]),
        ]);
    }
}
