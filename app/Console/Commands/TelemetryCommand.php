<?php

namespace App\Console\Commands;

use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Models\ClientError;
use App\Models\FeedRun;
use App\Models\UxEvent;
use App\Services\CfbCalendar;
use App\Support\CoverageReport;
use App\Support\OpsReport;
use App\Support\PickemPreflight;
use App\Support\SyncSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the app knows about how it is doing, in one payload.
 *
 * This is what the maintenance advisor reads. The advisor is a Claude Code
 * routine with NO DATABASE ACCESS — it reads the repository and this snapshot,
 * and the quality of what it proposes is bounded by what is in here.
 *
 * Two rules, and they are the whole design:
 *
 *   1. **Aggregate only. No user identifiers, ever.** Not an id, not an email,
 *      not a handle. The payload leaves the machine, and a snapshot that
 *      carries identity is a snapshot that cannot be handed to anything.
 *      `TelemetryTest` asserts this rather than trusting it.
 *   2. **It reports, it never fixes.** Every remedy is a string for a human
 *      or an agent to run.
 *
 * `--json` is the machine form the `/ops/telemetry` route will serve; the
 * default is a terminal read, because the first consumer of any report is
 * whoever is standing at a keyboard wondering what just happened.
 */
class TelemetryCommand extends Command
{
    protected $signature = 'cfb:telemetry {--json : Emit the raw snapshot instead of a terminal read}';

    protected $description = 'One aggregate snapshot of app health, data coverage, the schedule and the funnel';

    public function handle(
        OpsReport $ops,
        CoverageReport $coverage,
        PickemPreflight $preflight,
        SyncSchedule $schedule,
        CfbCalendar $calendar,
    ): int {
        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'window_hours' => OpsReport::HOURS,
            'season' => [
                'current_year' => $calendar->currentYear(),
                'results_year' => $calendar->resultsYear(),
                'phase' => $calendar->phase()->value,
            ],
            'ops' => $ops->checks(),
            'coverage' => $coverage->checks(),
            'pickem' => $preflight->checks(),
            'schedule' => $this->schedule($schedule),
            'errors' => [
                'commands' => $this->recentFailures('not like'),
                'jobs' => $this->recentFailures('like'),
                'client' => $this->clientErrors(),
            ],
            'performance' => $this->performance(),
            'funnel' => $this->funnel(),
        ];

