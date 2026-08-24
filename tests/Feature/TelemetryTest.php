<?php

use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Jobs\FetchGameSummary;
use App\Models\ClientError;
use App\Models\FeedRun;
use App\Models\User;
use App\Models\UxEvent;
use App\Support\OpsReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
 * The snapshot the maintenance advisor reads.
 *
 * The advisor is a Claude Code routine with NO database access: it reads the
 * repository and this payload, and the quality of what it proposes is bounded
 * by what is in here. Two rules, and they are the whole design — aggregate
 * only, and it reports rather than fixes.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();
    $this->travelTo('2026-09-05 18:00:00');
});

/** The snapshot, decoded. */
function telemetry(): array
{
    Artisan::call('cfb:telemetry', ['--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

describe('the payload', function () {
    it('carries every section the advisor needs', function () {
        expect(array_keys(telemetry()))->toBe([
            'generated_at', 'window_hours', 'season', 'ops', 'coverage',
            'pickem', 'schedule', 'errors', 'performance', 'funnel',
        ]);
    });

    it('is valid JSON and nothing but JSON', function () {
        // The `/ops/telemetry` route will serve this verbatim; a stray line of
        // console output would make it unparseable at the other end.
        Artisan::call('cfb:telemetry', ['--json' => true]);

        expect(json_decode(Artisan::output(), true))->toBeArray();
    });

    it('reads the season from the calendar and never from config', function () {
        // A season exists in the database months before it is played, and
        // currentYear/resultsYear answer different questions.
        expect(telemetry()['season'])
            ->toHaveKeys(['current_year', 'results_year', 'phase']);
    });
});

describe('no user ever reaches the payload', function () {
    it('carries counts and not people, even when every sensor has fired', function () {
        // This leaves the machine. A snapshot that carries identity is a
        // snapshot that cannot be handed to anything.
        $user = User::factory()->create([
            'id' => 987654,
            'email' => 'dolly@example.test',
            'handle' => 'jolene',
            'first_name' => 'Dolly',
        ]);

        ClientError::create([
            'fingerprint' => str_repeat('a', 40), 'kind' => 'error',
            'message' => 'Cannot read properties of undefined', 'reports' => 12,
            'path' => '/picks', 'user_id' => $user->id,
        ]);

        FeedRun::jobFailed(FetchGameSummary::class, 'ESPN returned 403');
        app(RecordUxEvent::class)->handle(UxSignal::FirstPickMade);

        $payload = json_encode(telemetry());

        expect($payload)
            ->not->toContain('dolly@example.test')
            ->not->toContain('jolene')
            ->not->toContain('987654')
            ->not->toContain('user_id')
            ->not->toContain('"email"');
    });

    it('drops the Eloquent models the schedule report hands back', function () {
        // SyncSchedule::tasks() returns a FeedRun instance for the admin
        // table; serializing one would drag every column into the payload.
        $task = telemetry()['schedule'][0];

        expect(array_keys($task))->toBe([
            'name', 'cadence', 'gated', 'overdue', 'last_status', 'last_run_at',
        ]);
    });
});

describe('what it reports', function () {
    it('separates a failed command from a failed job', function () {
        // The `job:` rows exist because Cloud's managed queues hide
        // failed_jobs; the split is what makes them readable as two problems.
        FeedRun::jobFailed(FetchGameSummary::class, 'ESPN returned 403');

        $run = FeedRun::begin('sync:news', 2026);
        $run->fail('the feed timed out', 1, 900);

        $errors = telemetry()['errors'];

        expect($errors['jobs'])->toHaveCount(1)
            ->and($errors['jobs'][0]['command'])->toBe('job:FetchGameSummary')
            ->and($errors['commands'])->toHaveCount(1)
            ->and($errors['commands'][0]['command'])->toBe('sync:news');
    });

    it('groups Pulse entries so one slow route is one line', function () {
        // A route that is slow two hundred times is one finding, not two
        // hundred — and the payload has to fit in a prompt.
        foreach (range(1, 5) as $i) {
            DB::table('pulse_entries')->insert([
                'timestamp' => now()->subMinutes(10)->getTimestamp(),
                'type' => 'slow_request',
                'key' => '["GET","/picks"]',
                'value' => 1_000 + $i,
            ]);
        }

        $slow = telemetry()['performance']['slow_request'];

        expect($slow)->toHaveCount(1)
            ->and($slow[0])->toBe(['what' => 'GET /picks', 'hits' => 5, 'worst' => 1_005]);
    });

    it('carries the browser errors no server-side monitor can see', function () {
        ClientError::create([
            'fingerprint' => str_repeat('b', 40), 'kind' => 'unhandledrejection',
            'message' => 'Failed to fetch', 'reports' => 4_000,
            'path' => '/scoreboard', 'viewport' => 390, 'standalone' => true,
        ]);

        $client = telemetry()['errors']['client'];

        expect($client[0]['message'])->toBe('Failed to fetch')
            ->and($client[0]['reports'])->toBe(4_000)
            // Width and standalone are the first two triage questions on a
            // product designed at 390px and installed as a PWA.
            ->and($client[0]['viewport'])->toBe(390)
            ->and($client[0]['standalone'])->toBeTrue();
    });

    it('adds today to the persisted funnel, so the two halves agree', function () {
        UxEvent::create(['day' => '2026-09-04', 'signal' => 'invite_opened', 'count' => 9]);
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened);

        expect(telemetry()['funnel']['invite_opened'])->toBe(10);
    });

    it('names every signal, including the ones at zero', function () {
        // A missing key and a zero read the same to a human and differently
        // to a model. Every signal is always present.
        expect(array_keys(telemetry()['funnel']))
            ->toBe(array_map(fn (UxSignal $s) => $s->value, UxSignal::cases()));
    });
});

describe('it reports and never gates', function () {
    it('exits zero even with something failing', function () {
        // cfb:doctor is the deploy gate. A snapshot command that fails a
        // pipeline because a request was slow is one somebody turns off.
        foreach (range(1, 12) as $i) {
            FeedRun::jobFailed(FetchGameSummary::class, 'boom');
        }

        expect(collect((new OpsReport)->checks())->firstWhere('key', 'failed_jobs')['status'])
            ->toBe(OpsReport::FAIL);

        $this->artisan('cfb:telemetry')->assertSuccessful();
    });

    it('reads at a terminal without --json', function () {
        $this->artisan('cfb:telemetry')
            ->expectsOutputToContain('Application')
            ->expectsOutputToContain('Data coverage')
            ->expectsOutputToContain('Funnel')
            ->assertSuccessful();
    });
});
