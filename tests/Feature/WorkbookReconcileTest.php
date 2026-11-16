<?php

use App\Console\Commands\WorkbookReconcileCommand;
use App\Enums\WorkbookStatus;
use App\Models\FeedRun;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use App\Support\SyncSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The net under the merge webhook. GitHub never retries a failed delivery and
 * every merge deploys, so a `closed` delivery that lands mid-swap gets a 503
 * and its card would sit In review for good — CFB-121, 2026-11-16. The hourly
 * reconcile asks GitHub instead of waiting to be told, and closes through the
 * same Action the webhook does.
 */

const RECONCILE_TOKEN = 'github_pat_a-fine-grained-read-token-for-tests';

const RECONCILE_REPO = 'github.com/posidev-coding/campus-football';

beforeEach(function () {
    config(['cfb.github_token' => RECONCILE_TOKEN, 'cfb.repo_host' => RECONCILE_REPO]);
    Http::preventStrayRequests();
});

/** A card a session has taken to review, with its branch and pull request stored. */
function cardInReview(int $pull): WorkbookItem
{
    $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::InReview]);

    $item->forceFill([
        'branch' => $item->branchName(),
        'pr_url' => 'https://'.RECONCILE_REPO."/pull/{$pull}",
    ])->save();

    return $item->refresh();
}

/** The REST API's own URL for one pull request. */
function pullApiUrl(int $pull): string
{
    return "https://api.github.com/repos/posidev-coding/campus-football/pulls/{$pull}";
}

/**
 * GitHub's pull request shape, trimmed to what matters — plus the login every
 * real response carries, which nothing may store.
 *
 * @return array<string, mixed>
 */
function pullBody(string $branch, bool $merged): array
{
    return [
        'number' => 1,
        'state' => $merged ? 'closed' : 'open',
        'merged' => $merged,
        'head' => ['ref' => $branch],
        'merged_by' => $merged ? ['login' => 'dolly-parton'] : null,
    ];
}

describe('a merged pull request', function () {
    it('moves its In review card to Done, as github', function () {
        $item = cardInReview(221);

        Http::fake([pullApiUrl(221) => Http::response(pullBody($item->branch, merged: true))]);

        $this->artisan('cfb:workbook:reconcile')
            ->expectsOutputToContain("{$item->reference}: #221 merged; moved to Done.")
            ->assertSuccessful();

        $event = $item->fresh()->events()->where('kind', WorkbookEvent::MOVED)->sole();

        expect($item->fresh()->status)->toBe(WorkbookStatus::Done)
            ->and($item->fresh()->completed_at)->not->toBeNull()
            ->and($event->actor)->toBe(WorkbookItem::ACTOR_GITHUB)
            ->and($event->note)->toBe('Merged.')
            ->and(json_encode($event->toArray()))->not->toContain('dolly');
    });

    it('asks with the token, as a header and only a header', function () {
        $item = cardInReview(221);

        Http::fake([pullApiUrl(221) => Http::response(pullBody($item->branch, merged: true))]);

        $this->artisan('cfb:workbook:reconcile')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === pullApiUrl(221)
            && $request->hasHeader('Authorization', 'Bearer '.RECONCILE_TOKEN)
            && ! str_contains($request->url(), RECONCILE_TOKEN));
        Http::assertSentCount(1);
    });

    it('records a completed run under its ledger key, with no ESPN spend', function () {
        $item = cardInReview(221);

        Http::fake([pullApiUrl(221) => Http::response(pullBody($item->branch, merged: true))]);

        $this->artisan('cfb:workbook:reconcile')->assertSuccessful();

        $run = FeedRun::latestFor(WorkbookReconcileCommand::LEDGER);

        expect($run->status)->toBe(FeedRun::COMPLETE)
            ->and($run->records)->toBe(1)
            ->and($run->requests)->toBe(0);
    });

    it('leaves a card alone when GitHub says it merged some other branch', function () {
        // The webhook's rule: match the STORED branch, never anything looser.
        $item = cardInReview(221);

        Http::fake([pullApiUrl(221) => Http::response(pullBody('somebody-elses-branch', merged: true))]);

        $this->artisan('cfb:workbook:reconcile')->assertSuccessful();

        expect($item->fresh()->status)->toBe(WorkbookStatus::InReview);
    });
});

