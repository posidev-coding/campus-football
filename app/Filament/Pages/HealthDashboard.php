<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Analytics\AdvisorLedger;
use App\Filament\Widgets\Analytics\ErrorRateByRoute;
use App\Filament\Widgets\Analytics\IngestBuffers;
use App\Filament\Widgets\Analytics\OpsChecks;
use App\Filament\Widgets\Analytics\PerformanceTop;
use App\Support\OpsReport;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Health — the same rows `cfb:telemetry` prints and `/ops/telemetry` serves,
 * for somebody standing at a keyboard.
 *
 * NO FILTERS, deliberately. Every row here is already scoped to
 * `OpsReport::HOURS`, and a range selector would invite reading a
 * twenty-four-hour check over ninety days, which is not the same check with a
 * wider window — it is a different question with the same label.
 *
 * Every widget reads the SAME support class the snapshot does — `OpsReport`,
 * `PerformanceReport`, `AnalyticsCatalog`. That is the `CoverageReport` rule
 * and it is the whole reason this page can be trusted beside the payload: the
 * panel and the advisor cannot disagree about which query is slowest, because
 * there is one implementation and two skins on it.
 */
class HealthDashboard extends BaseDashboard
{
    protected static ?string $title = 'Health';

    protected static UnitEnum|string|null $navigationGroup = 'Analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 2;

    protected static string $routePath = 'health';

    public function getColumns(): int|array
    {
        return 12;
    }

    public function getSubheading(): ?string
    {
        return 'The last '.OpsReport::HOURS.' hours. The same rows php artisan cfb:telemetry prints.';
    }

    /** @return list<class-string> */
    public function getWidgets(): array
    {
        return [
            OpsChecks::class,
            IngestBuffers::class,
            ErrorRateByRoute::class,
            PerformanceTop::class,
            AdvisorLedger::class,
        ];
    }
}
