<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GameSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Where a summary batch has got to.
 *
 * A full backfill is thousands of jobs over hours, so "is it still going and is
 * it going to finish" needs a straight answer rather than a log tail.
 */
class SummariesStatusCommand extends Command
{
    protected $signature = 'cfb:summaries:status {batch? : Batch id, or omit for overall coverage}';

    protected $description = 'Progress of a game summary batch, or overall coverage';

    public function handle(): int
    {
        if ($id = $this->argument('batch')) {
            return $this->batch($id);
        }

        return $this->coverage();
    }

    private function batch(string $id): int
    {
        $batch = Bus::findBatch($id);

        if ($batch === null) {
            $this->error("No batch [{$id}].");

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  %s  <fg=gray>%d/%d</>  %d%%%s',
            $batch->name,
            $batch->processedJobs(),
            $batch->totalJobs,
            $batch->progress(),
            $batch->cancelled() ? '  <fg=yellow>cancelled</>' : ($batch->finished() ? '  <fg=green>finished</>' : '')
        ));

        if ($batch->failedJobs > 0) {
            $this->line("  <fg=red>{$batch->failedJobs} failed</>  <fg=gray>php artisan queue:failed</>");
        }

        return self::SUCCESS;
    }

    private function coverage(): int
    {
        $rows = Game::query()
            ->completed()
            ->join('seasons', 'seasons.id', '=', 'games.season_id')
            ->leftJoin('game_summaries', 'game_summaries.game_id', '=', 'games.id')
            // `synced`, not `stored` — STORED is a reserved word in MySQL 8
            // (generated columns) and an unquoted alias is a syntax error.
            ->selectRaw('seasons.year, count(*) total, count(game_summaries.game_id) synced')
            ->groupBy('seasons.year')
            ->orderByDesc('seasons.year')
            ->get();

        $this->line('  <fg=gray>season   synced / completed</>');

        foreach ($rows as $row) {
            $pct = $row->total > 0 ? round($row->synced / $row->total * 100) : 0;

            $this->line(sprintf(
                '  %d     %5d / %-5d  %s%d%%</>',
                $row->year,
                $row->synced,
                $row->total,
                $pct === 100 ? '<fg=green>' : ($pct > 0 ? '<fg=yellow>' : '<fg=red>'),
                $pct
            ));
        }

        $missing = Game::completed()->whereNotIn('id', GameSummary::select('game_id'))->count();

        $this->newLine();
        $this->line("  <fg=gray>{$missing} remaining</>");

        return self::SUCCESS;
    }
}
