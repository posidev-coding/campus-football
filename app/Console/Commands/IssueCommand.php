<?php

namespace App\Console\Commands;

use App\Actions\ClaimWorkbookItem;
use App\Actions\DescribeWorkbookItem;
use App\Actions\LinkWorkbookItems;
use App\Actions\MoveWorkbookItem;
use App\Actions\ReadyWorkbookItem;
use App\Actions\RecordWorkbookEvent;
use App\Actions\ReviewWorkbookItem;
use App\Actions\StartWorkbookItem;
use App\Enums\WorkbookEffort;
use App\Enums\WorkbookLinkType;
use App\Enums\WorkbookStatus;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use App\Support\IssueBoard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * One issue, at a terminal — the surface a local Claude Code session works
 * through.
 *
 * A local session has the database, artisan and git, so it needs no HTTP: this
 * command and `/ops/issues` are two skins on one {@see IssueBoard}, the house
 * pattern, so a terminal and a cloud routine cannot disagree about the board.
 *
 * Read and write are two commands rather than one or seven. Seven classes would
 * duplicate branch inference seven times; one class carrying `--pr` for actions
 * that ignore it is muddy. It is the same line `cfb:doctor` and `cfb:telemetry`
 * already draw.
 *
 * It NEVER touches the working tree. `start` PRINTS `git switch -c …` and does
 * not run it — a command that reaches into a repository is one that will
 * eventually do it on the wrong branch, which is `AdvisorSetupCommand`'s
 * "prints, never writes" rule applied to git.
 */
class IssueCommand extends Command
{
    protected $signature = 'cfb:issue
        {action=show : show|start|ready|review|done|comment|claim|release|link}
        {issue? : CFB-12, a bare id, or the advisor key — omit to read it off the current branch}
        {--note= : One line for the activity trail}
        {--effort= : s, m or l}
        {--label=* : Add a label; repeatable}
        {--pr= : The pull request URL, for review}
        {--to= : The other issue, for link}
        {--relation=relates_to : blocks|blocked_by|relates_to|duplicates|duplicated_by}
        {--as=agent:local : Who is acting, recorded on the trail}
        {--json : The machine shape instead of a terminal read}';

    protected $description = 'Work one issue: show it, start it, comment on it, hand it to review';

    /** Everything but a read may size and label on the way past. */
    private const READ_ONLY = ['show'];

    public function handle(IssueBoard $board): int
    {
        $action = (string) $this->argument('action');
        $by = trim((string) $this->option('as')) ?: WorkbookEvent::ACTOR_AGENT;
        $note = $this->option('note') === null ? null : trim((string) $this->option('note'));

        $item = $this->resolveIssue();

        if ($item === null) {
            return self::FAILURE;
        }

        if (! in_array($action, self::READ_ONLY, true) && ! $this->annotate($item, $by)) {
            return self::FAILURE;
        }

        $outcome = match ($action) {
            'show' => $item->refresh(),
            'start' => $this->begin($item, $by),
            'ready' => app(ReadyWorkbookItem::class)->handle($item, $by, $note),
            'review' => $this->review($item, $by, $note),
            'done' => $this->done($item, $by, $note),
            'comment' => $this->remark($item, $by, $note),
            'claim' => app(ClaimWorkbookItem::class)->handle($item, $by) ?? $this->refuse($this->heldBy($item)),
            'release' => app(ClaimWorkbookItem::class)->release($item, $by, $note) ?? $this->refuse($this->heldBy($item)),
            'link' => $this->link($item, $by),
            default => $this->refuse("There is no `{$action}`. Try show, start, ready, review, done, comment, claim, release or link."),
        };

        if ($outcome === null) {
            return self::FAILURE;
        }

        $this->render($board->one($outcome->refresh()), $action);

        return self::SUCCESS;
    }

    /**
     * Which issue, and the one place branch inference lives.
     *
     * The stored `branch` column is the authority — a title edit cannot move
     * it. The leading-reference fallback covers a branch a human cut by hand
     * before `start` ever ran. Neither matching names BOTH attempts and exits
     * non-zero, because guessing is how a session works the wrong card.
     */
    private function resolveIssue(): ?WorkbookItem
    {
        $handle = $this->argument('issue');

        if ($handle !== null) {
            return WorkbookItem::resolve((string) $handle)
                ?? $this->refuse("Nothing matches \"{$handle}\" — try CFB-12, a bare id, or the advisor's key.");
        }

        $branch = $this->currentBranch();

        if ($branch === null) {
            return $this->refuse('No issue given, and no git branch to read one off. Pass CFB-12.');
        }

        $stored = WorkbookItem::query()->where('branch', $branch)->first();

        if ($stored !== null) {
            return $stored;
        }

        $leading = preg_match('/^([A-Za-z][A-Za-z0-9]{0,9}-\d+)/', $branch, $matches) === 1
            ? WorkbookItem::findByReference($matches[1])
            : null;

        return $leading ?? $this->refuse(
            "No issue stores the branch `{$branch}`, and its name does not start with a reference. Pass CFB-12."
        );
    }

    /** The branch this working tree is on, or null if there is no answer. */
    private function currentBranch(): ?string
    {
        // The Process FACADE, never shell_exec, so a test can fake it.
        $result = Process::run('git rev-parse --abbrev-ref HEAD');

        if (! $result->successful()) {
            return null;
        }

        $branch = trim($result->output());

        // Detached HEAD answers the literal string, which is not a branch.
        return $branch === '' || $branch === 'HEAD' ? null : $branch;
    }