describe('anything short of merged', function () {
    it('leaves an open pull request\'s card In review', function () {
        $item = cardInReview(230);

        Http::fake([pullApiUrl(230) => Http::response(pullBody($item->branch, merged: false))]);

        $this->artisan('cfb:workbook:reconcile')
            ->expectsOutputToContain('Closed 0 merged cards.')
            ->assertSuccessful();

        expect($item->fresh()->status)->toBe(WorkbookStatus::InReview)
            ->and($item->fresh()->events()->where('kind', WorkbookEvent::MOVED)->count())->toBe(0);
    });

    it('leaves a card whose response carries no merged field at all', function () {
        $item = cardInReview(230);

        Http::fake([pullApiUrl(230) => Http::response(['head' => ['ref' => $item->branch]])]);

        $this->artisan('cfb:workbook:reconcile')->assertSuccessful();

        expect($item->fresh()->status)->toBe(WorkbookStatus::InReview);
    });

    it('leaves the card and reports the failure when GitHub answers an error', function (int $status) {
        $item = cardInReview(230);

        Http::fake([pullApiUrl(230) => Http::response(['message' => 'nope'], $status)]);

        $this->artisan('cfb:workbook:reconcile')
            ->expectsOutputToContain("{$item->reference}: GitHub answered {$status} for #230; left In review.")
            ->assertFailed();

        expect($item->fresh()->status)->toBe(WorkbookStatus::InReview)
            ->and(FeedRun::latestFor(WorkbookReconcileCommand::LEDGER)->status)->toBe(FeedRun::FAILED);
    })->with(['not found' => 404, 'server error' => 502]);

    it('leaves the card and reports the failure when GitHub cannot be reached', function () {
        $item = cardInReview(230);

        Http::fake([pullApiUrl(230) => fn () => throw new ConnectionException('timed out')]);

        $this->artisan('cfb:workbook:reconcile')
            ->expectsOutputToContain("{$item->reference}: GitHub could not be reached; left In review.")
            ->assertFailed();

        expect($item->fresh()->status)->toBe(WorkbookStatus::InReview);
    });

    it('still closes the merged cards when another card\'s request fails', function () {
        $merged = cardInReview(221);
        $broken = cardInReview(222);

        Http::fake([
            pullApiUrl(221) => Http::response(pullBody($merged->branch, merged: true)),
            pullApiUrl(222) => Http::response([], 503),
        ]);

        $this->artisan('cfb:workbook:reconcile')->assertFailed();

        expect($merged->fresh()->status)->toBe(WorkbookStatus::Done)
            ->and($broken->fresh()->status)->toBe(WorkbookStatus::InReview);
    });
});

describe('what it never asks about', function () {
    it('asks only about In review cards that carry a pull request', function () {
        $inProgress = WorkbookItem::factory()->create(['status' => WorkbookStatus::InProgress]);
        $inProgress->forceFill(['branch' => $inProgress->branchName(), 'pr_url' => 'https://'.RECONCILE_REPO.'/pull/1'])->save();

        $noPull = WorkbookItem::factory()->create(['status' => WorkbookStatus::InReview]);
        $noPull->forceFill(['branch' => $noPull->branchName()])->save();

        Http::fake();

        $this->artisan('cfb:workbook:reconcile')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('skips a pr_url that is not a pull request on this repository', function () {
        $item = cardInReview(221);
        $item->forceFill(['pr_url' => 'https://github.com/someone-else/elsewhere/pull/221'])->save();

        Http::fake();

        $this->artisan('cfb:workbook:reconcile')
            ->expectsOutputToContain('is not a pull request on this repository')
            ->assertSuccessful();

        Http::assertNothingSent();
        expect($item->fresh()->status)->toBe(WorkbookStatus::InReview);
    });
});

describe('without a token', function () {
    it('says it is not configured, moves nothing and exits 0', function (?string $token) {
        config(['cfb.github_token' => $token]);

        $item = cardInReview(221);

        Http::fake();

        $this->artisan('cfb:workbook:reconcile')
            ->expectsOutputToContain('not configured')
            ->assertSuccessful();

        Http::assertNothingSent();
        expect($item->fresh()->status)->toBe(WorkbookStatus::InReview)
            ->and(FeedRun::latestFor(WorkbookReconcileCommand::LEDGER))->toBeNull();
    })->with(['unset' => null, 'blank' => '   ']);
});

describe('the schedule', function () {
    it('resolves its ledger key on the schedule panel', function () {
        $tracked = collect(app(SyncSchedule::class)->tasks())
            ->filter(fn (array $task) => $task['name'] === 'cfb:workbook:reconcile')
            ->pluck('tracked')
            ->unique()
            ->all();

        expect($tracked)->toBe([WorkbookReconcileCommand::LEDGER]);
    });

    it('runs hourly in season and six-hourly off it, so June still sleeps', function (string $at, string $expression) {
        $this->travelTo(CarbonImmutable::parse($at, config('cfb.timezone')));

        $due = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'cfb:workbook:reconcile'))
            ->filter(fn (Event $event) => $event->filtersPass(app()))
            ->map(fn (Event $event) => $event->expression)
            ->values()
            ->all();

        expect($due)->toBe([$expression]);
    })->with([
        'a Saturday in October' => ['2026-10-17 14:00', '0 * * * *'],
        'mid-June' => ['2026-06-15 14:00', '0 */6 * * *'],
    ]);
});
