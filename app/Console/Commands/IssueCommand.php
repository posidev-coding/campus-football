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
use App\Support\RemoteBoard;
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
 *
 * WHICH BOARD is configuration, never a guess. With no `CFB_BOARD_URL` this
 * reads the local table, which is what it has always done; with one set every
 * verb below goes to that deployment's `/ops/issues` through
 * {@see RemoteBoard} instead. The two never mix in one invocation and there is
 * no fallback in either direction — a session that comments on the wrong board
 * and is told it succeeded is the whole reason the remote half exists, and a
 * fallback is that bug wearing a hat.
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

        if (RemoteBoard::configured()) {
            return $this->remotely($action, $by, $note);
        }

        $item = $this->resolveIssue($board);

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
     * The same verbs, against the board `CFB_BOARD_URL` names.
     *
     * Deliberately a separate path rather than a driver swapped in behind
     * {@see IssueBoard}: the two boards do not offer the same set of verbs, and
     * pretending they do is how `--effort` would look like it landed. What has
     * no remote route is REFUSED by name here, never dropped.
     */
    private function remotely(string $action, string $by, ?string $note): int
    {
        $remote = new RemoteBoard;

        if ($this->option('effort') !== null || array_filter((array) $this->option('label')) !== []) {
            // Silently dropping them is the failure mode this whole card is
            // about: a session that believes it sized a card it did not.
            $this->refuse(sprintf(
                'The board at %s has no route that sizes or labels, so --effort and --label would go nowhere. Drop '
                .'them and run it again; the size and the labels are the board\'s to set.',
                $remote->whereItLooked(),
            ));

            return self::FAILURE;
        }

        $handle = $this->remoteHandle($remote);

        if ($handle === null) {
            return self::FAILURE;
        }

        // Its own path, because the note must survive a failure and the
        // refusal has to carry it — see remoteComment().
        if ($action === 'comment') {
            return $this->remoteComment($remote, $handle, $by, $note);
        }

        $issue = match ($action) {
            'show' => $remote->brief($handle),
            'start' => $remote->start($handle, $by),
            'claim' => $remote->claim($handle, $by),
            'release' => $remote->release($handle, $by, $note),
            'review' => $this->remoteReview($remote, $handle, $by, $note),
            // Server-side policy, said again here only because the message is
            // better than a 404: there is no `done` route and there never will
            // be one. Merging earns Done.
            'done' => $this->refuse(
                'Done is a human\'s to give, and no board serves a route for it. A session\'s last move is `review` — '
                .'merging is what earns Done.'
            ),
            'ready', 'link' => $this->refuse(sprintf(
                '`%s` has no route on the board at %s. Over HTTP a session may show, start, claim, release, comment '
                .'and review — readying a brief and linking two cards are a human\'s, in the panel.',
                $action,
                $remote->whereItLooked(),
            )),
            default => $this->refuse("There is no `{$action}`. Try show, start, review, comment, claim or release."),
        };

        if ($issue === null) {
            // A local refusal has already said its piece; a remote one has not.
            if ($remote->refusal !== null) {
                $this->refuse($remote->refusal);
            }

            return self::FAILURE;
        }

        $this->render($issue, $action);

        return self::SUCCESS;
    }

    /**
     * Which issue, with no database to ask.
     *
     * The stored `branch` column is the authority locally, and it lives on the
     * OTHER board here — so there is nothing in this process to look a branch
     * up in. What survives is the reference at the FRONT of the branch name,
     * which `start` minted from that reference in the first place. A branch
     * carrying none refuses rather than sending a guess to a board that would
     * answer confidently about a different card.
     */
    private function remoteHandle(RemoteBoard $remote): ?string
    {
        $handle = $this->argument('issue');

        if ($handle !== null) {
            return trim((string) $handle);
        }

        $branch = $this->currentBranch();

        if ($branch === null) {
            return $this->refuse('No issue given, and no git branch to read one off. Pass CFB-12.');
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9]{0,9}-\d+)/', $branch, $matches) !== 1) {
            return $this->refuse(sprintf(
                'The branch `%s` does not start with a reference, and the board at %s is not this checkout\'s '
                .'database — there is no branch column here to read. Pass CFB-12.',
                $branch,
                $remote->whereItLooked(),
            ));
        }

        return $matches[1];
    }

    /**
     * Review, validated here before it travels.
     *
     * The same two constants the local path and the panel read, so a URL the
     * board would refuse is refused before a request is spent on it — and the
     * message is the one a session already knows.
     *
     * @return array<string, mixed>|null
     */
    private function remoteReview(RemoteBoard $remote, string $handle, string $by, ?string $note): ?array
    {
        $pr = trim((string) $this->option('pr'));

        if ($pr === ''
            || ! Str::startsWith($pr, ReviewWorkbookItem::URL_SCHEME)
            || mb_strlen($pr) > ReviewWorkbookItem::URL_MAX_LENGTH) {
            return $this->refuse('Pass --pr= with the https:// URL of the pull request.');
        }

        return $remote->review($handle, $by, $pr, $note);
    }

    /**
     * The trail comment, and the one refusal that must hand something back.
     *
     * A comment is the one verb whose whole content is written by the session
     * making the call — a failed `start` can simply be run again, but a failed
     * comment has a paragraph in it that exists nowhere else. So the note comes
     * BACK, on stdout, next to the refusal, for a human to paste.
     *
     * No spool file and no queue: a note replayed later lands out of order on a
     * trail whose whole value is sequence, and a spool nobody drains is a
     * second board again.
     */
    private function remoteComment(RemoteBoard $remote, string $handle, string $by, ?string $note): int
    {
        if ($note === null || $note === '') {
            $this->refuse('Pass --note= with what you want on the trail.');

            return self::FAILURE;
        }

        $issue = $remote->comment($handle, $by, $note);

        if ($issue !== null) {
            $this->render($issue, 'comment');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            // ONE document, still valid JSON and nothing but — the same
            // contract every other machine shape here holds. Two documents
            // would be a parse error at the other end, which loses the note
            // just as thoroughly as swallowing it.
            $this->output->writeln(json_encode(
                ['error' => $remote->refusal, 'note' => $note],
                JSON_UNESCAPED_SLASHES,
            ));

            return self::FAILURE;
        }

        $this->refuse((string) $remote->refusal);
        $this->line('  <fg=yellow>The note did not land. Here it is, to paste:</>');
        $this->newLine();
        $this->line('  '.$note);
        $this->newLine();

        return self::FAILURE;
    }

    /**
     * Which issue, and the one place branch inference lives.
     *
     * The stored `branch` column is the authority — a title edit cannot move
     * it. The leading-reference fallback covers a branch a human cut by hand
     * before `start` ever ran. Neither matching names BOTH attempts and exits
     * non-zero, because guessing is how a session works the wrong card.
     *
     * Every refusal here NAMES THE BOARD it searched. A checkout pointed at a
     * different database says "nothing matches" about a card that plainly
     * exists, and a reader sent to check the reference, the id and the key
     * checks three things that are all correct. The board is the one thing
     * that is wrong, so it is the thing the message says.
     */
    private function resolveIssue(IssueBoard $board): ?WorkbookItem
    {
        $handle = $this->argument('issue');

        if ($handle !== null) {
            return WorkbookItem::resolve((string) $handle)
                ?? $this->refuse(sprintf(
                    'Nothing matches "%s" on the board this checkout reads (%s). A card filed on another '
                    .'board never resolves here; if it is this one, try CFB-12, a bare id, or the advisor\'s key.',
                    $handle,
                    $board->whereItLooked(),
                ));
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

        return $leading ?? $this->refuse(sprintf(
            'No issue on the board this checkout reads (%s) stores the branch `%s`, and its name does not '
            .'start with a reference. Pass CFB-12.',
            $board->whereItLooked(),
            $branch,
        ));
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
