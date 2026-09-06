<?php

namespace App\Support;

use App\Actions\RecordActivity;
use App\Actions\RecordUxEvent;
use App\Enums\ActivityKind;
use App\Enums\UxSignal;
use App\Models\ActivityEvent;
use App\Models\ClientError;
use App\Models\FeedRun;
use App\Models\PageViewDaily;
use App\Models\UxEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Pulse\Recorders\SlowRequests;
use Throwable;

/**
 * How the application itself is behaving — the third report in the house
 * shape, beside {@see CoverageReport} (is the DATA there) and
 * {@see PickemPreflight} (is the PRODUCT ready).
 *
 * Same row keys as both on purpose, so one terminal renderer prints all three
 * and one admin page could show them side by side.
 *
 * It reads what Phase 1's sensors record and nothing else: Pulse's own tables
 * for server performance and exceptions, `client_errors` for the half of the
 * app that runs in a browser, `feed_runs` for jobs that died, and `ux_events`
 * for where people stop. Nothing here writes, nothing here fixes; it answers
 * a question, and every row names its remedy because a dashboard that says
 * "broken" without saying "run this" moves the mystery one screen over.
 *
 * AGGREGATE ONLY. This is the payload `cfb:telemetry` hands to a Claude Code
 * routine with no database access, so it carries counts and never a user.
 */
