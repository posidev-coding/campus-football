<?php

namespace App\Console\Commands;

use App\Models\WorkbookEvent;
use App\Support\IssueBoard;
use Illuminate\Console\Command;

/**
 * The board, at a terminal — what is open, what is ready, what you are holding.
 *
 * The read half of the pair. `cfb:issue` writes; this one only looks, the same
 * line `cfb:doctor` and `cfb:telemetry` already draw, and it means a session can
 * find its next piece of work without any way to change the board by accident.
 */
class IssuesCommand extends Command
{
    protected $signature = 'cfb:issues
        {--status=* : inbox, planned, in_progress, in_review, done, dismissed}
        {--severity=* : critical, high, medium, low}
        {--label=* : Only issues carrying one of these}
        {--effort=* : s, m or l}
        {--ready : Only issues marked ready for an agent to start}
        {--mine : Only what --as currently holds}
        {--as=agent:local : Whose claim --mine means}
        {--limit=25}
        {--json : The machine shape instead of a terminal read}';

    protected $description = 'List issues on the workbook board';

    public function handle(IssueBoard $board): int
    {
        $by = trim((string) $this->option('as')) ?: WorkbookEvent::ACTOR_AGENT;

        $issues = $board->list([
            'status' => (array) $this->option('status'),
            'severity' => (array) $this->option('severity'),
            'effort' => (array) $this->option('effort'),
            'label' => (array) $this->option('label'),
            'ready' => (bool) $this->option('ready'),
            'mine' => $this->option('mine') ? $by : null,
        ], max(1, (int) $this->option('limit')));

        if ($this->option('json')) {
            $this->output->writeln(json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($issues === []) {
            $this->newLine();
            $this->line('  <fg=gray>Nothing matches.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($issues as $issue) {
            $this->line(sprintf(
                '  <fg=cyan>%-10s</> %-8s %-12s %s',
                $issue['reference'],
                $issue['severity'],
                $issue['status'],
                $issue['title'],
            ));

            $trailing = array_filter([
                $issue['effort'],
                $issue['labels'] === null ? null : implode(', ', $issue['labels']),
                $issue['claim'] === null ? null : "held by {$issue['claim']['by']}",
                $issue['ready_at'] === null ? null : 'ready',
            ]);

            if ($trailing !== []) {
                $this->line('             <fg=gray>'.implode(' · ', $trailing).'</>');
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>'.count($issues).' shown</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
