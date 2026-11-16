<?php

namespace App\Support;

use App\Models\FeedRun;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The schedule as data: every cfb and pick'em task, its cadence, and its
 * latest ledger row, with an overdue flag derived from the task's own cron
 * expression.
 *
 * Introspected from Schedule::events() rather than from a hand-kept list, so
 * a task added to routes/console.php appears here without anyone remembering
 * a second registry — the same single-source rule as Navigation.
 *
 * The one catch: routes/console.php loads only when the CONSOLE kernel
 * bootstraps, so in an HTTP request the resolved Schedule is empty. The
 * admin page is an HTTP request. bootstrap() on the console kernel is the
 * framework's own path to loading the command routes, and the file is
 * guaranteed side-effect-free while loading — "never touch the database
 * while the schedule file loads" is already a project rule it survives a
 * deploy build by.
 */
class SyncSchedule
{
    /**
     * The command prefixes this report covers — an ALLOWLIST, and still a
     * deliberate one rather than "everything on the schedule".
     *
     * `model:prune` is on the schedule too and stays off the report for the
     * reason it always did: it is the ledger's own housekeeping, it writes no
     * feed run, and it could only ever render as a permanently grey
     * "untracked" row that means nothing.
     *
     * The pick'em sweeps are the opposite case, and they were dropped here for
     * no better reason than their prefix. They are the weekly loop the product
     * turns on, and PickemPreflight can only say they are REGISTERED — nothing
     * else in the app answers whether one actually ran, so a dead worker or a
     * stuck overlap mutex left the preflight green and this panel silent.
     *
     * @var list<string>
     */
    private const REPORTED = ['cfb:', 'pickem:'];

    /**
     * How many of a shared entry's own due moments the ledger is searched
     * across for its run. Overdue only needs two; the rest is so a row that
     * missed one pass still shows the pass before it.
     */
    private const SHARED_LOOKBACK = 4;

    /**
     * @return list<array{
     *     name: string, cadence: string, tracked: ?string, gated: bool,
     *     shared: bool, run: ?FeedRun, overdue: bool,
     * }>
     */
    public function tasks(): array
    {
        $entries = collect($this->events())
            ->map(fn (Event $event) => $this->entry($event))
            ->filter()
            ->values()
            ->all();

        $runs = $this->runs($entries);

        $sharing = collect($entries)->pluck('tracked')->filter()->countBy();

        return collect($entries)
            ->map(fn (array $entry, int $index) => [
                'name' => $entry['name'],
                'cadence' => $this->cadence($entry['event']),
                'tracked' => $entry['tracked'],
                'gated' => $entry['gated'],
                'shared' => $entry['tracked'] !== null && $sharing[$entry['tracked']] > 1,
                'run' => $runs[$index],
                'overdue' => ! $entry['gated'] && $entry['tracked'] !== null && $this->overdue($entry['event'], $runs[$index]),
            ])
            ->all();
    }

    /**
     * THE ONLY PLACE `Schedule::events()` IS READ, and it has to stay that way.
     *
     * A raw read returns an EMPTY list over HTTP, because routes/console.php
     * loads only when the console kernel bootstraps. That is not an error and
     * nothing throws — the caller simply concludes that nothing is scheduled.
     * `PickemPreflight` read it raw and reported all four Pick'em sweeps
     * missing on every admin page load, a red launch gate over a schedule that
     * was entirely correct.
     *
     * Under `php artisan` the kernel has already bootstrapped, so a test can
     * never see the empty case: `ScheduleReadersTest` guards the call site
     * instead of the behavior.
     *
     * @return list<Event>
     */
    public function events(): array
    {
        $schedule = app(Schedule::class);

        if ($schedule->events() === []) {
            app(Kernel::class)->bootstrap();
        }

        return $schedule->events();
    }

