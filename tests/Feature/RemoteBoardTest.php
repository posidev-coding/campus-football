<?php

use App\Enums\WorkbookStatus;
use App\Http\Middleware\EnsureOpsToken;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use App\Support\IssueBoard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/*
 * `cfb:issue` and `cfb:issues` against a board that is not this database.
 *
 * The bug this file holds shut is a SILENT FALLBACK. A working checkout reads
 * its own workbook table; the cards a human reads live on a deployment. So a
 * remote verb that quietly writes locally when the board does not answer is the
 * original bug wearing a hat — the session is told it commented, the trail
 * never hears about it, and nothing anywhere reports a failure.
 *
 * Which is why nearly every test below asserts the same second thing: the LOCAL
 * row did not move. A row is planted here with the same reference the fake
 * board answers about, so a fallback would have something to write to and would
 * be caught doing it.
 */

/** A real-shaped secret. Anything shorter is treated as unset. */
const REMOTE_OPS_TOKEN = 'a-real-ops-token-of-a-believable-length-0123456789';

const REMOTE_BOARD = 'https://board.test';

beforeEach(function () {
    config(['cfb.board_url' => REMOTE_BOARD, 'cfb.ops_token' => REMOTE_OPS_TOKEN]);

    // Nothing here may reach the network or the repository by accident. A
    // stray request is the failure this file is about, so it is an error
    // rather than a surprise 200.
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    Process::fake(['git rev-parse *' => Process::result('main')]);
});

/**
 * A row on the LOCAL board, carrying the reference the fake board answers
 * about. It exists to be a target: if a remote verb ever fell back, this is
 * what it would write to, and every test asserts it did not.
 */
function localIssue(array $overrides = []): WorkbookItem
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
 * What the board answers with — a real `IssueBoard::one()` array, so the shape
 * this test asserts against is the shape the board actually serves rather than
 * a hand-written guess that can drift.
 *
 * @return array<string, mixed>
 */
function boardPayload(WorkbookItem $item, array $overrides = []): array
{
    return [...app(IssueBoard::class)->one($item), ...$overrides];
}

describe('which board, by configuration', function () {
    it('stays local when no board URL is set, which is the default', function () {
        // The behavior these commands have always had. Remote is opt-in, and
        // nothing infers it from a hostname or a branch name.
        config(['cfb.board_url' => null]);
        $item = localIssue();

        expect(Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference]))->toBe(0)
            ->and($item->fresh()->status)->toBe(WorkbookStatus::InProgress);

        Http::assertNothingSent();
    });

    it('sends every verb to the board and writes nothing locally', function () {
        /*
         * The assertion that matters is the SECOND one in each pair. A remote
         * verb hitting the endpoint proves the happy path; the local row not
         * moving is what catches a fallback creeping back in.
         */
        $item = localIssue();

        Http::fake([
            REMOTE_BOARD.'/ops/issues/*' => Http::response([
                'result' => 'started',
                'issue' => boardPayload($item, ['status' => 'in_progress', 'branch' => $item->branchName()]),
            ]),
        ]);

        expect(Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference]))->toBe(0);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === REMOTE_BOARD.'/ops/issues/'.$item->reference.'/start'
            && $request['as'] === 'agent:local');

        $fresh = $item->fresh();

        expect($fresh->status)->toBe(WorkbookStatus::Planned)
            ->and($fresh->claimed_by)->toBeNull()
            ->and($fresh->branch)->toBeNull()
            ->and($fresh->events()->where('kind', WorkbookEvent::STARTED)->count())->toBe(0);
    });

    it('reads a brief without writing anywhere', function () {
        $item = localIssue();

        Http::fake([REMOTE_BOARD.'/ops/issues/*' => Http::response(['result' => 'ok', 'issue' => boardPayload($item)])]);

        Artisan::call('cfb:issue', ['action' => 'show', 'issue' => $item->reference, '--json' => true]);
        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($answer['reference'])->toBe($item->reference)
            ->and($answer)->toHaveKeys(['body', 'prompt', 'trail']);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === REMOTE_BOARD.'/ops/issues/'.$item->reference.'/brief');
    });

    it('reads the ready queue off the board rather than the local table', function () {
        // The local table holds a DIFFERENT card. If the list ever came from
        // here, this reference is what would come back.
        $local = localIssue(['key' => 'only-on-this-checkout']);
        $remote = localIssue(['key' => 'only-on-the-board']);

        Http::fake([REMOTE_BOARD.'/ops/issues/ready*' => Http::response([
            'result' => 'ok',
            'issues' => [boardPayload($remote)],
        ])]);

        Artisan::call('cfb:issues', ['--ready' => true, '--json' => true]);
        $issues = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_column($issues, 'reference'))->toBe([$remote->reference])
            ->and(array_column($issues, 'reference'))->not->toContain($local->reference);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), REMOTE_BOARD.'/ops/issues/ready'));
    });
});

