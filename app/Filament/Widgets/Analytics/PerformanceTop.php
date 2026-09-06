<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\OpsReport;
use App\Support\PerformanceReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Pulse's heaviest entries, grouped by key.
 *
 * Read through {@see PerformanceReport}, which is exactly why that class was
 * extracted out of the ops snapshot: this page and the payload the advisor
 * reads must not be able to name different slow queries.
 *
 * ONE COLUMN, TWO MEANINGS, and the table says which. Pulse writes a duration
 * in milliseconds into `value` for the four `slow_*` types and the OCCURRENCE
 * TIMESTAMP for `exception`. So an exception row carries `last_seen_at` and no
 * `worst` at all — omitted rather than zeroed, because a `worst` of 0 on an
 * exception is precisely the invented number the rule exists to stop.
 */
class PerformanceTop extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 12;

    protected static ?int $sort = 4;

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Slowest and loudest')
            ->description('Pulse over the last '.OpsReport::HOURS.' hours, grouped so one slow route is one row')
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('type')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state))
                    ->color(fn (string $state) => $state === PerformanceReport::EXCEPTION ? 'danger' : 'warning'),

                TextColumn::make('what')
                    ->label('What')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->wrap(),

                TextColumn::make('hits')
                    ->label('Hits')
                    ->alignEnd(),

                // ->state() rather than ->formatStateUsing(): Filament renders
                // its placeholder for a null state and never calls the
                // formatter, so a dash written as a formatter never appears.
                TextColumn::make('worst')
                    ->label('Worst')
                    // An exception row has no duration to show, and a dash is
                    // the honest rendering of a measurement that was never
                    // taken.
                    ->state(fn (array $record): string => $record['worst'] === null
                        ? '—'
                        : number_format($record['worst']).' ms')
                    ->alignEnd()
                    ->weight('medium'),

                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->placeholder('—')
                    ->since()
                    ->color('gray'),
            ])
            ->paginated(false);
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $out = [];

        foreach (app(PerformanceReport::class)->checks() as $type => $entries) {
            foreach ($entries as $i => $entry) {
                $out["{$type}-{$i}"] = [
                    'type' => $type,
                    'what' => $entry['what'],
                    'hits' => $entry['hits'],
                    // Present on four types and absent on the fifth, read
                    // rather than defaulted.
                    'worst' => $entry['worst'] ?? null,
                    'last_seen_at' => $entry['last_seen_at'] ?? null,
                ];
            }
        }

        return $out;
    }
}
