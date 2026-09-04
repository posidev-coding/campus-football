<?php

namespace App\Support;

use App\Models\FeedRun;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
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
     * @return list<array{
     *     name: string, cadence: string, tracked: ?string, gated: bool,
     *     run: ?FeedRun, overdue: bool,
     * }>
     */
    public function tasks(): array
    {
        return collect($this->events())
            ->map(fn (Event $event) => $this->task($event))
            ->filter()
            ->values()
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
     * @return array{
     *     name: string, cadence: string, tracked: ?string, gated: bool,
     *     run: ?FeedRun, overdue: bool,
     * }|null
     */
    private function task(Event $event): ?array
    {
        $name = $this->displayName($event);

        if ($name === null) {
            return null;
        }

        $tracked = $this->ledgerKey($name);
        $run = $tracked !== null ? FeedRun::latestFor($tracked) : null;

        // The season gates are pure month checks and the overlap mutex is a
        // cache read, so evaluating the filters is safe — and it is what
        // stops August flagging every offseason-gated task as overdue.
        $gated = ! $event->filtersPass(app());

        return [
            'name' => $name,
            'cadence' => $this->cadence($event),
            'tracked' => $tracked,
            'gated' => $gated,
            'run' => $run,
            'overdue' => ! $gated && $tracked !== null && $this->overdue($event, $run),
        ];
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
            str_starts_with($command, 'cfb:sync') => 'sync:'.($option('only') ?? 'all'),
            str_starts_with($command, 'cfb:players') => 'players:'.($option('only') ?? 'all'),
            str_starts_with($command, 'cfb:coaches') => 'coaches',
            str_starts_with($command, 'cfb:aggregate') => 'aggregate',
            str_starts_with($command, 'cfb:newsletter') => 'newsletter',
            str_starts_with($command, 'cfb:verification-reminders') => 'verification-reminders',
            // Both of these DO write feed runs and were rendering as
            // permanently grey "untracked" rows — the state this method exists
            // to distinguish from "ran and found nothing". A command that
            // gains a trackRun() key needs a line here or its row lies.
            str_starts_with($command, 'cfb:kickoff-alerts') => 'kickoff-alerts',
            str_starts_with($command, 'cfb:ux-rollup') => 'ux:rollup',
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
        $cron = new CronExpression($event->expression);
        $timezone = $event->timezone ?? config('app.timezone');

        $previous = $cron->getPreviousRunDate(now($timezone), nth: 1, allowCurrentDate: false, timeZone: $timezone);

        return $run === null || $run->started_at->lt($previous);
    }
}
