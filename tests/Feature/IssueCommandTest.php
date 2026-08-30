<?php

use App\Actions\ClaimWorkbookItem;
use App\Enums\WorkbookEffort;
use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/*
 * The surface a local Claude Code session works an issue through.
 *
 * Two things this file exists to hold. `--json` must be valid JSON and NOTHING
 * but — a stray console line makes it unparseable at the other end, the same
 * contract TelemetryTest holds. And NOTHING here may steal a claim: the whole
 * point of a lease is that two sessions cannot work one issue, and a command
 * that quietly takes one over is worse than no claim at all.
 *
 * RefreshDatabase resets AUTO_INCREMENT, so `CFB-1` is a different item in
 * every test. Every reference below is read off the row.
 */

beforeEach(function () {
    // Nothing in this file may reach the real repository. A fake also makes
    // branch inference drivable, which shell_exec never would be.
    Process::preventStrayProcesses();
    Process::fake(['git rev-parse *' => Process::result('main')]);

    // This whole file is the LOCAL board. Pinned rather than assumed: a
    // developer with a real CFB_BOARD_URL would otherwise send every
    // assertion below at a deployment. The remote half is RemoteBoardTest.
    config(['cfb.board_url' => null]);
});

/** An issue an agent is allowed to pick up. */
function readyIssue(array $overrides = []): WorkbookItem
{
    $item = WorkbookItem::factory()->create([
        'key' => 'picks-n-plus-one',
        'status' => WorkbookStatus::Planned,
        ...$overrides,
    ]);

    $item->forceFill(['ready_at' => now()])->save();

    return $item->refresh();
}

/**
 * The board this test process is pointed at, spelled the way a refusal spells
 * it. Read off the connection rather than restated, or the assertion would
 * pass on a hardcoded string that no longer matches what anyone reads.
 */
function thisBoard(): string
{
    $connection = WorkbookItem::query()->getConnection();

    return $connection->getName().'/'.$connection->getDatabaseName();
}