describe('the token is a header and only a header', function () {
    it('rides in the header, never in the URL or the body', function () {
        // A token in argv is a token in shell history and in `ps`. This
        // command never takes one as an argument, and never puts one in a URL.
        $item = localIssue();

        Http::fake([REMOTE_BOARD.'/ops/issues/*' => Http::response(['result' => 'ok', 'issue' => boardPayload($item)])]);

        Artisan::call('cfb:issue', ['action' => 'show', 'issue' => $item->reference, '--json' => true]);

        Http::assertSent(fn ($request): bool => $request->hasHeader(EnsureOpsToken::HEADER, REMOTE_OPS_TOKEN)
            && ! str_contains($request->url(), REMOTE_OPS_TOKEN)
            && ! str_contains($request->body(), REMOTE_OPS_TOKEN));
    });

    it('keeps the token out of what a transport failure says', function () {
        /*
         * A refusal is printed, and a printed line is a line in a scrollback
         * and a CI log. The message is built here rather than handed through
         * from a client exception, and whatever IS handed through is scrubbed.
         */
        $item = localIssue();

        Http::fake(fn () => throw new ConnectionException(
            'cURL error 7: Failed to connect, token was '.REMOTE_OPS_TOKEN,
        ));

        $exit = Artisan::call('cfb:issue', ['action' => 'show', 'issue' => $item->reference, '--json' => true]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->not->toContain(REMOTE_OPS_TOKEN)
            ->and($output)->toContain('[ops token]');
    });

    it('says so plainly when the board is set but the token is not', function () {
        // Without one, every /ops route answers 404 — which reads exactly like
        // a card that does not exist, and sends the reader after the wrong bug.
        config(['cfb.ops_token' => null]);
        $item = localIssue();

        $exit = Artisan::call('cfb:issue', ['action' => 'show', 'issue' => $item->reference, '--json' => true]);
        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($answer['error'])->toContain('OPS_TOKEN')
            ->and($answer['error'])->toContain(REMOTE_BOARD);

        Http::assertNothingSent();
    });
});

describe('no silent fallback', function () {
    it('exits non-zero and writes nothing when the board is unreachable', function () {
        $item = localIssue();

        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $exit = Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference, '--json' => true]);
        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($answer['error'])->toContain(REMOTE_BOARD)
            ->and($item->fresh()->status)->toBe(WorkbookStatus::Planned)
            ->and($item->fresh()->claimed_by)->toBeNull()
            ->and($item->fresh()->events()->count())->toBe(1);
    });

    it('exits non-zero on a server error rather than shrugging', function () {
        $item = localIssue();

        Http::fake([REMOTE_BOARD.'/ops/issues/*' => Http::response('', 500)]);

        expect(Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference]))->toBe(1)
            ->and($item->fresh()->status)->toBe(WorkbookStatus::Planned);
    });

    it('names the board when a card is not on it', function () {
        // The same false conclusion the local refusal already guards against:
        // a card on another board reads as a card that was withdrawn.
        localIssue();

        Http::fake([REMOTE_BOARD.'/ops/issues/*' => Http::response(['message' => 'Not Found'], 404)]);

        $exit = Artisan::call('cfb:issue', ['action' => 'show', 'issue' => 'CFB-999', '--json' => true]);
        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($answer['error'])->toContain('CFB-999')
            ->and($answer['error'])->toContain(REMOTE_BOARD)
            // The other half of a 404 from an ops surface, said out loud.
            ->and($answer['error'])->toContain('OPS_TOKEN');
    });

    it('surfaces a held claim as a non-zero exit naming the holder', function () {
        /*
         * The claim is atomic ON THE BOARD — one conditional update, and a 409
         * for the loser. Nothing here reads the claim and then writes it, which
         * over a network is where that race would actually be lost.
         */
        $item = localIssue();

        Http::fake([REMOTE_BOARD.'/ops/issues/*' => Http::response([
            'result' => 'held',
            'by' => 'cloud:nightly',
            'expires_at' => now()->addMinutes(42)->toIso8601String(),
        ], 409)]);

        $exit = Artisan::call('cfb:issue', ['action' => 'start', 'issue' => $item->reference, '--json' => true]);
        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($answer['error'])->toContain('cloud:nightly')
            ->and($answer['error'])->toContain('steals a claim')
            ->and($item->fresh()->claimed_by)->toBeNull();
    });
});

