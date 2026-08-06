<?php

namespace App\Filament\Widgets;

use App\Models\FeedRun;
use App\Support\SyncSchedule;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Every cfb entry in the schedule, with its latest ledger row.
 *
 * Introspected from `Schedule::events()` rather than a hand-kept list, so a
 * task added to routes/console.php appears here without anyone remembering a
 * second registry.
 */
class ScheduledSyncTasks extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Scheduled tasks')
            ->description('Gated tasks are outside their season or window — not a fault.')
            ->records(fn (): array => collect(app(SyncSchedule::class)->tasks())
                ->map(fn (array $task, int $index) => [
                    'id' => $index,
                    'name' => $task['name'],
                    'cadence' => $task['cadence'],
                    'gated' => $task['gated'],
                    'overdue' => $task['overdue'],
                    'status' => match (true) {
                        $task['gated'] => 'gated',
                        $task['run']?->status === FeedRun::FAILED => 'failed',
                        $task['overdue'] => 'overdue',
                        $task['run'] === null => 'untracked',
                        default => 'ok',
                    },
                    'last_run' => $task['run']?->started_at?->diffForHumans(),
                    'records' => $task['run']?->records,
                    'requests' => $task['run']?->requests,
                ])
                ->all())
            ->columns([
                TextColumn::make('name')
                    ->label('Task')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->weight('medium')
                    ->description(fn (array $record) => $record['cadence']),

                TextColumn::make('last_run')
                    ->label('Last run')
                    ->placeholder('never')
                    ->color('gray'),

                TextColumn::make('records')
                    ->label('Records')
                    ->numeric()
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('requests')
                    ->label('Requests')
                    ->numeric()
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->alignEnd()
                    ->color(fn (string $state) => match ($state) {
                        'ok' => 'success',
                        'failed' => 'danger',
                        'overdue' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->paginated(false);
    }
}
