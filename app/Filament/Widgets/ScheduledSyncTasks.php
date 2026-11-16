<?php

namespace App\Filament\Widgets;

use App\Models\FeedRun;
use App\Support\SyncSchedule;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Every cfb and pick'em entry in the schedule, with its latest ledger row.
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
                    'shared' => $task['shared'],
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

                /*
                 * A shared row's stamp is THIS cadence's own pass, dealt out
                 * of a ledger the command keeps for several entries — said in
                 * the row, so nobody reads one entry's time as the command's.
                 * With none of its own in the window it says so, rather than
                 * "never": the command may well have run, just not as this.
                 */
                TextColumn::make('last_run')
                    ->label('Last run')
                    ->placeholder(fn (array $record) => $record['shared'] ? 'none of its own' : 'never')
                    ->description(fn (array $record) => $record['shared'] ? 'this cadence · shared ledger' : null)
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
