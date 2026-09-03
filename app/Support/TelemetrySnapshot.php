<?php

namespace App\Support;

use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Enums\WorkbookStatus;
use App\Models\ClientError;
use App\Models\FeedRun;
use App\Models\UxEvent;
use App\Models\WorkbookItem;
use App\Services\CfbCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the app knows about how it is doing, as one array.
 *
 * ONE OBJECT, TWO SURFACES — the shape CoverageReport already set with
 * `cfb:doctor` and the DataCoverage widget. Here the surfaces are
 * `cfb:telemetry`, which renders it at a terminal, and `GET /ops/telemetry`,
 * which serves it to the maintenance advisor. They cannot disagree about what
 * the app's health is, because there is only one answer.
 *
 * The advisor is a Claude Code routine with NO DATABASE ACCESS: it reads the
 * repository and this payload, and the quality of what it proposes is bounded
 * by what is in here.
 *
 * Two rules, and they are the whole design:
 *
 *   1. **Aggregate only. No user identifiers, ever.** Not an id, not an email,
 *      not a handle. This leaves the machine, and a snapshot that carries
 *      identity is a snapshot that cannot be handed to anything.
 *      `TelemetryTest` asserts it rather than trusting it.
 *   2. **It reports, it never fixes.** Every remedy is a string for a human or
 *      an agent to run.
 */
class TelemetrySnapshot
{
    /**
     * The one Pulse type whose `value` is not a duration. Named, because the
     * whole of `pulseTop()`'s split personality hangs off this comparison.
     */
    private const EXCEPTION = 'exception';

