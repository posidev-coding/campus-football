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
     * The maintenance advisor's own runs, in the same ledger.
     *
     * The advisor is a Claude Code routine with no database access — it reads
     * a telemetry snapshot over HTTP and files workbook items back. So its
     * runs are recorded through the `/ops` surface rather than by a scheduled
     * command, but they land HERE, in the same table, under the same three
     * statuses. Sync Health's failures table therefore shows an advisor run
     * that died with no extra wiring, and "when did it last run" is one query
     * against a column that already exists.
     *
     * It is deliberately NOT on `SyncSchedule`: that report introspects OUR
     * scheduler, and the advisor's cron lives in Claude Code's cloud. A row
     * there would be a task we cannot see the schedule of, reporting an
     * overdue flag we cannot compute.
     */
    public const ADVISOR = 'advisor:review';

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

    /**
     * A failed QUEUE JOB, recorded in the same ledger the scheduled commands
     * write to.
     *
     * It lands here rather than in a table of its own because on Laravel Cloud
     * the managed queues keep `failed_jobs` to themselves — the app cannot read
     * its own failures at all, so a job that dies is invisible to every screen
     * we own. Pulse's Exceptions recorder sees the throw, but not the job
     * record, the attempt count or the queue it died on.
     *
     * Shaped like a run that started and failed in the same instant: a job has
     * no records, spends no ESPN requests of its own, and the duration is the
     * worker's business, not the ledger's.
     */
    public static function jobFailed(string $job, string $error): self
    {
        return static::create([
            'command' => mb_substr('job:'.class_basename($job), 0, 60),
            'status' => self::FAILED,
            'error' => mb_substr($error, 0, 2000),
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /** The advisor's last pass, or null if it has never run here. */
    public static function latestAdvisorRun(): ?self
    {
        return static::latestFor(self::ADVISOR);
    }

    /** The most recent run of one command, whatever its outcome. */
    public static function latestFor(string $command): ?self
    {
        return static::where('command', $command)
            ->orderByDesc('started_at')
            ->first();
    }
}
