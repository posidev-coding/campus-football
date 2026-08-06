<?php

namespace App\Filament\Widgets;

use App\Support\CoverageReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Expected against actual — the check that turns "the command reported
 * success and wrote nothing" into a red row.
 *
 * A table over CUSTOM DATA rather than Eloquent: the rows are computed, and
 * CoverageReport is shared verbatim with `php artisan cfb:doctor` so the
 * panel and the terminal cannot disagree.
 */
class DataCoverage extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Data coverage')
            ->description('Expected against actual, shared verbatim with php artisan cfb:doctor')
            ->records(fn (): array => collect(app(CoverageReport::class)->checks())
                ->keyBy('key')
                ->all())
            ->columns([
                TextColumn::make('status')
                    ->label('')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => strtoupper($state))
                    ->color(fn (string $state) => match ($state) {
                        CoverageReport::OK => 'success',
                        CoverageReport::WARN => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('label')
                    ->label('Check')
                    ->weight('medium')
                    ->description(fn (array $record) => $record['detail']),

                TextColumn::make('expected')
                    ->label('Expected')
                    ->alignEnd()
                    ->color('gray'),

                TextColumn::make('actual')
                    ->label('Actual')
                    ->alignEnd()
                    ->weight('medium'),

                // Named, because a dashboard that says "broken" without saying
                // "run this" just moves the mystery one screen over.
                TextColumn::make('remedy')
                    ->label('Remedy')
                    ->formatStateUsing(fn (?string $state) => $state ? "php artisan {$state}" : '—')
                    ->color('gray')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->copyable()
                    ->copyableState(fn (?string $state) => $state ? "php artisan {$state}" : '')
                    ->toggleable(),
            ])
            ->paginated(false);
    }
}
