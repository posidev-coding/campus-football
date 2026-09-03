<?php

namespace App\Console\Commands;

use App\Support\OpsReport;
use App\Support\TelemetrySnapshot;
use Illuminate\Console\Command;

/**
 * The telemetry snapshot, at the terminal.
 *
 * Same payload as `GET /ops/telemetry` — one {@see TelemetrySnapshot}, two
 * surfaces, so the advisor and whoever is standing at a keyboard can never
 * disagree about how the app is doing. `--json` is the machine form; the
 * default is a read, because the first consumer of any report is a person
 * wondering what just happened.
 */
class TelemetryCommand extends Command
{
    protected $signature = 'cfb:telemetry {--json : Emit the raw snapshot instead of a terminal read}';

    protected $description = 'One aggregate snapshot of app health, data coverage, the schedule and the funnel';

    public function handle(TelemetrySnapshot $telemetry): int
    {
        $snapshot = $telemetry->build();

        if ($this->option('json')) {
            $this->output->writeln(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

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

        // The date is the denominator: a signal counting since this morning
        // has no seven-day number, whatever the column beside it says.
        foreach ($snapshot['funnel'] as $signal => $count) {
            $this->line(sprintf('    %-30s %6d   since %s', $signal, $count, $snapshot['funnel_since'][$signal]));
        }

        $failing = collect($snapshot['ops'])->where('status', OpsReport::FAIL)->count();

        $this->newLine();
        $this->line($failing > 0
            ? "  <fg=red>{$failing} failing</>"
            : '  <fg=gray>nothing failing</>');

        /*
         * Deliberately ALWAYS zero. `cfb:doctor` is the deploy gate and exits
         * non-zero on a data gap; this is a report, and a snapshot command
         * that fails a pipeline because a request was slow is a snapshot
         * command somebody turns off.
         */
        return self::SUCCESS;
    }
}