/** Run the command and decode its machine shape. */
function issueJson(string $action, array $parameters = []): array
{
    Artisan::call('cfb:issue', ['action' => $action, '--json' => true, ...$parameters]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

describe('the machine shape', function () {
    it('is valid JSON and nothing but JSON', function () {
        // The `/ops/issues` routes will serve these arrays verbatim, and a
        // session pipes this straight into a parser.
        $item = readyIssue();

        $issue = issueJson('show', ['issue' => $item->reference]);

        expect($issue['reference'])->toBe($item->reference)
            ->and($issue['key'])->toBe('picks-n-plus-one')
            ->and($issue)->toHaveKeys(['title', 'body', 'prompt', 'severity', 'effort', 'labels', 'status', 'branch', 'claim', 'trail']);
    });

    it('carries null through rather than softening it', function () {
        // `null` means no data. A caller skips it; it never reads `[]` or `''`
        // as an answer.
        $item = WorkbookItem::factory()->create(['prompt' => null]);

        $issue = issueJson('show', ['issue' => $item->reference]);

        expect($issue['prompt'])->toBeNull()
            ->and($issue['effort'])->toBeNull()
            ->and($issue['labels'])->toBeNull()
            ->and($issue['branch'])->toBeNull()
            ->and($issue['claim'])->toBeNull();
    });
});

describe('finding the issue', function () {
    it('takes a reference, a bare id or the advisor key', function () {
        $item = readyIssue();

        expect(issueJson('show', ['issue' => $item->reference])['reference'])->toBe($item->reference)
            ->and(issueJson('show', ['issue' => (string) $item->id])['reference'])->toBe($item->reference)
            ->and(issueJson('show', ['issue' => 'picks-n-plus-one'])['reference'])->toBe($item->reference);
    });

    it('reads it off the branch the row stores', function () {
        // The stored column is the AUTHORITY, because a title edit cannot move
        // it and a reference in the name can be typed wrong.
        $item = readyIssue();
        $item->forceFill(['branch' => 'CFB-writing-this-by-hand'])->save();
        Process::fake(['git rev-parse *' => Process::result('CFB-writing-this-by-hand')]);

        expect(issueJson('show')['reference'])->toBe($item->reference);
    });

    it('falls back to a reference at the front of the branch name', function () {
        // Covers a branch a human cut before `cfb:issue start` ever ran.
        $item = readyIssue();
        Process::fake(['git rev-parse *' => Process::result($item->reference.'-cut-by-hand')]);

        expect(issueJson('show')['reference'])->toBe($item->reference);
    });

    it('names both attempts and refuses rather than guessing', function () {
        readyIssue();
        Process::fake(['git rev-parse *' => Process::result('some-other-work')]);

        $exit = Artisan::call('cfb:issue', ['action' => 'show', '--json' => true]);
        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($answer['error'])->toContain('some-other-work')
            ->and($answer['error'])->toContain('does not start with a reference')
            // The third attempt a reader has to make is "am I even on the
            // right board", and only the message can answer it.
            ->and($answer['error'])->toContain(thisBoard());
    });

    it('names the board it searched, so a pointing error cannot read as a missing card', function () {
        /*
         * A checkout wired to another database answers a card it has never
         * heard of in the same words it uses for one that was withdrawn. The
         * message sent the reader to check the reference, the id and the key —
         * three things that were all correct — and never mentioned the board.
         *
         * The COUNT is half the fix: it says the lookup ran against a
         * populated table rather than failing to reach one.
         */
        $item = readyIssue();
        WorkbookItem::factory()->count(2)->create();

        // Shaped exactly like a reference, for a row this board does not have.
        $missing = str_replace('-'.$item->id, '-'.($item->id + 9_000), $item->reference);

        $exit = Artisan::call('cfb:issue', ['action' => 'show', 'issue' => $missing, '--json' => true]);
        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($answer['error'])->toContain($missing)
            ->and($answer['error'])->toContain(thisBoard())
            ->and($answer['error'])->toContain('3 items')
            ->and($answer['error'])->toContain('another board')
            // The format hint stays; it is useful once the board is named.
            ->and($answer['error'])->toContain('the advisor\'s key');
    });

    it('names one board and never reaches for a second', function () {
        // A fallback that searched somewhere else would make two sessions
        // believe different things about one reference. The board is stated
        // once, in the singular, and that is the whole contract.
        readyIssue();

        Artisan::call('cfb:issue', ['action' => 'show', 'issue' => 'not-on-this-board', '--json' => true]);
        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(substr_count($answer['error'], thisBoard()))->toBe(1);
    });

    it('says which board came up empty when the list has nothing to show', function () {
        // Same false conclusion one command over: an empty queue on the wrong
        // board reads exactly like an empty queue.
        Artisan::call('cfb:issues', ['--ready' => true]);

        expect(Artisan::output())->toContain(thisBoard());
    });

    it('treats a detached HEAD as no answer at all', function () {
        readyIssue();
        Process::fake(['git rev-parse *' => Process::result('HEAD')]);

        expect(Artisan::call('cfb:issue', ['action' => 'show', '--json' => true]))->toBe(1);
    });
});

describe('starting work', function () {
    it('claims, mints the branch and prints the git line it will not run', function () {
        // A command that reaches into the working tree is one that will one
        // day do it on the wrong branch.
        $item = readyIssue();

        Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference]);
        $output = Artisan::output();

        $fresh = $item->fresh();

        expect($fresh->status)->toBe(WorkbookStatus::InProgress)
            ->and($fresh->branch)->toBe($item->branchName())
            ->and($fresh->claimed_by)->toBe(WorkbookEvent::ACTOR_AGENT)
            ->and($fresh->started_at)->not->toBeNull()
            ->and($output)->toContain("git switch -c {$fresh->branch}");
    });

    it('never runs git itself', function () {
        // `Process::preventStrayProcesses()` in the beforeEach would already
        // fail an unfaked call; this proves the specific one nobody wants.
        $item = readyIssue();

        Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference]);

        Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'switch'));
        Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'checkout'));
    });

    it('is idempotent for whoever holds it', function () {
        $item = readyIssue();

        Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference]);
        $branch = $item->fresh()->branch;

        expect(Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference]))->toBe(0)
            // Minted ONCE. It is the durable copy of the reference and it is
            // already in git.
            ->and($item->fresh()->branch)->toBe($branch)
            ->and($item->fresh()->events()->where('kind', WorkbookEvent::STARTED)->count())->toBe(1);
    });

    it('refuses a thief, and does not take the claim on the way past', function () {
        $item = readyIssue();
        Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference, '--as' => 'cloud:nightly']);

        $exit = Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference]);

        expect($exit)->toBe(1)
            ->and($item->fresh()->claimed_by)->toBe('cloud:nightly');
    });
});

