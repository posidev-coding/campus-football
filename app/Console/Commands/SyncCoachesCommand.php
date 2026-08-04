<?php

namespace App\Console\Commands;

use App\Jobs\FetchCoach;
use App\Models\Coach;
use App\Services\Espn\EspnClient;
use App\Services\Espn\Sync\SyncCoaches;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * Career records, birthplaces, tenures and headshots for every coach the
 * roster sync has named.
 *
 * Queues a job per coach by default. `--missing` limits the run to coaches
 * with no career record yet, which makes every re-run a resume; the scheduled
 * refresh uses `--current`, which touches only each coach's latest season —
 * published career history does not change retroactively, the same reasoning
 * that stopped SyncRankings re-reading eighteen weeks to learn one.
 */
class SyncCoachesCommand extends Command
{
    protected $signature = 'cfb:coaches
        {--coach= : One coach id}
        {--missing : Only coaches with no career record stored}
        {--current : Refresh only each coach\'s latest season}
        {--now : Run in-process instead of queueing (small runs and debugging)}';

    protected $description = 'Sync coach careers, tenures, birthplaces and headshots';

    public function handle(EspnClient $espn, SyncCoaches $sync): int
    {
        $coachIds = $this->coachIds();

        if ($coachIds->isEmpty()) {
            $this->info('Nothing to sync.');

            return self::SUCCESS;
        }

        return $this->option('now')
            ? $this->runNow($coachIds, $espn, $sync)
            : $this->queue($coachIds);
    }

    /**
     * @param  Collection<int, int>  $coachIds
     */
    private function queue(Collection $coachIds): int
    {
        $currentOnly = (bool) $this->option('current');

        $batch = Bus::batch($coachIds->map(fn (int $id) => new FetchCoach($id, $currentOnly))->all())
            ->name('Coaches')
            // One malformed coach must not cancel the other 135.
            ->allowFailures()
            ->dispatch();

        $this->info("Queued {$coachIds->count()} coaches.");
        $this->line("  <fg=gray>batch</> {$batch->id}");
        $this->line('  <fg=gray>Run a worker if one is not already going:</>');
        $this->line('  <fg=gray>php artisan queue:work --stop-when-empty</>');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, int>  $coachIds
     */
    private function runNow(Collection $coachIds, EspnClient $espn, SyncCoaches $sync): int
    {
        $espn->resetCallCount();
        $started = microtime(true);
        $currentOnly = (bool) $this->option('current');

        $this->info("Syncing {$coachIds->count()} coaches in-process");
        $bar = $this->output->createProgressBar($coachIds->count());
        $bar->start();

        $synced = 0;
        $empty = 0;
        $errors = [];

        foreach ($coachIds as $coachId) {
            try {
                $sync->handle($coachId, $currentOnly) ? $synced++ : $empty++;
            } catch (\Throwable $e) {
                $errors[$coachId] = $e->getMessage();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line(sprintf(
            '  <fg=green>✓</> %d synced%s%s  <fg=gray>%d requests, %.1fs</>',
            $synced,
            $empty > 0 ? ", <fg=yellow>{$empty} returned no data</>" : '',
            $errors !== [] ? ', <fg=red>'.count($errors).' errored</>' : '',
            $espn->callCount(),
            microtime(true) - $started,
        ));

        foreach (array_slice($errors, 0, 5, true) as $coachId => $message) {
            $this->line("    <fg=red>✗</> coach {$coachId}: ".mb_substr($message, 0, 120));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, int>
     */
    private function coachIds(): Collection
    {
        if ($coachId = $this->option('coach')) {
            return Coach::whereKey((int) $coachId)->pluck('id');
        }

        return Coach::query()
            ->when($this->option('missing'), fn ($q) => $q->whereNull('career_wins'))
            ->orderBy('id')
            ->pluck('id');
    }
}
