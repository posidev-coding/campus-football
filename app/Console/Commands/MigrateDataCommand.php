<?php

namespace App\Console\Commands;

use App\Models\SyncRun;
use App\Services\CfbCalendar;
use App\Services\Espn\EspnClient;
use Illuminate\Console\Command;

/**
 * Take an empty database to a fully populated one.
 *
 * Everything here already exists as a separate command; this is the
 * orchestration — dependency order, resumability, and an honest estimate before
 * it starts. A full six-season run is thousands of requests and takes hours, so
 * "run it and walk away" has to actually work.
 *
 *   php artisan cfb:migrate --from=2021 --to=2026
 *   php artisan cfb:migrate --resume            # after an interruption
 *   php artisan cfb:migrate --only=summaries --year=2025
 *
 * Every step is idempotent, so a re-run costs requests but never corrupts.
 * `--resume` skips steps already recorded complete in `sync_runs`.
 */
class MigrateDataCommand extends Command
{
    protected $signature = 'cfb:migrate
        {--from= : First season year}
        {--to= : Last season year (defaults to the current season)}
        {--year= : A single season, shorthand for --from and --to}
        {--only= : One step only}
        {--resume : Skip steps already recorded complete}
        {--summaries : Include game summaries (slow: one request per game)}
        {--fresh : Clear recorded progress and start over}';

    protected $description = 'Populate the whole database from ESPN, resumably';

    /**
     * Dependency order, and it is not negotiable.
     *
     * Conferences before teams (teams inherit classification from the
     * conference tree), both before games, games before standings and
     * summaries. Leaders before athletes, because the athlete resolve pass
     * reads the leaderboard to discover who is missing.
     *
     * @var list<array{step:string, command:string, per_season:bool, cost:string}>
     */
    private const STEPS = [
        ['step' => 'seasons', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '~4'],
        ['step' => 'conferences', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '~200'],
        ['step' => 'teams', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '~800'],
        ['step' => 'games', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '~9'],
        ['step' => 'rankings', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '~120'],
        ['step' => 'standings', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '~11'],
        ['step' => 'compute', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '0'],
        ['step' => 'reconcile', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '0'],
        ['step' => 'leaders', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '2'],
        ['step' => 'athletes', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '~250'],
        // Six requests for a 5,000-prospect class: the collection serves 1,000
        // a page and every item is already inline. It was ~5,200 when each
        // prospect cost a $ref fetch, which is why this used to be capped.
        ['step' => 'recruiting', 'command' => 'cfb:sync', 'per_season' => true, 'cost' => '6'],
        ['step' => 'rosters', 'command' => 'cfb:players', 'per_season' => true, 'cost' => '~136'],
        ['step' => 'stats', 'command' => 'cfb:players', 'per_season' => true, 'cost' => '~272'],
        // Deliberately last and opt-in. One request per game, 544 KB each — by
        // far the longest pole in the whole migration.
        ['step' => 'summaries', 'command' => 'cfb:summaries', 'per_season' => true, 'cost' => '1/game'],
        // No season parameter: the news feed is a rolling few-day window.
        ['step' => 'news', 'command' => 'cfb:sync', 'per_season' => false, 'cost' => '1'],
    ];

    public function handle(EspnClient $espn): int
    {
        if ($this->option('fresh')) {
            SyncRun::truncate();
            $this->warn('Cleared recorded progress.');
        }

        $years = $this->years();
        $steps = $this->steps();

        if ($steps === []) {
            $this->error('Unknown step ['.$this->option('only').'].');

            return self::FAILURE;
        }

        $this->summarize($years, $steps);

        $espn->resetCallCount();
        $started = microtime(true);

        foreach ($steps as $step) {
            $targets = $step['per_season'] ? $years : [null];

            foreach ($targets as $year) {
                $this->runStep($step, $year);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. %d ESPN requests in %s.',
            $espn->callCount(),
            $this->duration(microtime(true) - $started)
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{step:string, command:string, per_season:bool, cost:string}  $step
     */
    private function runStep(array $step, ?int $year): void
    {
        $label = $step['step'].($year ? " {$year}" : '');

        if ($this->option('resume') && SyncRun::isComplete($step['step'], $year)) {
            $this->line("  <fg=gray>·</> {$label} <fg=gray>already done</>");

            return;
        }

        $run = SyncRun::begin($step['step'], $year);
        $started = microtime(true);

        try {
            $this->call($step['command'], $this->optionsFor($step, $year));

            $run->complete((int) ((microtime(true) - $started) * 1000));
        } catch (\Throwable $e) {
            /*
             * A failed step does not abort the migration. Steps are largely
             * independent, and losing an entire overnight run because one
             * season's recruiting feed 500'd would be the wrong trade — the
             * failure is recorded and `--resume` will retry just that step.
             */
            $run->fail($e->getMessage(), (int) ((microtime(true) - $started) * 1000));

            $this->error("  ✗ {$label}: ".$e->getMessage());
        }
    }

    /**
     * @param  array{step:string, command:string, per_season:bool, cost:string}  $step
     *                                                                                  Named `optionsFor`, not `arguments` — Command::arguments() already
     *                                                                                  exists on the base class and overriding it with a private method is a
     *                                                                                  fatal access-level error at load time.
     * @return array<string, mixed>
     */
    private function optionsFor(array $step, ?int $year): array
    {
        $args = $year !== null ? ['--year' => $year] : [];

        return match ($step['command']) {
            'cfb:sync' => $args + ['--only' => $step['step']],
            'cfb:players' => $args + ['--only' => $step['step'], '--classification' => 'FBS'],
            // Queued, not inline: the migration would otherwise hold one
            // process open for thousands of 544 KB payloads and exhaust memory.
            // A worker has to be running for this step to make progress.
            'cfb:summaries' => $args + ['--missing' => true, '--limit' => 2000],
            default => $args,
        };
    }

    /** @return list<int> */
    private function years(): array
    {
        if ($year = $this->option('year')) {
            return [(int) $year];
        }

        $from = (int) ($this->option('from') ?: config('cfb.season'));
        $to = (int) ($this->option('to') ?: app(CfbCalendar::class)->currentYear());

        return $from <= $to ? range($from, $to) : range($to, $from);
    }

    /** @return list<array{step:string, command:string, per_season:bool, cost:string}> */
    private function steps(): array
    {
        $only = $this->option('only');

        if ($only !== null) {
            return array_values(array_filter(self::STEPS, fn (array $s) => $s['step'] === $only));
        }

        // Summaries are excluded by default: one request per game turns a
        // twenty-minute migration into an overnight one, and that should be a
        // decision rather than a surprise.
        return array_values(array_filter(
            self::STEPS,
            fn (array $s) => $s['step'] !== 'summaries' || $this->option('summaries')
        ));
    }

    /**
     * @param  list<int>  $years
     * @param  list<array{step:string, command:string, per_season:bool, cost:string}>  $steps
     */
    private function summarize(array $years, array $steps): void
    {
        $this->info(sprintf(
            'Migrating %d season%s (%s) — %d steps',
            count($years),
            count($years) === 1 ? '' : 's',
            implode(', ', $years),
            count($steps)
        ));

        if (collect($steps)->contains(fn (array $s) => $s['step'] === 'summaries')) {
            $this->warn('  Includes game summaries: one request per game, expect hours.');
        }

        $this->newLine();
    }

    private function duration(float $seconds): string
    {
        return $seconds < 90
            ? sprintf('%.1fs', $seconds)
            : sprintf('%dm %ds', floor($seconds / 60), $seconds % 60);
    }
}
