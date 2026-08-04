<?php

namespace App\Jobs;

use App\Jobs\Middleware\ThrottleEspn;
use App\Models\Team;
use App\Services\Espn\Sync\SyncRosters;
use App\Services\Espn\Sync\SyncTeamStats;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One team's roster and statistics for one season.
 *
 * Fanned out for ISOLATION rather than speed. The weekly load across all 136
 * FBS teams is a few hundred requests — a couple of minutes of wall clock, so
 * parallelism buys almost nothing. What it buys is that a single team failing
 * no longer takes the other 135 with it, which is not hypothetical: one
 * historical athlete with a position id we did not carry aborted the entire
 * 2022 stats backfill on a foreign key.
 *
 * The two halves are deliberately one job. They share a team and a season and
 * are always wanted together, so splitting them would double the queue traffic
 * to no benefit.
 */
class SyncTeamSeason implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(
        public int $teamId,
        public int $year,
        public bool $rosters = true,
        public bool $stats = true,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->teamId}:{$this->year}";
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleEspn];
    }

    public function handle(SyncRosters $rosters, SyncTeamStats $stats): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if (! Team::whereKey($this->teamId)->exists()) {
            return;
        }

        if ($this->rosters) {
            $rosters->team($this->teamId, $this->year);
        }

        if ($this->stats) {
            $stats->team($this->teamId, $this->year);
        }
    }
}