    public function __construct(
        private OpsReport $ops,
        private CoverageReport $coverage,
        private PickemPreflight $preflight,
        private SyncSchedule $schedule,
        private CfbCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'window_hours' => OpsReport::HOURS,
            'season' => [
                'current_year' => $this->calendar->currentYear(),
                'results_year' => $this->calendar->resultsYear(),
                'phase' => $this->calendar->phase()->value,
            ],
            'ops' => $this->ops->checks(),
            'coverage' => $this->coverage->checks(),
            'pickem' => $this->preflight->checks(),
            'schedule' => $this->schedule(),
            'errors' => [
                'commands' => $this->recentFailures('not like'),
                'jobs' => $this->recentFailures('like'),
                'client' => $this->clientErrors(),
            ],
            'performance' => $this->performance(),
            'funnel' => $this->funnel(),
            'funnel_since' => $this->funnelSince(),
            'workbook' => $this->workbook(),
        ];
    }

    /**
     * The board as it stands — and this is what closes the advisor's loop.
     *
     * Without it the routine reads telemetry, finds the same slow query it
     * found last week, and files it again under a new key or re-argues one a
     * human already answered. With it, `open` says what to UPDATE rather than
     * duplicate and `answered` says what to leave alone.
     *
     * `dismissed` is the important half. `WorkbookItem::propose()` refuses to
     * reopen those whatever the routine sends, but a guard that silently
     * discards work is a routine that wastes its whole run rediscovering it.
     * Telling it up front is cheaper than refusing it afterwards.
     *
     * No identity here either: a workbook item has no user on it by design.
     *
     * @return array{open: list<array<string, mixed>>, answered: array<string, string>}
     */
    private function workbook(): array
    {
        $open = WorkbookItem::query()
            ->open()
            ->orderByRaw("field(severity, 'critical', 'high', 'medium', 'low')")
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get()
            ->map(fn (WorkbookItem $item): array => [
                'key' => $item->key,
                // The handle a human says out loud, so the advisor can quote a
                // card in the language the board uses rather than in a slug.
                'reference' => $item->reference,
                'title' => $item->title,
                'category' => $item->category->value,
                'severity' => $item->severity->value,
                // Human-owned, and the advisor is told them so it stops
                // proposing work a human has already sized and filed.
                'effort' => $item->effort?->value,
                'labels' => $item->labels,
                'status' => $item->status->value,
                'first_seen_at' => $item->first_seen_at?->toIso8601String(),
                'last_seen_at' => $item->last_seen_at?->toIso8601String(),
            ])
            ->all();

        $answered = WorkbookItem::query()
            ->whereIn('status', [WorkbookStatus::Done->value, WorkbookStatus::Dismissed->value])
            ->orderByDesc('last_seen_at')
            ->limit(200)
            ->pluck('status', 'key')
            ->map(fn (WorkbookStatus $status): string => $status->value)
            ->all();

        return ['open' => $open, 'answered' => $answered];
    }

    /**
     * The schedule WITHOUT its FeedRun models — `SyncSchedule::tasks()` hands
     * back Eloquent instances for the admin table, and serializing one drags
     * every column into the payload.
     *
     * @return list<array<string, mixed>>
     */
    private function schedule(): array
    {
        return collect($this->schedule->tasks())
            ->map(fn (array $task): array => [
                'name' => $task['name'],
                'cadence' => $task['cadence'],
                'gated' => $task['gated'],
                'overdue' => $task['overdue'],
                'last_status' => $task['run']?->status,
                'last_run_at' => $task['run']?->started_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Failed runs inside the window, split by what failed.
     *
     * The `job:` prefix is the split: `Queue::failing` writes those rows
     * because Laravel Cloud's managed queues keep `failed_jobs` to themselves,
     * so they are the only record of a dead job the app can read at all.
     *
     * @return list<array<string, mixed>>
     */
    private function recentFailures(string $operator): array
    {
        return FeedRun::query()
            ->where('status', FeedRun::FAILED)
            ->where('command', $operator, 'job:%')
            ->where('started_at', '>=', now()->subHours(OpsReport::HOURS))
            ->orderByDesc('started_at')
            ->limit(20)
            ->get(['command', 'season_year', 'error', 'started_at'])
            ->map(fn (FeedRun $run): array => [
                'command' => $run->command,
                'season_year' => $run->season_year,
                'error' => $run->error,
                'at' => $run->started_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Browser errors, grouped by fingerprint. The one signal no server-side
     * monitor produces — and the class of bug a 390px PWA ships silently.
     *
     * @return list<array<string, mixed>>
     */
    private function clientErrors(): array
    {
        return ClientError::query()
            ->where('created_at', '>=', now()->subHours(OpsReport::HOURS))
            ->orderByDesc('reports')
            ->limit(20)
            ->get()
            ->map(fn (ClientError $error): array => [
                'kind' => $error->kind,
                'message' => $error->message,
                'source' => $error->source,
                'line' => $error->line,
                'path' => $error->path,
                'viewport' => $error->viewport,
                'standalone' => $error->standalone,
                'reports' => $error->reports,
            ])
            ->all();
    }

    /**
     * Pulse's own tables, read straight — the decisive advantage of Pulse over
     * a hosted APM for this app: the data is in our MySQL, so a snapshot is a
     * query rather than an API call, a rate limit and a bill.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function performance(): array
    {
        if (! Schema::hasTable('pulse_entries')) {
            return [];
        }

        return collect(['slow_request', 'slow_query', 'slow_job', 'slow_outgoing_request', self::EXCEPTION])
            ->mapWithKeys(fn (string $type): array => [$type => $this->pulseTop($type)])
            ->all();
    }

    /**
     * The heaviest entries of one type. Grouped by key, so a route that is
     * slow two hundred times is one line with a count rather than two hundred.
     *
     * ONE QUERY, TWO MEANINGS — and it has to be said out loud, because the
     * column does not say it. Pulse writes a DURATION IN MILLISECONDS into
     * `value` for slow_request, slow_query, slow_job and slow_outgoing_request.
     * For `exception` it writes the OCCURRENCE TIMESTAMP there instead
     * (`Recorders/Exceptions.php`, `value: $timestamp`).
     *
     * So `max(value) desc` is "slowest first" for four types and "most recent
     * first" for the fifth. Both orderings are right for their type; only the
     * NAME was wrong. This used to emit `"worst": 1787646322` on an exception
     * row — a unix timestamp sitting in a field every sibling row measures in
     * milliseconds — to a consumer with no database access that is explicitly
     * told never to invent a number.
     *
     * Exceptions therefore carry `last_seen_at` and NO `worst`. OMITTED rather
     * than zeroed: a missing measurement is skipped, never substituted, and a
     * `worst` of 0 on an exception row is precisely the invented value that
     * rule exists to stop.
     *
     * @return list<array<string, mixed>>
     */
    private function pulseTop(string $type): array
    {
        return DB::table('pulse_entries')
            ->where('type', $type)
            ->where('timestamp', '>=', now()->subHours(OpsReport::HOURS)->getTimestamp())
            ->groupBy('key')
            ->orderByRaw('max(value) desc')
            ->limit(10)
            // `max_value`, not `worst`: the alias says what the column HOLDS,
            // and what it MEANS is decided below, per type.
            ->selectRaw('`key`, count(*) as hits, max(value) as max_value')
            ->get()
            ->map(fn ($row): array => [
                'what' => OpsReport::readableKey((string) $row->key),
                'hits' => (int) $row->hits,
                ...$type === self::EXCEPTION
                    // UTC spelled out rather than left to Carbon's default,
                    // which changed between major versions.
                    ? ['last_seen_at' => CarbonImmutable::createFromTimestamp((int) $row->max_value, 'UTC')->toIso8601String()]
                    : ['worst' => (int) $row->max_value],
            ])
            ->all();
    }

    /**
     * The funnel: seven persisted days plus whatever today has counted so far.
     *
     * "Abandoned with zero picks" is deliberately absent as a number — it is
     * `slate_entered` minus `first_pick_made`, and the advisor can subtract.
     *
     * @return array<string, int>
     */
    private function funnel(): array
    {
        $persisted = UxEvent::query()
            ->where('day', '>=', now()->timezone(config('cfb.timezone'))->subDays(7)->toDateString())
            ->groupBy('signal')
            // BOTH backticked: `signal` and `count` are reserved words in
            // MySQL 8, and an unquoted one is a 1064 rather than a wrong
            // answer — the same family as the STORED trap in data-model.md.
            ->selectRaw('`signal`, sum(`count`) as total')
            ->pluck('total', 'signal');

        $today = app(RecordUxEvent::class);

        // Today's counters are still in Redis. Including them keeps this
        // section and OpsReport's pick-through row telling the same story —
        // two numbers for one fact is how an agent's afternoon goes missing.
        return collect(UxSignal::cases())
            ->mapWithKeys(fn (UxSignal $signal): array => [
                $signal->value => (int) ($persisted[$signal->value] ?? 0) + $today->todayCount($signal),
            ])
            ->all();
    }

    /**
     * The first day each signal's `funnel` total covers.
     *
     * The total above is a seven-day number only for a signal that has been
     * counting for seven days. A signal added this week reads zero for every
     * day before it shipped, and a table that held only its non-zero days
     * could not say so: `onboarding_credentials_reached` read 0 beside a
     * seven-day 163 opened, and was filed as the wizard losing everybody
     * when it had been live for two of the seven. The rollup now writes a
     * row for every signal it knows, zero included, so the earliest row in
     * the window IS the day the deployed code started counting. With no row
     * at all the total is today's Redis count alone, and the date says so.
     *
     * @return array<string, string> signal => Y-m-d
     */
    private function funnelSince(): array
    {
        $today = now()->timezone(config('cfb.timezone'));

        $first = UxEvent::query()
            ->where('day', '>=', $today->copy()->subDays(7)->toDateString())
            ->groupBy('signal')
            ->selectRaw('`signal`, min(`day`) as first_day')
            ->pluck('first_day', 'signal');

        return collect(UxSignal::cases())
            ->mapWithKeys(fn (UxSignal $signal): array => [
                $signal->value => isset($first[$signal->value])
                    ? CarbonImmutable::parse($first[$signal->value])->toDateString()
                    : $today->toDateString(),
            ])
            ->all();
    }
}