describe('the claim', function () {
    it('refuses a second claim and leaves the holder alone', function () {
        $item = readyIssue();

        Artisan::call('cfb:issue', ['action' => 'claim', 'issue' => $item->reference, '--as' => 'cloud:nightly']);
        $exit = Artisan::call('cfb:issue', ['action' => 'claim', 'issue' => $item->reference, '--as' => 'agent:local']);

        expect($exit)->toBe(1)
            ->and($item->fresh()->claimed_by)->toBe('cloud:nightly');
    });

    it('frees a lapsed lease without a reaper', function () {
        /*
         * The guarantee lives in the WHERE clause, not in the order these
         * calls happen — `.ai/rules/tests.md` is explicit that sequential
         * calls are not concurrent writers. So the lease is set BY HAND to
         * something already expired, which is what the clause actually reads.
         */
        $item = readyIssue();
        $item->forceFill([
            'claimed_at' => now()->subHours(4),
            'claimed_by' => 'cloud:died-mid-run',
            'claim_expires_at' => now()->subHours(2),
        ])->save();

        $claimed = app(ClaimWorkbookItem::class)->handle($item->refresh(), 'agent:local');

        expect($claimed)->not->toBeNull()
            ->and($item->fresh()->claimed_by)->toBe('agent:local');
    });

    it('holds against a live lease, which is the whole point', function () {
        $item = readyIssue();
        $item->forceFill([
            'claimed_at' => now(),
            'claimed_by' => 'cloud:nightly',
            'claim_expires_at' => now()->addMinutes(ClaimWorkbookItem::LEASE_MINUTES),
        ])->save();

        expect(app(ClaimWorkbookItem::class)->handle($item->refresh(), 'agent:local'))->toBeNull()
            ->and($item->fresh()->claimed_by)->toBe('cloud:nightly');
    });

    it('hands back only what you hold', function () {
        $item = readyIssue();
        Artisan::call('cfb:issue', ['action' => 'claim', 'issue' => $item->reference, '--as' => 'cloud:nightly']);

        expect(Artisan::call('cfb:issue', ['action' => 'release', 'issue' => $item->reference, '--as' => 'agent:local']))->toBe(1)
            ->and($item->fresh()->claimed_by)->toBe('cloud:nightly')
            ->and(Artisan::call('cfb:issue', ['action' => 'release', 'issue' => $item->reference, '--as' => 'cloud:nightly']))->toBe(0)
            ->and($item->fresh()->claimed_by)->toBeNull();
    });

    it('takes the worst ready issue nobody holds', function () {
        $low = readyIssue(['key' => 'low-one', 'severity' => WorkbookSeverity::Low]);
        $critical = readyIssue(['key' => 'critical-one', 'severity' => WorkbookSeverity::Critical]);
        // Planned but NOT ready: the brief is not finished, so no routine may
        // start it at 3am.
        WorkbookItem::factory()->create(['key' => 'half-written', 'status' => WorkbookStatus::Planned, 'severity' => WorkbookSeverity::Critical]);

        $first = app(ClaimWorkbookItem::class)->next('cloud:nightly');
        $second = app(ClaimWorkbookItem::class)->next('agent:local');

        expect($first->id)->toBe($critical->id)
            ->and($second->id)->toBe($low->id)
            // The third call finds nothing rather than handing out a held one.
            ->and(app(ClaimWorkbookItem::class)->next('agent:other'))->toBeNull();
    });

    it('narrows the ready queue to the labels a routine asked for', function () {
        readyIssue(['key' => 'not-for-you', 'severity' => WorkbookSeverity::Critical]);
        $wanted = readyIssue(['key' => 'for-you', 'severity' => WorkbookSeverity::Low, 'labels' => ['Frontend']]);

        expect(app(ClaimWorkbookItem::class)->next('cloud:nightly', ['frontend'])->id)->toBe($wanted->id);
    });
});

describe('what an agent may never do', function () {
    it('refuses Done to an agent and to a cloud routine', function () {
        // If a session could close its own work, In review is decorative and
        // the trail fills with sessions marking themselves complete.
        $item = readyIssue();

        expect(Artisan::call('cfb:issue', ['action' => 'done', 'issue' => $item->reference, '--as' => 'agent:local']))->toBe(1)
            ->and(Artisan::call('cfb:issue', ['action' => 'done', 'issue' => $item->reference, '--as' => 'cloud:nightly']))->toBe(1)
            ->and($item->fresh()->status)->toBe(WorkbookStatus::Planned);
    });

    it('lets a human close it', function () {
        $item = readyIssue();

        expect(Artisan::call('cfb:issue', ['action' => 'done', 'issue' => $item->reference, '--as' => 'human']))->toBe(0)
            ->and($item->fresh()->status)->toBe(WorkbookStatus::Done)
            ->and($item->fresh()->completed_at)->not->toBeNull();
    });
});

