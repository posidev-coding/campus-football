<?php

namespace App\Console\Commands;

use App\Models\WorkbookEvent;
use App\Support\IssueBoard;
use App\Support\RemoteBoard;
use Illuminate\Console\Command;

/**
 * The board, at a terminal — what is open, what is ready, what you are holding.
 *
 * The read half of the pair. `cfb:issue` writes; this one only looks, the same
 * line `cfb:doctor` and `cfb:telemetry` already draw, and it means a session can
 * find its next piece of work without any way to change the board by accident.
 *
 * WHICH BOARD follows `CFB_BOARD_URL`, exactly as `cfb:issue` does. Remotely
 * there is ONE list — the ready queue — because that is the only list a client
 * composing its own URL can reach, so every other filter is refused by name
 * rather than answered with a different list under the same flags.
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

        if (RemoteBoard::configured()) {
            return $this->remotely();
        }

        $issues = $board->list([
            'status' => (array) $this->option('status'),
            'severity' => (array) $this->option('severity'),
            'effort' => (array) $this->option('effort'),
            'label' => (array) $this->option('label'),
            'ready' => (bool) $this->option('ready'),
            'mine' => $this->option('mine') ? $by : null,
        ], max(1, (int) $this->option('limit')));

        return $this->show($issues, $board->whereItLooked());
    }

    /**
     * The ready queue, off the board a URL names.
     *
     * Every other filter is refused rather than dropped. Answering `--mine` or
     * `--severity=critical` with the whole ready queue is a list a session
     * would trust and a filter that never ran, which is worse than no answer.
     */
    private function remotely(): int
    {
        $remote = new RemoteBoard;

        $unsupported = array_keys(array_filter([
            '--status' => (array) $this->option('status') !== [],
            '--severity' => (array) $this->option('severity') !== [],
            '--effort' => (array) $this->option('effort') !== [],
            '--label' => array_filter((array) $this->option('label')) !== [],
            '--mine' => (bool) $this->option('mine'),
        ]));

        if ($unsupported !== [] || ! $this->option('ready')) {
            return $this->refuse(sprintf(
                'The board at %s serves ONE list over HTTP — the ready queue, with a limit. `cfb:issues --ready` '
                .'reads it; %s has no remote route, and the same flags answering a different list is how a session '
                .'trusts a filter that never ran.',
                $remote->whereItLooked(),
                $unsupported === [] ? 'a bare list' : implode(', ', $unsupported),
            ));
        }

        $issues = $remote->ready(max(1, (int) $this->option('limit')));

        return $issues === null
            ? $this->refuse((string) $remote->refusal)
            : $this->show($issues, $remote->whereItLooked());
    }

    /**
     * One board's worth of rows, however they were read.
     *
     * @param  list<array<string, mixed>>  $issues
     */
    private function show(array $issues, string $where): int
    {
        if ($this->option('json')) {
            $this->output->writeln(json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($issues === []) {
            // NAMED, for the same reason `cfb:issue`'s refusals are: an empty
            // answer from a checkout pointed at another board is unreadable
            // otherwise. The count separates "this board is empty" from "your
            // filters are".
            $this->newLine();
            $this->line('  <fg=gray>Nothing matches on the board this checkout reads ('.$where.').</>');
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

    /**
     * Says why, non-zero — and in `--json` mode still valid JSON and nothing
     * but, the same contract the rest of the machine shape holds.
     */
    private function refuse(string $message): int
    {
        if ($this->option('json')) {
            $this->output->writeln(json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