    /**
     * @return array{event: Event, name: string, tracked: ?string, gated: bool}|null
     */
    private function entry(Event $event): ?array
    {
        $name = $this->displayName($event);

        if ($name === null) {
            return null;
        }

        return [
            'event' => $event,
            'name' => $name,
            'tracked' => $this->ledgerKey($name),
            // The season gates are pure month checks, so evaluating the filters
            // is safe — and it is what stops August flagging every offseason-
            // gated task as overdue.
            'gated' => ! $this->scheduledNow($event),
        ];
    }

    /**
     * Whether the entry's OWN filters — its between() window, its season gate,
     * its when() and skip() — let it run right now, with the overlap mutex left
     * out.
     *
     * `withoutOverlapping()` is a skip() filter too: it rejects while
     * `mutex->exists()`, so asking `filtersPass()` raw answers "gated" for a
     * pass that is running, and for a crashed one whose lock has not expired
     * (five minutes for the drain, thirty for the hourly rollup). That is the
     * dead pipeline reading as asleep in the daytime — the one state this
     * report exists to tell apart. A held mutex says something is or was
     * running, never that the schedule is off, so the read answers with a
     * mutex that holds nothing and puts the real one straight back.
     */
    private function scheduledNow(Event $event): bool
    {
        $mutex = $event->mutex;

        $event->mutex = new class implements EventMutex
        {
            public function create(Event $event): bool
            {
                return true;
            }

            public function exists(Event $event): bool
            {
                return false;
            }

            public function forget(Event $event): void {}
        };

        try {
            return $event->filtersPass(app());
        } finally {
            $event->mutex = $mutex;
        }
    }

    /**
     * Each entry's OWN last run, by index — never a sibling's.
     *
     * A ledger key belongs to a COMMAND, and the schedule runs some commands
     * from several entries: predictors on Wednesday, Thursday and Saturday,
     * the drain on an in-season and an off-season cadence, the current games
     * tier on Tue/Wed and Thu–Sun. Reading `latestFor()` per entry handed
     * every one of them the same row, so the Thursday predictors pass
     * reported complete at Saturday 08:00 — and a Thursday pass that had
     * stopped would never have looked overdue.
     *
     * So a shared key's recent runs are dealt out instead: each run belongs
     * to the entry that came due most recently before it started, the
     * ungated one winning a tie (06:00 is due for both the hourly and the
     * six-hourly news sync, and only one of them is on). An entry that owns
     * no run in its last few due moments gets NULL — no evidence is no
     * evidence, and a sibling's stamp is not evidence.
     *
     * @param  list<array{event: Event, name: string, tracked: ?string, gated: bool}>  $entries
     * @return array<int, ?FeedRun>
     */
    private function runs(array $entries): array
    {
        $runs = array_fill(0, count($entries), null);

        $groups = collect($entries)
            ->filter(fn (array $entry) => $entry['tracked'] !== null)
            ->groupBy(fn (array $entry) => $entry['tracked'], preserveKeys: true);

        foreach ($groups as $key => $group) {
            if ($group->count() === 1) {
                $runs[$group->keys()->first()] = FeedRun::latestFor($key);

                continue;
            }

            foreach ($this->recentRuns($key, $group) as $run) {
                $owner = $this->owner($group, $run);

                $runs[$owner] ??= $run;
            }
        }

        return $runs;
    }

    /**
     * A shared key's runs, newest first, back to the earliest of its
     * ungated entries' last few due moments (all entries', when every one is
     * gated). Bounded by the cadences rather than a row count, so the five-
     * minute drain cannot crowd a slower sibling out of the window.
     *
     * @param  Collection<int, array{event: Event, name: string, tracked: ?string, gated: bool}>  $group
     * @return Collection<int, FeedRun>
     */
    private function recentRuns(string $key, Collection $group): Collection
    {
        $judged = $group->reject(fn (array $entry) => $entry['gated']);

        $since = ($judged->isEmpty() ? $group : $judged)
            ->map(fn (array $entry) => $this->dueBefore($entry['event'], now(), self::SHARED_LOOKBACK - 1, allowCurrent: false))
            ->min();

        return FeedRun::where('command', $key)
            ->where('started_at', '>=', $since)
            ->orderByDesc('started_at')
            ->get();
    }