    /** `--effort` and `--label` ride along with whatever you are doing. */
    private function annotate(WorkbookItem $item, string $by): bool
    {
        $raw = $this->option('effort');
        $effort = null;

        if ($raw !== null && ($effort = WorkbookEffort::tryFrom((string) $raw)) === null) {
            $this->refuse("`{$raw}` is not a size. Use s, m or l.");

            return false;
        }

        /** @var list<string> $labels */
        $labels = array_values(array_filter((array) $this->option('label')));

        app(DescribeWorkbookItem::class)->handle($item, $by, $effort, $labels);

        return true;
    }

    private function begin(WorkbookItem $item, string $by): ?WorkbookItem
    {
        return app(StartWorkbookItem::class)->handle($item, $by) ?? $this->refuse($this->heldBy($item));
    }

    private function review(WorkbookItem $item, string $by, ?string $note): ?WorkbookItem
    {
        $pr = trim((string) $this->option('pr'));

        // The rule itself lives on the action, because the panel's Review
        // field validates on the same two constants — one doorway accepting a
        // URL the other refuses is the board and the terminal disagreeing.
        if ($pr === ''
            || ! Str::startsWith($pr, ReviewWorkbookItem::URL_SCHEME)
            || mb_strlen($pr) > ReviewWorkbookItem::URL_MAX_LENGTH) {
            return $this->refuse('Pass --pr= with the https:// URL of the pull request.');
        }

        return app(ReviewWorkbookItem::class)->handle($item, $by, $pr, $note) ?? $this->refuse($this->heldBy($item));
    }

    private function done(WorkbookItem $item, string $by, ?string $note): ?WorkbookItem
    {
        if (WorkbookEvent::isAgent($by)) {
            return $this->refuse(
                'Done is a human\'s to give. A session\'s last move is `review` — merging is what earns Done.'
            );
        }

        return app(MoveWorkbookItem::class)->handle($item->id, WorkbookStatus::Done, actor: $by, note: $note);
    }

    /**
     * NOT `comment()`. `Illuminate\Console\Command::comment()` is a public
     * output helper, and a private override of it is a fatal error at
     * class-load time — the data-model rule about naming a helper after a
     * base-class method, met in the wild.
     */
    private function remark(WorkbookItem $item, string $by, ?string $note): ?WorkbookItem
    {
        if ($note === null || $note === '') {
            return $this->refuse('Pass --note= with what you want on the trail.');
        }

        app(RecordWorkbookEvent::class)->handle($item, WorkbookEvent::COMMENTED, actor: $by, note: $note);

        return $item;
    }

    private function link(WorkbookItem $item, string $by): ?WorkbookItem
    {
        $handle = trim((string) $this->option('to'));
        $other = $handle === '' ? null : WorkbookItem::resolve($handle);

        if ($other === null) {
            return $this->refuse('Pass --to= with the other issue — CFB-12, a bare id, or its key.');
        }

        $relation = WorkbookLinkType::tryFrom((string) $this->option('relation'));

        if ($relation === null) {
            return $this->refuse('--relation must be blocks, blocked_by, relates_to, duplicates or duplicated_by.');
        }

        $linker = app(LinkWorkbookItems::class);

        return $linker->handle($item, $other, $relation, $by) === null
            ? $this->refuse((string) $linker->refusal)
            : $item;
    }

    private function heldBy(WorkbookItem $item): string
    {
        $until = $item->claim_expires_at?->diffForHumans() ?? 'no stated end';

        return "{$item->reference} is held by {$item->claimed_by} ({$until}). Nothing here steals a claim.";
    }

    /** Says why, and returns null so every caller can `?? $this->refuse(...)`. */
    private function refuse(string $message): null
    {
        if ($this->option('json')) {
            // Still valid JSON and nothing but, so a machine on the other end
            // gets an answer rather than a parse error.
            $this->output->writeln(json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->error($message);
        }

        return null;
    }

    /** @param  array<string, mixed>  $issue */
    private function render(array $issue, string $action): void
    {
        if ($this->option('json')) {
            $this->output->writeln(json_encode($issue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->newLine();
        $this->line("  <fg=cyan>{$issue['reference']}</>  {$issue['title']}");
        $this->line(sprintf(
            '  <fg=gray>%s · %s · %s%s</>',
            $issue['status'],
            $issue['severity'],
            $issue['effort'] ?? 'unsized',
            $issue['labels'] === null ? '' : ' · '.implode(', ', $issue['labels']),
        ));

        if ($issue['claim'] !== null) {
            $this->line("  <fg=gray>held by {$issue['claim']['by']}</>");
        }

        if ($issue['branch'] !== null) {
            $this->line("  <fg=gray>branch</> {$issue['branch']}");
        }

        if ($action === 'start') {
            // PRINTED, never run. See the class docblock.
            $this->newLine();
            $this->line("  <fg=green>git switch -c {$issue['branch']}</>");
        }

        foreach ($issue['links'] as $link) {
            $this->line(sprintf('  <fg=gray>%s</> %s  %s', $link['label'], $link['reference'], $link['title']));
        }

        if ($issue['prompt'] !== null && $action === 'show') {
            $this->newLine();
            $this->line('  <fg=gray>'.str_replace("\n", "\n  ", $issue['prompt']).'</>');
        }

        if ($issue['trail'] !== []) {
            $this->newLine();

            foreach ($issue['trail'] as $event) {
                $this->line(sprintf(
                    '  <fg=gray>%s</> %-10s %s%s',
                    Str::limit((string) $event['at'], 16, ''),
                    $event['kind'],
                    $event['actor'],
                    $event['note'] === null ? '' : " — {$event['note']}",
                ));
            }
        }

        $this->newLine();
    }
}
