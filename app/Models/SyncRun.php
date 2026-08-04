<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of `cfb:migrate`, so a multi-hour run can be resumed rather than
 * restarted.
 */
#[Fillable([
    'step', 'season_year', 'status', 'records', 'requests',
    'duration_ms', 'error', 'started_at', 'finished_at',
])]
class SyncRun extends Model
{
    public const RUNNING = 'running';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function isComplete(string $step, ?int $year): bool
    {
        return static::where('step', $step)
            ->where('season_year', $year)
            ->where('status', self::COMPLETE)
            ->exists();
    }

    public static function begin(string $step, ?int $year): self
    {
        return static::updateOrCreate(
            ['step' => $step, 'season_year' => $year],
            ['status' => self::RUNNING, 'started_at' => now(), 'finished_at' => null, 'error' => null]
        );
    }

    public function complete(int $durationMs): void
    {
        $this->update([
            'status' => self::COMPLETE,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
        ]);
    }

    public function fail(string $error, int $durationMs): void
    {
        $this->update([
            'status' => self::FAILED,
            // Truncated: a stack-trace-laden driver message can run to
            // kilobytes and this column exists to be glanced at.
            'error' => mb_substr($error, 0, 2000),
            'duration_ms' => $durationMs,
            'finished_at' => now(),
        ]);
    }
}