    /**
     * The index of the entry whose most recent due moment at or before the
     * run's start is the latest — the pass that run was. Ties go to an
     * ungated entry, then to schedule order.
     *
     * @param  Collection<int, array{event: Event, name: string, tracked: ?string, gated: bool}>  $group
     */
    private function owner(Collection $group, FeedRun $run): int
    {
        return $group
            ->map(fn (array $entry, int $index) => [
                'index' => $index,
                'due' => $this->dueBefore($entry['event'], $run->started_at, 0, allowCurrent: true)->getTimestamp(),
                'gated' => $entry['gated'],
            ])
            ->sortBy([['due', 'desc'], ['gated', 'asc'], ['index', 'asc']])
            ->first()['index'];
    }

    /** The event's nth most recent due moment before (or at) the given time. */
    private function dueBefore(Event $event, DateTimeInterface $at, int $nth, bool $allowCurrent): CarbonImmutable
    {
        $timezone = $event->timezone ?? config('app.timezone');

        $due = (new CronExpression($event->expression))->getPreviousRunDate(
            CarbonImmutable::instance($at)->setTimezone($timezone),
            nth: $nth,
            allowCurrentDate: $allowCurrent,
            timeZone: $timezone,
        );

        return CarbonImmutable::instance($due);
    }

    /** The artisan invocation without the binary prefix, or the closure's name. */
    private function displayName(Event $event): ?string
    {
        if ($event instanceof CallbackEvent) {
            $description = (string) $event->description;

            return Str::startsWith($description, self::REPORTED) ? $description : null;
        }

        $command = (string) $event->command;
        $artisan = strpos($command, "'artisan' ");

        if ($artisan !== false) {
            $command = substr($command, $artisan + strlen("'artisan' "));
        }

        return Str::contains($command, self::REPORTED) ? trim($command) : null;
    }

    /**
     * The ledger key a command's TracksFeedRun rows are written under, parsed
     * from the same option syntax the schedule states it with.
     */
    private function ledgerKey(string $command): ?string
    {
        $option = function (string $name) use ($command): ?string {
            return preg_match('/--'.$name.'[= ]([\w-]+)/', $command, $m) ? $m[1] : null;
        };

        return match (true) {
            str_starts_with($command, 'cfb:games') => 'games:'.($option('tier') ?? 'current'),
            str_starts_with($command, 'cfb:summaries:live') => 'summaries:live',
            str_starts_with($command, 'cfb:summaries') => 'summaries',
            // `--year=next` is a different class from `--year=current`, and the
            // command writes it under its own key — see SyncSeasonCommand.
            str_starts_with($command, 'cfb:sync') => 'sync:'.($option('only') ?? 'all').($option('year') === 'next' ? ':next' : ''),
            str_starts_with($command, 'cfb:players') => 'players:'.($option('only') ?? 'all'),
            str_starts_with($command, 'cfb:coaches') => 'coaches',
            str_starts_with($command, 'cfb:aggregate') => 'aggregate',
            // Above `cfb:newsletter` for the same reason `cfb:summaries:live`
            // sits above `cfb:summaries`: the arms are prefix matches read in
            // order, and a news key must never be answered by a mail one.
            str_starts_with($command, 'cfb:news:followed') => 'news:followed',
            str_starts_with($command, 'cfb:newsletter') => 'newsletter',
            str_starts_with($command, 'cfb:verification-reminders') => 'verification-reminders',
            // Both of these DO write feed runs and were rendering as
            // permanently grey "untracked" rows — the state this method exists
            // to distinguish from "ran and found nothing". A command that
            // gains a trackRun() key needs a line here or its row lies.
            str_starts_with($command, 'cfb:kickoff-alerts') => 'kickoff-alerts',
            str_starts_with($command, 'cfb:ux-rollup') => 'ux:rollup',
            /*
             * The clickstream pair. Both write feed runs, and the drain is
             * the one row on this panel that catches a dead PIPELINE rather
             * than a dead app: a stalled drain looks exactly like a quiet
             * week, on every widget the rollups feed, with nothing throwing
             * anywhere.
             */
            str_starts_with($command, 'cfb:activity-drain') => 'activity:drain',
            // The hourly `--today` pass writes its own key, or the daily
            // 04:56 run reads as its last run all day.
            str_starts_with($command, 'cfb:activity-rollup') => str_contains($command, '--today') ? 'activity:rollup:today' : 'activity:rollup',
            str_starts_with($command, 'cfb:gameday') => 'gameday',
            /*
             * ALL FOUR pick'em sweeps write rows now. Three of them did not,
             * and being untracked was honest but blind: they are the weekly
             * loop the product turns on, they spend no ESPN request so they
             * cost nothing to record, and `pickem:preflight` could only ever
             * say they were REGISTERED. A quiet hour and a dead worker
             * rendered identically on this panel for the whole of the regular
             * season until they each gained a trackRun().
             */
            str_starts_with($command, 'pickem:remind') => 'pick-reminders',
            str_starts_with($command, 'pickem:publish-slates') => 'publish-slates',
            str_starts_with($command, 'pickem:settle') => 'settle-slates',
            str_starts_with($command, 'pickem:open-lobbies') => 'open-lobbies',
            default => null,
        };
    }

