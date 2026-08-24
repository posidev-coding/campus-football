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
 * The ledger covers scheduled COMMANDS and, since the `Queue::failing` hook in
 * AppServiceProvider, failed JOBS too — the latter prefixed `job:`. That hook
 * exists because Laravel Cloud's managed queues keep `failed_jobs` to
 * themselves: without a row of our own, a job that dies in production is
 * invisible to every screen we own. The Cloud dashboard's Queues tab still has
 * the payload and the retry button; this has the fact that it happened.
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
            ->description('Scheduled commands and failed queue jobs (job:…). The Laravel Cloud dashboard keeps the payload and the retry button.')
            ->query(fn (): Builder => FeedRun::query()->where('status', FeedRun::FAILED))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('command')
                    ->label('Command or job')
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
            ->emptyStateDescription('No scheduled command and no queue job has failed in the last fortnight.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([10, 25]);
    }
}