describe('the note is never swallowed', function () {
    it('writes the plan to the board and not to the local trail', function () {
        $item = localIssue();

        Http::fake([REMOTE_BOARD.'/ops/issues/*' => Http::response([
            'result' => 'commented',
            'issue' => boardPayload($item),
        ])]);

        $exit = Artisan::call('cfb:issue', [
            'action' => 'comment',
            'issue' => $item->reference,
            '--note' => 'Adding the eager load to pickem-home, then a query-count test.',
        ]);

        expect($exit)->toBe(0)
            ->and($item->fresh()->events()->where('kind', WorkbookEvent::COMMENTED)->count())->toBe(0);

        Http::assertSent(fn ($request): bool => $request->url() === REMOTE_BOARD.'/ops/issues/'.$item->reference.'/comment'
            && $request['note'] === 'Adding the eager load to pickem-home, then a query-count test.');
    });

    it('hands the note back when the board does not take it', function () {
        /*
         * A failed `start` can simply be run again. A failed comment has a
         * paragraph in it that exists nowhere else, so it comes BACK on stdout
         * for a human to paste. No spool file and no queue: a note replayed
         * later lands out of order on a trail whose whole value is sequence.
         */
        $item = localIssue();
        $note = 'Adding the eager load to pickem-home, then a query-count test.';

        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $exit = Artisan::call('cfb:issue', ['action' => 'comment', 'issue' => $item->reference, '--note' => $note]);

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain($note)
            ->and($item->fresh()->events()->where('kind', WorkbookEvent::COMMENTED)->count())->toBe(0);
    });

    it('keeps the note inside ONE json document', function () {
        // Two documents on stdout is a parse error at the other end, which
        // loses the note just as thoroughly as swallowing it would.
        $item = localIssue();
        $note = 'Bigger than the card — this needs the CfbCalendar refactor first.';

        Http::fake([REMOTE_BOARD.'/ops/issues/*' => Http::response('', 500)]);

        $exit = Artisan::call('cfb:issue', [
            'action' => 'comment', 'issue' => $item->reference, '--note' => $note, '--json' => true,
        ]);

        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($answer['note'])->toBe($note)
            ->and($answer['error'])->toContain(REMOTE_BOARD);
    });
});

describe('what has no remote route is refused, never dropped', function () {
    it('will not pretend it sized or labeled a card', function () {
        // Dropping them silently is the failure this whole card is about: a
        // session that believes it sized something it did not.
        $item = localIssue();

        $exit = Artisan::call('cfb:issue', [
            'action' => 'comment', 'issue' => $item->reference, '--note' => 'hi', '--effort' => 'm',
        ]);

        expect($exit)->toBe(1)
            ->and($item->fresh()->effort)->toBeNull();

        Http::assertNothingSent();
    });

    it('refuses done, ready and link by name', function () {
        $item = localIssue();

        foreach (['done', 'ready', 'link'] as $action) {
            expect(Artisan::call('cfb:issue', ['action' => $action, 'issue' => $item->reference, '--as' => 'human']))->toBe(1);
        }

        expect($item->fresh()->status)->toBe(WorkbookStatus::Planned);

        Http::assertNothingSent();
    });

    it('refuses a list the board does not serve rather than answering a different one', function () {
        // `--mine` answered with the whole ready queue is a list a session
        // would trust and a filter that never ran.
        localIssue();

        expect(Artisan::call('cfb:issues', ['--mine' => true, '--ready' => true]))->toBe(1)
            ->and(Artisan::call('cfb:issues', []))->toBe(1);

        Http::assertNothingSent();
    });
});

describe('finding the issue without a database', function () {
    it('reads the reference off the front of the branch', function () {
        // The stored `branch` column is on the OTHER board, so there is nothing
        // here to look a branch up in. The reference `start` minted into the
        // name is what survives.
        $item = localIssue();
        Process::fake(['git rev-parse *' => Process::result($item->reference.'-picks-n-plus-one')]);

        Http::fake([REMOTE_BOARD.'/ops/issues/*' => Http::response(['result' => 'ok', 'issue' => boardPayload($item)])]);

        expect(Artisan::call('cfb:issue', ['action' => 'show', '--json' => true]))->toBe(0);

        Http::assertSent(fn ($request): bool => $request->url() === REMOTE_BOARD.'/ops/issues/'.$item->reference.'/brief');
    });

    it('refuses a branch carrying no reference rather than guessing', function () {
        localIssue();
        Process::fake(['git rev-parse *' => Process::result('some-other-work')]);

        $exit = Artisan::call('cfb:issue', ['action' => 'show', '--json' => true]);
        $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($answer['error'])->toContain('some-other-work')
            ->and($answer['error'])->toContain(REMOTE_BOARD);

        Http::assertNothingSent();
    });

    it('will not compose a path out of something that is not a handle', function () {
        Artisan::call('cfb:issue', ['action' => 'show', 'issue' => '../../etc/passwd', '--json' => true]);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toContain('not a handle');

        Http::assertNothingSent();
    });
});