    /**
     * Readable cadence for the expressions the schedule actually uses,
     * falling back to the raw cron for anything new.
     */
    private function cadence(Event $event): string
    {
        $expression = (string) $event->expression;

        if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $expression, $m)) {
            return "every {$m[1]} min";
        }

        if (preg_match('/^0 \*\/(\d+) \* \* \*$/', $expression, $m)) {
            return "every {$m[1]} hours";
        }

        if (preg_match('/^0 \* \* \* ([0-6](?:,[0-6])*)$/', $expression, $m)) {
            $days = collect(explode(',', $m[1]))
                ->map(fn (string $d) => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][(int) $d])
                ->implode('/');

            return "hourly {$days}";
        }

        if (preg_match('/^0 (\d+)-(\d+) \* \* ([0-6](?:,[0-6])*)$/', $expression, $m)) {
            $days = collect(explode(',', $m[3]))
                ->map(fn (string $d) => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][(int) $d])
                ->implode('/');

            return sprintf('hourly %s %02d:00-%02d:00', $days, $m[1], $m[2]);
        }

        if (preg_match('/^(\d+) (\d+) \* \* ([0-6](?:,[0-6])*)$/', $expression, $m)) {
            $days = collect(explode(',', $m[3]))
                ->map(fn (string $d) => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][(int) $d])
                ->implode('/');

            return sprintf('%s %02d:%02d', $days, $m[2], $m[1]);
        }

        if (preg_match('/^(\d+) (\d+) 1 \* \*$/', $expression, $m)) {
            return sprintf('monthly %02d:%02d', $m[2], $m[1]);
        }

        if (preg_match('/^(\d+) (\d+) \* \* \*$/', $expression, $m)) {
            return sprintf('daily %02d:%02d', $m[2], $m[1]);
        }

        return match ($expression) {
            '* * * * *' => 'every min',
            '0 * * * *' => 'hourly',
            default => $expression,
        };
    }

    /**
     * A task is overdue when its last recorded run predates the SECOND most
     * recent due moment — one full period of slack, so the minute between a
     * due time and its tick never reads as a failure, while a live tier that
     * has missed two minutes on a Saturday afternoon does.
     */
    private function overdue(Event $event, ?FeedRun $run): bool
    {
        $previous = $this->dueBefore($event, now(), 1, allowCurrent: false);

        return $run === null || $run->started_at->lt($previous);
    }
}
