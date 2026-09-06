<?php

namespace App\Filament\Widgets\Analytics;

use App\Models\FeedRun;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Did the maintenance advisor actually run, and what did it find?
 *
 * The advisor is a Claude Code routine whose cron lives outside this
 * repository, so nothing in the scheduler can report it overdue. Its
 * `feed_runs` rows are the only record that it ran at all — and a routine
 * that dies silently is indistinguishable from one that never ran, which is
 * the failure a ledger exists to prevent.
 *
 * A FAILED pass is a row here, not an absence: the skill posts
 * `{"error": …}` rather than nothing when it cannot read telemetry.
 */
class AdvisorLedger extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 12;

    protected static ?int $sort = 5;

    protected ?string $pollingInterval = null;

    /** Passes shown — enough to see a cadence, not enough to be a log. */
    private const PASSES = 10;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Advisor passes')
            ->description('The routine runs outside this repository, so these rows are the only record it ran')
            ->records(fn (): array => FeedRun::query()
                ->where('command', FeedRun::ADVISOR)
                ->orderByDesc('started_at')
                ->limit(self::PASSES)
                ->get()
                ->keyBy('id')
                ->map(fn (FeedRun $run): array => [
                    'status' => $run->status,
                    'started_at' => $run->started_at,
                    'error' => $run->error,
                ])
                ->all())
            ->columns([
                TextColumn::make('status')
                    ->label('')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => strtoupper($state))
                    ->color(fn (string $state) => $state === FeedRun::FAILED ? 'danger' : 'success'),

                TextColumn::make('started_at')
                    ->label('Ran')
                    ->since()
                    ->weight('medium'),

                TextColumn::make('error')
                    ->label('Detail')
                    ->state(fn (array $record): string => $record['error'] ?? '—')
                    ->color('gray')
                    ->wrap(),
            ])
            ->emptyStateHeading('No passes recorded')
            ->emptyStateDescription('The advisor has not reported a run. Check its cron, not this app.')
            ->paginated(false);
    }
}
