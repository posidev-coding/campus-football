<?php

namespace App\Console\Commands;

use App\Support\CoverageReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The coverage report, at the terminal.
 *
 * Same rows as the admin panel's Sync Health page — one CoverageReport, two
 * surfaces, so they can never disagree about whether the data is whole. Exits
 * non-zero when any check fails, which makes it usable as a deploy gate or a
 * cron alert without any further wiring.
 */
#[Signature('cfb:doctor')]
#[Description('Check sync coverage: expected vs actual, with the remedy for each gap')]
class DoctorCommand extends Command
{
    public function handle(CoverageReport $report): int
    {
        $checks = $report->checks();

        foreach ($checks as $check) {
            $mark = match ($check['status']) {
                CoverageReport::OK => '<fg=green>✓</>',
                CoverageReport::WARN => '<fg=yellow>!</>',
                default => '<fg=red>✗</>',
            };

            $this->line(sprintf('  %s %-28s %s', $mark, $check['label'], $check['detail']));

            if ($check['status'] !== CoverageReport::OK && $check['remedy'] !== null) {
                $this->line("      <fg=gray>php artisan {$check['remedy']}</>");
            }
        }

        $failing = collect($checks)->where('status', CoverageReport::FAIL)->count();

        $this->newLine();

        if ($failing > 0) {
            $this->line("  <fg=red>{$failing} failing</>");

            return self::FAILURE;
        }

        $this->line('  <fg=gray>all checks passing</>');

        return self::SUCCESS;
    }
}
