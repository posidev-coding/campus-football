<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\DataCoverage;
use App\Support\OpsReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Is the application behaving — the same rows `cfb:telemetry` prints and
 * `/ops/telemetry` serves.
 *
 * A table over COMPUTED rows rather than Eloquent, in the shape
 * {@see DataCoverage} already set, and reading
 * {@see OpsReport} verbatim. Three surfaces, one implementation: the panel,
 * the terminal and the advisor cannot disagree about whether the app is
 * healthy, because there is only one answer to disagree about.
 */
class OpsChecks extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 12;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Application')
            ->description('The last '.OpsReport::HOURS.' hours, shared verbatim with php artisan cfb:telemetry')
            ->records(fn (): array => collect(app(OpsReport::class)->checks())->keyBy('key')->all())
            ->columns([
                TextColumn::make('status')
                    ->label('')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => strtoupper($state))
                    ->color(fn (string $state) => match ($state) {
                        OpsReport::OK => 'success',
                        OpsReport::WARN => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('label')
                    ->label('Check')
                    ->weight('medium')
                    ->description(fn (array $record) => $record['detail']),

                /*
                 * VERBATIM, and this is where it differs from the otherwise
                 * identical DataCoverage column. `CoverageReport` remedies are
                 * bare commands, so that widget prefixes "php artisan" onto
                 * them. `OpsReport` remedies are finished sentences — some of
                 * them are not commands at all ("Sync Health → Recent
                 * failures, then the Cloud dashboard") — so the same prefix
                 * rendered "php artisan php artisan pulse:work" on one row and
                 * a nonsense instruction on another. Copying a column is not
                 * the same as copying its contract.
                 */
                TextColumn::make('remedy')
                    ->label('Remedy')
                    ->state(fn (array $record): string => $record['remedy'] ?? '—')
                    ->color('gray')
                    ->wrap()
                    ->size('xs')
                    ->copyable()
                    ->copyableState(fn (array $record): string => $record['remedy'] ?? '')
                    ->toggleable(),
            ])
            ->paginated(false);
    }
}
