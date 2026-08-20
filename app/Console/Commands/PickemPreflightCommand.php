<?php

namespace App\Console\Commands;

use App\Support\PickemPreflight;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The flip check: is Pick'em ready for people who are not admins?
 *
 * Deliberately read-only. It never flips the `pickem` flag and never stocks
 * anything — it prints what is true and exits non-zero when something is
 * not, so it works as a deploy gate or a cron alert with no further wiring.
 * Turning the flag on stays a person's decision and a one-line commit.
 *
 * Same renderer as cfb:doctor, because it is the same kind of answer.
 */
#[Signature('pickem:preflight')]
#[Description('Check whether Pick\'em is ready for the pickem flag to be flipped on')]
class PickemPreflightCommand extends Command
{
    public function handle(PickemPreflight $preflight): int
    {
        $checks = $preflight->checks();

        $this->newLine();

        foreach ($checks as $check) {
            $mark = match ($check['status']) {
                PickemPreflight::OK => '<fg=green>✓</>',
                PickemPreflight::WARN => '<fg=yellow>!</>',
                default => '<fg=red>✗</>',
            };

            $this->line(sprintf('  %s %-22s %s', $mark, $check['label'], $check['detail']));

            if ($check['status'] !== PickemPreflight::OK && $check['remedy'] !== null) {
                $this->line("      <fg=gray>php artisan {$check['remedy']}</>");
            }
        }

        $failing = collect($checks)->where('status', PickemPreflight::FAIL)->count();

        $this->newLine();

        if ($failing > 0) {
            $this->line("  <fg=red>{$failing} blocking</> — do not flip the flag yet.");

            return self::FAILURE;
        }

        $this->line('  <fg=gray>clear for the flip: PICKEM_OPEN=true, then config:clear and pennant:purge pickem</>');

        return self::SUCCESS;
    }
}
