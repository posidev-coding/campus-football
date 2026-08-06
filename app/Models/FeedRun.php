<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One invocation of a recurring sync command — what ran, what it wrote, what
 * it spent, and how it ended. See the migration for why `sync_runs` is not
 * this table.
 */
#[Fillable([
    'command', 'season_year', 'status', 'records', 'requests',
    'duration_ms', 'error', 'batch_id', 'started_at', 'finished_at',
])]
class FeedRun extends Model
{
    use Prunable;

    public const RUNNING = 'running';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    /**
     * A fortnight of history. The live tier writes a row a minute all
     * Saturday, and the value here is operational — "is the schedule
     * healthy" — not archival.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(14));
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function begin(string $command, ?int $year): self
    {
        return static::create([
            'command' => $command,
            'season_year' => $year,
            'status' => self::RUNNING,
            'started_at' => now(),
        ]);
    }

    public function complete(int $records, int $requests, int $durationMs, ?string $batchId = null): void
    {
        $this->update([
            'status' => self::COMPLETE,
            'records' => $records,
            'requests' => $requests,
            'duration_ms' => $durationMs,
            'batch_id' => $batchId,
            'finished_at' => now(),
        ]);
    }

    public function fail(string $error, int $requests, int $durationMs): void
    {
        $this->update([
            'status' => self::FAILED,
            'error' => mb_substr($error, 0, 2000),
            'requests' => $requests,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
        ]);
    }

    /** The most recent run of one command, whatever its outcome. */
    public static function latestFor(string $command): ?self
    {
        return static::where('command', $command)
            ->orderByDesc('started_at')
            ->first();
    }
}