class OpsReport
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** The window every rate here is measured over. */
    public const HOURS = 24;

    /**
     * Buffered Pulse entries that mean the drain is behind rather than busy.
     *
     * A stalled `pulse:work` looks EXACTLY like no traffic — the dashboard
     * empties and nothing errors — so this is the only row that catches the
     * monitor failing rather than the app.
     */
    public const INGEST_WARN = 500;

    public const INGEST_FAIL = 5_000;

    /**
     * The same question asked of the clickstream, at ten times the numbers.
     *
     * A page view is one stream entry per screen somebody read, where a Pulse
     * entry is one per slow thing — so a busy Saturday buffers thousands
     * between two five-minute drains and is perfectly healthy. Five thousand
     * is roughly an hour of missed drains at pilot traffic; fifty thousand is
     * a quarter of `RecordActivity::MAXLEN`, which is the point where the
     * stream starts trimming the oldest entries and the day is lost rather
     * than late.
     */
    public const ACTIVITY_WARN = 5_000;

    public const ACTIVITY_FAIL = 50_000;

    /**
     * The hour by which yesterday must be rolled. The scheduled pass runs at
     * 04:56 league time; an hour of slack keeps a slow night off the report.
     */
    public const ROLLUP_DUE_HOUR = 6;

    /**
     * Where a pick-through rate stops being a slow week and starts being a
     * broken screen. A FIRST CALIBRATION, like the quality weights: it has
     * never been measured against a real Saturday, and it should be revisited
     * once one exists rather than trusted.
     */
    public const ABANDON_WARN = 0.5;

    /** Below this many samples a rate is noise, and the row stays quiet. */
    public const RATE_SAMPLE_FLOOR = 20;

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, remedy: string|null}>
     */
    public function checks(): array
    {
        return [
            $this->ingest(),
            $this->exceptions(),
            $this->slowRequests(),
            $this->slowQueries(),
            $this->failedJobs(),
            $this->clientErrors(),
            $this->activityIngest(),
            $this->activityRollup(),
            $this->pickThrough(),
        ];
    }

    public function failing(): int
    {
        return collect($this->checks())->where('status', self::FAIL)->count();
    }

    /**
     * Is `pulse:work` actually draining?
     *
     * Recorders push onto a Redis stream and the daemon drains it to MySQL. If
     * the daemon is not running, the stream grows and every Pulse-fed row
     * below silently reads "nothing happened".
     */
    private function ingest(): array
    {
        $buffered = $this->streamLength();

        if ($buffered === null) {
            return $this->row(
                'pulse_ingest',
                'Pulse ingest',
                self::FAIL,
                'Could not reach the telemetry Redis database.',
                'Check REDIS_HOST and the `pulse` connection in config/database.php.',
            );
        }

        $status = match (true) {
            $buffered >= self::INGEST_FAIL => self::FAIL,
            $buffered >= self::INGEST_WARN => self::WARN,
            default => self::OK,
        };

        return $this->row(
            'pulse_ingest',
            'Pulse ingest',
            $status,
            $buffered === 0
                ? 'Buffer empty — the drain is keeping up'
                : "{$buffered} entries buffered and not yet written",
            $status === self::OK ? null : 'php artisan pulse:work — a stalled drain looks exactly like no traffic',
        );
    }

    private function exceptions(): array
    {
        $count = $this->pulseCount('exception');

        return $this->row(
            'exceptions',
            'Exceptions · '.self::HOURS.'h',
            $count === 0 ? self::OK : self::WARN,
            $count === 0
                ? 'None thrown'
                // LATEST, not worst. For `exception` Pulse writes the
                // occurrence timestamp into `value`, so ordering by it means
                // "most recently thrown" — the four slow_* rows below order by
                // a real duration and say "worst" honestly.
                : "{$count} thrown · latest: ".$this->pulseTopKey('exception'),
            $count === 0 ? null : 'Open /pulse for the stack traces.',
        );
    }

    private function slowRequests(): array
    {
        $count = $this->pulseCount('slow_request');

        return $this->row(
            'slow_requests',
            'Slow requests · '.self::HOURS.'h',
            $count === 0 ? self::OK : self::WARN,
            $count === 0
                ? 'Nothing over the threshold'
                : "{$count} over ".config('pulse.recorders.'.SlowRequests::class.'.threshold').'ms · worst: '.$this->pulseTopKey('slow_request'),
            $count === 0 ? null : 'Open /pulse; the slowest route is where to start.',
        );
    }

    /**
     * The only detector a missing eager load has.
     *
     * `Model::preventLazyLoading()`'s per-instance flag is false under test, so
     * NO feature test catches an N+1 — a `<x-game-card>` in a rail panel once
     * shipped a 500 on /rankings through a fully green suite. This row is what
     * sees it, and only in production.
     */
    private function slowQueries(): array
    {
        $count = $this->pulseCount('slow_query');

        return $this->row(
            'slow_queries',
            'Slow queries · '.self::HOURS.'h',
            $count === 0 ? self::OK : self::WARN,
            $count === 0
                ? 'Nothing over the threshold'
                : "{$count} over threshold · worst: ".$this->pulseTopKey('slow_query'),
            $count === 0 ? null : 'Check the eager loads on the calling screen — no test can catch this one.',
        );
    }

    /**
     * Jobs that died, from our own ledger.
     *
     * Laravel Cloud's managed queues keep `failed_jobs` to themselves, so the
     * `Queue::failing` hook's `job:` rows are the only record the app can read.
     */
    private function failedJobs(): array
    {
        $count = FeedRun::query()
            ->where('status', FeedRun::FAILED)
            ->where('command', 'like', 'job:%')
            ->where('started_at', '>=', $this->since())
            ->count();

        return $this->row(
            'failed_jobs',
            'Failed jobs · '.self::HOURS.'h',
            match (true) {
                $count === 0 => self::OK,
                $count >= 10 => self::FAIL,
                default => self::WARN,
            },
            $count === 0 ? 'None' : "{$count} died",
            $count === 0 ? null : 'Sync Health → Recent failures, then the Cloud dashboard for the payload.',
        );
    }

    /**
     * Is `cfb:activity-drain` actually draining?
     *
     * The `pulse_ingest` row one layer over, and it fails the same way: a
     * stalled drain is indistinguishable from a quiet week on every widget
     * the rollups feed, because both render as no rows. Nothing throws,
     * nothing 500s, and the only tell is the buffer.
     */
    private function activityIngest(): array
    {
        $buffered = app(RecordActivity::class)->pending();

        if ($buffered === null) {
            return $this->row(
                'activity_ingest',
                'Activity ingest',
                self::FAIL,
                'Could not reach the telemetry Redis database.',
                'Check REDIS_HOST and the `pulse` connection in config/database.php.',
            );
        }

        $status = match (true) {
            $buffered >= self::ACTIVITY_FAIL => self::FAIL,
            $buffered >= self::ACTIVITY_WARN => self::WARN,
            default => self::OK,
        };

        return $this->row(
            'activity_ingest',
            'Activity ingest',
            $status,
            $buffered === 0
                ? 'Buffer empty — the drain is keeping up'
                : "{$buffered} page views buffered and not yet written",
            $status === self::OK ? null : 'php artisan cfb:activity-drain — above '.number_format(self::ACTIVITY_FAIL).' the stream starts trimming the oldest entries',
        );
    }

    /**
     * Did yesterday get folded into the tables that live on?
     *
     * Measured against the RAW rows rather than against the clock alone, so a
     * genuinely quiet day reads OK instead of crying wolf every morning of the
     * offseason — and so a PARTIAL roll is caught too, which a bare
     * "is there a row" check would call healthy.
     */
    private function activityRollup(): array
    {
        $yesterday = now(config('cfb.timezone'))->subDay()->toDateString();

        $raw = ActivityEvent::query()
            ->where('day', $yesterday)
            ->where('kind', ActivityKind::PageView->value)
            ->count();

        if ($raw === 0) {
            return $this->row(
                'activity_rollup',
                'Activity rollup',
                self::OK,
                "No page views recorded on {$yesterday} — nothing to roll",
                null,
            );
        }

        $rolled = (int) PageViewDaily::query()->where('day', $yesterday)->sum('views');

        if ($rolled >= $raw) {
            return $this->row(
                'activity_rollup',
                'Activity rollup',
                self::OK,
                "{$rolled} views rolled for {$yesterday}",
                null,
            );
        }

        // Before the pass is due, "not yet" is the honest answer rather than
        // a warning about work nobody has asked for.
        $due = now(config('cfb.timezone'))->hour >= self::ROLLUP_DUE_HOUR;

        return $this->row(
            'activity_rollup',
            'Activity rollup',
            $due ? self::WARN : self::OK,
            $due
                ? "{$rolled} of {$raw} views rolled for {$yesterday}"
                : "{$yesterday} has not been rolled yet — the daily pass runs at 04:56",
            $due ? "php artisan cfb:activity-rollup --day={$yesterday}" : null,
        );
    }

    private function clientErrors(): array
    {
        $rows = ClientError::query()->where('created_at', '>=', $this->since());

        $distinct = (clone $rows)->distinct()->count('fingerprint');
        $reports = (int) (clone $rows)->sum('reports');

        return $this->row(
            'client_errors',
            'Browser errors · '.self::HOURS.'h',
            $distinct === 0 ? self::OK : self::WARN,
            $distinct === 0
                ? 'None reported'
                : "{$distinct} distinct, {$reports} reports · worst: ".$this->worstClientError(),
            $distinct === 0 ? null : 'A 390px PWA ships this class of bug silently — no server log sees it.',
        );
    }

    /**
     * How many people who opened a slate for the first time actually picked.
     *
     * "Abandoned with zero picks" is DERIVED here rather than counted as its
     * own signal — a third counter for a difference is a third counter that
     * can disagree with the other two.
     *
     * A DIVISION IS ONLY A RATE WHEN BOTH SIDES COUNT ONE POPULATION.
     * `first_pick_made` fires once per (user, slate) for all time, so
     * `slate_entered` skips a member who already has an entry — otherwise
     * every reopen of a sheet somebody already filled in grew the
     * denominator against a numerator that could never answer it, and the
     * reported rate fell as engagement rose. Read the number as a FLOOR
     * either way: a member who opens on three days and never picks still
     * counts three times, which this pipeline cannot close without
     * persisting who read what.
     */
    private function pickThrough(): array
    {
        $entered = $this->funnelTotal(UxSignal::SlateEntered);
        $picked = $this->funnelTotal(UxSignal::FirstPickMade);

        if ($entered < self::RATE_SAMPLE_FLOOR) {
            return $this->row(
                'pick_through',
                'Pick-through · 7d',
                self::OK,
                $entered === 0
                    ? 'No slates opened yet'
                    : "Only {$entered} first-time slate opens — too few to read a rate from",
                null,
            );
        }

        $abandoned = ($entered - $picked) / $entered;
        $rate = round((1 - $abandoned) * 100);

        return $this->row(
            'pick_through',
            'Pick-through · 7d',
            $abandoned > self::ABANDON_WARN ? self::WARN : self::OK,
            "{$rate}% of first-time slate opens became a pick ({$picked} of {$entered})",
            $abandoned > self::ABANDON_WARN
                ? 'More than half open a slate for the first time and leave without picking. Walk the pick surface at 390px.'
                : null,
        );
    }

    // ------------------------------------------------------------- readers

    private function since(): CarbonInterface
    {
        return now()->subHours(self::HOURS);
    }

    /** Entries of one Pulse type inside the window. Zero when Pulse is absent. */
    private function pulseCount(string $type): int
    {
        if (! Schema::hasTable('pulse_entries')) {
            return 0;
        }

        return DB::table('pulse_entries')
            ->where('type', $type)
            ->where('timestamp', '>=', $this->since()->getTimestamp())
            ->count();
    }

    /** The heaviest entry of one type, named for a human. */
    /**
     * The leading entry of one Pulse type, by `value` — and `value` does not
     * mean the same thing for every type, which is why this is NOT called
     * `pulseWorstKey` any more.
     *
     * Pulse stores a DURATION IN MILLISECONDS for slow_request, slow_query,
     * slow_job and slow_outgoing_request, and the OCCURRENCE TIMESTAMP for
     * `exception` (`Recorders/Exceptions.php`, `value: $timestamp`). So
     * `orderByDesc('value')` is "slowest" for four types and "most recent" for
     * the fifth. Both are the right order for their type; the caller supplies
     * the word, because only the caller knows which one it asked for.
     *
     * Same split `TelemetrySnapshot::pulseTop()` carries, and the same reason
     * its SQL alias is `max_value` rather than `worst`.
     */
    private function pulseTopKey(string $type): string
    {
        if (! Schema::hasTable('pulse_entries')) {
            return 'unknown';
        }

        $key = DB::table('pulse_entries')
            ->where('type', $type)
            ->where('timestamp', '>=', $this->since()->getTimestamp())
            ->orderByDesc('value')
            ->value('key');

        return $key === null ? 'unknown' : self::readableKey($key);
    }

    /**
     * Pulse stores a key as a JSON array — `["GET","/picks","App\\…"]` for a
     * request, `[sql, location]` for a query. Join the first two parts and
     * leave the rest; the raw key is in Pulse's own dashboard for anyone who
     * wants it.
     */
    public static function readableKey(string $key): string
    {
        $parts = json_decode($key, true);

        $text = is_array($parts)
            ? implode(' ', array_map(fn ($part) => (string) $part, array_slice($parts, 0, 2)))
            : $key;

        return trim(preg_replace('/\s+/', ' ', mb_substr($text, 0, 120)));
    }

    private function worstClientError(): string
    {
        $error = ClientError::query()
            ->where('created_at', '>=', $this->since())
            ->orderByDesc('reports')
            ->first();

        return $error === null ? 'unknown' : mb_substr($error->message, 0, 120);
    }

    /** A signal's total over the last week of PERSISTED days plus today. */
    private function funnelTotal(UxSignal $signal): int
    {
        $persisted = (int) UxEvent::query()
            ->where('signal', $signal->value)
            ->where('day', '>=', now()->timezone(config('cfb.timezone'))->subDays(7)->toDateString())
            ->sum('count');

        // Today has not been rolled up yet, and leaving it out would make
        // this blind to the hours somebody is actually asking about.
        return $persisted + app(RecordUxEvent::class)->todayCount($signal);
    }

    /** Buffered Pulse entries, or null when Redis cannot be reached at all. */
    private function streamLength(): ?int
    {
        try {
            return (int) Redis::connection('pulse')->xlen('laravel:pulse:ingest');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string, remedy: string|null}
     */
    private function row(string $key, string $label, string $status, string $detail, ?string $remedy): array
    {
        return compact('key', 'label', 'status', 'detail', 'remedy');
    }
}
