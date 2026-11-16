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
    /** The funnel's width in league days, today included. */
    public const FUNNEL_DAYS = 7;

    private ?AnalyticsWindow $funnelWindow = null;

    public function __construct(
        private OpsReport $ops,
        private CoverageReport $coverage,
        private PickemPreflight $preflight,
        private SyncSchedule $schedule,
        private CfbCalendar $calendar,
        private PerformanceReport $performance,
        private AnalyticsCatalog $analytics,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        // Once per build: five readers share it, and a snapshot taken across
        // midnight must not hand two of them different days.
        $this->funnelWindow = null;

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
            'performance' => $this->performance->checks(),
            'funnel' => $this->funnel(),
            /*
             * The window, stated, so nobody derives it from funnel_since —
             * which answers a different question: the first day each SIGNAL
             * counted, where this is the first day the window reads.
             */
            'funnel_window_days' => $this->funnelWindow()->days,
            'funnel_window_since' => $this->funnelWindow()->fromDate(),
            'funnel_since' => $this->funnelSince(),
            'funnel_missing_days' => $this->funnelMissingDays(),
            /*
             * The attention sections, appended AFTER the funnel and before the
             * board on purpose: the advisor reads this payload top to bottom,
             * and everything above is "is the machine alright", which is the
             * question that has to be answered first. A traffic dip is not
             * readable until the drain is known to be running.
             *
             * Every one of them is aggregate — the same rule as the rest of
             * this file, applied to the one pipeline that DOES hold identity
             * upstream. `activity_events` knows who; nothing below says so.
             */
            'traffic' => $this->analytics->traffic(AnalyticsWindow::of(7)),
            'audience' => [
                'actives' => $this->analytics->actives(),
                'adoption' => $this->analytics->adoption(AnalyticsWindow::of(7)),
                'cohorts' => $this->analytics->cohorts(),
                'retention' => $this->analytics->retention(),
                'saturday_retention' => $this->analytics->saturdayRetention(),
            ],
            'routes' => $this->analytics->routes(AnalyticsWindow::of(28)),
            'devices' => $this->analytics->devices(AnalyticsWindow::of(28)),
            'pickem_health' => $this->analytics->pickemHealth(),
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
            // `key` is unique, so it settles every tie — a frozen clock or a
            // batch filed in one second otherwise hands the advisor a
            // different order on every read.
            ->orderBy('key')
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
            ->orderBy('key')
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
                // The ledger key is the command's, and several entries run
                // some commands; their stamps are each entry's own pass.
                'shared' => $task['shared'],
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
                /*
                 * The path resolved to a route NAME, and the same route's
                 * traffic in the same window — which is what turns eleven
                 * errors into a rate. Null when the router matches nothing
                 * (a path from an old deploy, or one that never existed) and
                 * null when the raw table holds no views: "zero views, eleven
                 * errors" is an impossible pair, and the honest report of a
                 * denominator we do not have is no denominator.
                 */
                'route' => $route = $this->analytics->routeFor($error->path),
                'views_24h' => $this->analytics->routeViews($route, OpsReport::HOURS),
                'viewport' => $error->viewport,
                'standalone' => $error->standalone,
                'reports' => $error->reports,
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
            ->where('day', '>=', $this->funnelFloor()->toDateString())
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
     * That reading only holds while every finished day HAS its rows. A day
     * the rollup never stated moves this date forward exactly as a ship date
     * would, so it is never read alone: `funnel_missing_days` says which days
     * of the window are absent.
     *
     * @return array<string, string> signal => Y-m-d
     */
    private function funnelSince(): array
    {
        $today = now()->timezone(config('cfb.timezone'));

        $first = UxEvent::query()
            ->where('day', '>=', $this->funnelFloor()->toDateString())
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

    /**
     * Every finished day of the funnel window with no `ux_events` row at all.
     *
     * The rollup states every signal for every day it reads, zero included,
     * so a day with no row for ANY signal is a day nothing measured — not a
     * quiet day, and not a signal that had yet to ship (that one is absent
     * for its own signal while the others carry the day). Without this, two
     * unstated days at the start of the window slid `funnel_since` forward to
     * 2026-11-10 and read exactly like ten signals that shipped that morning,
     * which is the confusion `funnel_since` exists to prevent.
     *
     * Reported, never filled: a zero written now for a day nobody counted is
     * an invented measurement. Today is left out, because it is still in
     * Redis and is never persisted until tomorrow.
     *
     * Yesterday is left out too until `cfb:ux-rollup` has STARTED today:
     * between midnight and the 04:55 run it is not missing, merely not due,
     * and listing it would have every snapshot taken overnight file a false
     * ops finding. Any run started today counts, failed included — a run
     * that failed has had its chance, and its day is a real gap.
     *
     * @return list<string> Y-m-d, oldest first
     */
    private function funnelMissingDays(): array
    {
        $floor = $this->funnelFloor();
        $today = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();
        $lastRun = FeedRun::latestFor('ux:rollup')?->started_at;
        $lastDue = $lastRun !== null && $lastRun->gte($today)
            ? $today->subDay()
            : $today->subDays(2);

        $stated = UxEvent::query()
            ->toBase()
            ->whereBetween('day', [$floor->toDateString(), $lastDue->toDateString()])
            ->distinct()
            ->pluck('day')
            ->map(fn (mixed $day): string => substr((string) $day, 0, 10))
            ->flip();

        $missing = [];

        for ($day = $floor; $day->lte($lastDue); $day = $day->addDay()) {
            if (! $stated->has($day->toDateString())) {
                $missing[] = $day->toDateString();
            }
        }

        return $missing;
    }

    /**
     * The funnel's window: seven league days, today included, through the
     * same {@see AnalyticsWindow} traffic and adoption are cut by.
     *
     * It was `today - 7` open-coded, and with today inclusive that is EIGHT
     * calendar days under a seven-day label — a day more than the sections
     * beside it, so every cross-section read (invite_opened against
     * registrations) divided an eight-day numerator by a seven-day
     * denominator (CFB-126). One window object is what stops the two
     * drifting again.
     */
    private function funnelWindow(): AnalyticsWindow
    {
        return $this->funnelWindow ??= AnalyticsWindow::of(self::FUNNEL_DAYS);
    }

    /**
     * The first league day the funnel window reads — one floor for the total,
     * its dates and its gaps, so the three can never describe different days.
     */
    private function funnelFloor(): CarbonImmutable
    {
        return $this->funnelWindow()->from;
    }
}