describe('handing it on', function () {
    it('stops at the pull request, and gives the claim back', function () {
        $item = readyIssue();
        Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference]);

        $exit = Artisan::call('cfb:issue', [
            'action' => 'review',
            'issue' => $item->reference,
            '--pr' => 'https://github.com/posidev-coding/campus-football/pull/9',
        ]);

        $fresh = $item->fresh();

        expect($exit)->toBe(0)
            ->and($fresh->status)->toBe(WorkbookStatus::InReview)
            ->and($fresh->pr_url)->toBe('https://github.com/posidev-coding/campus-football/pull/9')
            // A session's terminal transition. The human merges.
            ->and($fresh->claimed_by)->toBeNull();
    });

    it('will not take a review without a pull request to point at', function () {
        // In review without a PR is a lie.
        $item = readyIssue();

        expect(Artisan::call('cfb:issue', ['action' => 'review', 'issue' => $item->reference]))->toBe(1)
            ->and(Artisan::call('cfb:issue', ['action' => 'review', 'issue' => $item->reference, '--pr' => 'javascript:alert(1)']))->toBe(1)
            ->and($item->fresh()->status)->toBe(WorkbookStatus::Planned);
    });

    it('writes the plan back onto the trail', function () {
        $item = readyIssue();

        Artisan::call('cfb:issue', [
            'action' => 'comment',
            'issue' => $item->reference,
            '--note' => 'Adding the eager load to pickem-home, then a query-count test.',
        ]);

        expect($item->fresh()->events()->where('kind', WorkbookEvent::COMMENTED)->sole()->note)
            ->toBe('Adding the eager load to pickem-home, then a query-count test.');
    });

    it('will not write an empty comment', function () {
        $item = readyIssue();

        expect(Artisan::call('cfb:issue', ['action' => 'comment', 'issue' => $item->reference]))->toBe(1)
            ->and($item->fresh()->events()->where('kind', WorkbookEvent::COMMENTED)->count())->toBe(0);
    });
});

describe('sizing and labeling ride along', function () {
    it('sizes and labels whatever you were already doing', function () {
        $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned]);

        Artisan::call('cfb:issue', [
            'action' => 'ready',
            'issue' => $item->reference,
            '--effort' => 'm',
            '--label' => ['Slow Query', 'performance'],
        ]);

        $fresh = $item->fresh();

        expect($fresh->effort)->toBe(WorkbookEffort::Medium)
            ->and($fresh->labels)->toBe(['slow-query', 'performance'])
            ->and($fresh->ready_at)->not->toBeNull()
            ->and($fresh->events()->pluck('kind')->all())
            ->toBe([WorkbookEvent::FILED, WorkbookEvent::SIZED, WorkbookEvent::LABELED, WorkbookEvent::READIED]);
    });

    it('adds labels rather than replacing a human\'s', function () {
        // A session that could clear a human's labels is one that quietly
        // loses a filter.
        $item = WorkbookItem::factory()->create(['labels' => ['keep-me']]);

        Artisan::call('cfb:issue', ['action' => 'comment', 'issue' => $item->reference, '--note' => 'hi', '--label' => ['and-this']]);

        expect($item->fresh()->labels)->toBe(['keep-me', 'and-this']);
    });

    it('refuses a size that is not a size', function () {
        $item = WorkbookItem::factory()->create();

        expect(Artisan::call('cfb:issue', ['action' => 'ready', 'issue' => $item->reference, '--effort' => 'xl']))->toBe(1)
            ->and($item->fresh()->ready_at)->toBeNull();
    });

    it('leaves a read alone', function () {
        // `show --effort=s` writing would make a read command a write command.
        $item = WorkbookItem::factory()->create();

        Artisan::call('cfb:issue', ['action' => 'show', 'issue' => $item->reference, '--effort' => 's']);

        expect($item->fresh()->effort)->toBeNull();
    });
});

describe('the list', function () {
    it('shows the ready queue worst first', function () {
        $low = readyIssue(['key' => 'low-one', 'severity' => WorkbookSeverity::Low]);
        $critical = readyIssue(['key' => 'critical-one', 'severity' => WorkbookSeverity::Critical]);
        WorkbookItem::factory()->create(['key' => 'not-ready', 'status' => WorkbookStatus::Planned]);

        Artisan::call('cfb:issues', ['--ready' => true, '--json' => true]);
        $issues = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_column($issues, 'reference'))->toBe([$critical->reference, $low->reference]);
    });

    it('answers what a routine is holding right now', function () {
        $mine = readyIssue(['key' => 'mine']);
        readyIssue(['key' => 'someone-elses']);

        Artisan::call('cfb:issue', ['action' => 'claim', 'issue' => $mine->reference, '--as' => 'cloud:nightly']);

        Artisan::call('cfb:issues', ['--mine' => true, '--as' => 'cloud:nightly', '--json' => true]);
        $issues = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_column($issues, 'reference'))->toBe([$mine->reference]);
    });

    it('drops a lapsed lease off "mine" rather than calling it work in hand', function () {
        $item = readyIssue();
        $item->forceFill([
            'claimed_at' => now()->subHours(4),
            'claimed_by' => 'cloud:nightly',
            'claim_expires_at' => now()->subHours(2),
        ])->save();

        Artisan::call('cfb:issues', ['--mine' => true, '--as' => 'cloud:nightly', '--json' => true]);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([]);
    });

    it('is valid JSON when there is nothing to say', function () {
        Artisan::call('cfb:issues', ['--json' => true]);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([]);
    });
});
