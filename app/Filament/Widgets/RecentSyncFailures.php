<?php

namespace App\Filament\Widgets;

use App\Models\FeedRun;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Failed feed runs, error text verbatim — the text IS the point of keeping
 * them, so it is not truncated into uselessness.
 *
 * Scope note the UI states plainly: this ledger covers the scheduled COMMANDS.
 * On Laravel Cloud's managed queues, failed JOBS live in the Cloud dashboard's
 * Queues tab, not in `failed_jobs`.
 */
class RecentSyncFailures extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent failures')
            ->description('Scheduled commands only — failed queue jobs live in the Laravel Cloud dashboard.')
            ->query(fn (): Builder => FeedRun::query()->where('status', FeedRun::FAILED))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('command')
                    ->label('Command')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->weight('medium'),

                TextColumn::make('season_year')
                    ->label('Season')
                    ->placeholder('—')
                    ->color('gray'),

                TextColumn::make('started_at')
                    ->label('When')
                    ->since()
                    ->color('gray'),

                TextColumn::make('error')
                    ->label('Error')
                    ->color('danger')
                    ->wrap()
                    ->limit(200)
                    ->tooltip(fn (?string $state) => $state),
            ])
            ->emptyStateHeading('Nothing has failed')
            ->emptyStateDescription('No scheduled sync command has failed in the last fortnight.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([10, 25]);
    }
}