        if ($this->option('json')) {
            $this->output->writeln(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->render($snapshot);
    }

    /**
     * The schedule WITHOUT its FeedRun models — `SyncSchedule::tasks()` hands
     * back Eloquent instances for the admin table, and serializing one drags
     * every column into the payload.
     *
     * @return list<array<string, mixed>>
     */
    private function schedule(SyncSchedule $schedule): array
    {
        return collect($schedule->tasks())
            ->map(fn (array $task): array => [
                'name' => $task['name'],
                'cadence' => $task['cadence'],
                'gated' => $task['gated'],
                'overdue' => $task['overdue'],
                'last_status' => $task['run']?->status,
                'last_run_at' => $task['run']?->started_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Failed runs inside the window, split by what failed.
     *
     * The `job:` prefix is the split: `Queue::failing` writes those rows
     * because Laravel Cloud's managed queues keep `failed_jobs` to themselves,
     * so they are the only record of a dead job the app can read at all.
     *
     * @return list<array<string, mixed>>
     */
    private function recentFailures(string $operator): array
    {
        return FeedRun::query()
            ->where('status', FeedRun::FAILED)
            ->where('command', $operator, 'job:%')
            ->where('started_at', '>=', now()->subHours(OpsReport::HOURS))
            ->orderByDesc('started_at')
            ->limit(20)
            ->get(['command', 'season_year', 'error', 'started_at'])
            ->map(fn (FeedRun $run): array => [
                'command' => $run->command,
                'season_year' => $run->season_year,
                'error' => $run->error,
                'at' => $run->started_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Browser errors, grouped by fingerprint. The one signal no server-side
     * monitor produces — and the class of bug a 390px PWA ships silently.
     *
     * @return list<array<string, mixed>>
     */
    private function clientErrors(): array
    {
        return ClientError::query()
            ->where('created_at', '>=', now()->subHours(OpsReport::HOURS))
            ->orderByDesc('reports')
            ->limit(20)
            ->get()
            ->map(fn (ClientError $error): array => [
                'kind' => $error->kind,
                'message' => $error->message,
                'source' => $error->source,
                'line' => $error->line,
                'path' => $error->path,
                'viewport' => $error->viewport,
                'standalone' => $error->standalone,
                'reports' => $error->reports,
            ])
            ->all();
    }

    /**
     * Pulse's own tables, read straight — the decisive advantage of Pulse over
     * a hosted APM for this app: the data is in our MySQL, so a snapshot is a
     * query rather than an API call, a rate limit and a bill.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function performance(): array
    {
        if (! Schema::hasTable('pulse_entries')) {
            return [];
        }

        return collect(['slow_request', 'slow_query', 'slow_job', 'slow_outgoing_request', 'exception'])
            ->mapWithKeys(fn (string $type): array => [$type => $this->pulseTop($type)])
            ->all();
    }

    /**
     * The heaviest entries of one type. Grouped by key, so a route that is
     * slow two hundred times is one line with a count rather than two hundred.
     *
     * @return list<array<string, mixed>>
     */
    private function pulseTop(string $type): array
    {
        return DB::table('pulse_entries')
            ->where('type', $type)
            ->where('timestamp', '>=', now()->subHours(OpsReport::HOURS)->getTimestamp())
            ->groupBy('key')
            ->orderByRaw('max(value) desc')
            ->limit(10)
            ->selectRaw('`key`, count(*) as hits, max(value) as worst')
            ->get()
            ->map(fn ($row): array => [
                'what' => OpsReport::readableKey((string) $row->key),
                'hits' => (int) $row->hits,
                'worst' => (int) $row->worst,
            ])
            ->all();
    }

    /**
     * The funnel: seven persisted days plus whatever today has counted so far.
     *
     * "Abandoned with zero picks" is deliberately absent as a number — it is
     * `slate_entered` minus `first_pick_made`, and the advisor can subtract.
     *
     * @return array<string, int>
     */
    private function funnel(): array
    {
        $persisted = UxEvent::query()
            ->where('day', '>=', now()->timezone(config('cfb.timezone'))->subDays(7)->toDateString())
            ->groupBy('signal')
            // BOTH backticked: `signal` and `count` are reserved words in
            // MySQL 8, and an unquoted one is a 1064 rather than a wrong
            // answer — the same family as the STORED trap in data-model.md.
            ->selectRaw('`signal`, sum(`count`) as total')
            ->pluck('total', 'signal');

        $today = app(RecordUxEvent::class);

        // Today's counters are still in Redis. Including them keeps this
        // section and OpsReport's pick-through row telling the same story —
        // two numbers for one fact is how an agent's afternoon goes missing.
        return collect(UxSignal::cases())
            ->mapWithKeys(fn (UxSignal $signal): array => [
                $signal->value => (int) ($persisted[$signal->value] ?? 0) + $today->todayCount($signal),
            ])
            ->all();
    }

    private function render(array $snapshot): int
    {
        foreach (['ops' => 'Application', 'coverage' => 'Data coverage', 'pickem' => "Pick'em"] as $section => $heading) {
            $this->newLine();
            $this->line("  <fg=gray>{$heading}</>");

            foreach ($snapshot[$section] as $check) {
                $mark = match ($check['status']) {
                    OpsReport::OK => '<fg=green>✓</>',
                    OpsReport::WARN => '<fg=yellow>!</>',
                    default => '<fg=red>✗</>',
                };

                $this->line(sprintf('  %s %-26s %s', $mark, $check['label'], $check['detail']));
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>Funnel · 7d</>');

        foreach ($snapshot['funnel'] as $signal => $count) {
            $this->line(sprintf('    %-26s %d', $signal, $count));
        }

        $failing = collect($snapshot['ops'])->where('status', OpsReport::FAIL)->count();

        $this->newLine();
        $this->line($failing > 0
            ? "  <fg=red>{$failing} failing</>"
            : '  <fg=gray>nothing failing</>');

        // Deliberately ALWAYS zero. `cfb:doctor` is the deploy gate and exits
        // non-zero on a data gap; this is a report, and a snapshot command
        // that fails a pipeline because a request was slow is a snapshot
        // command somebody turns off.
        return self::SUCCESS;
    }
}
