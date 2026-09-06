<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pulse's own tables, read straight — the heaviest entries of each type,
 * grouped by key.
 *
 * The decisive advantage of Pulse over a hosted APM for this app: the data is
 * in our MySQL, so a report is a query rather than an API call, a rate limit
 * and a bill.
 *
 * ONE OBJECT, TWO SURFACES, for the reason {@see CoverageReport} already
 * settled — this used to be a private method on {@see TelemetrySnapshot}, and
 * the Health dashboard was about to grow a second copy of it. The panel and
 * the payload must not be able to disagree about which query is the slowest.
 */
class PerformanceReport
{
    /**
     * The one Pulse type whose `value` is not a duration. Named, because the
     * whole of {@see top()}'s split personality hangs off this comparison.
     */
    public const EXCEPTION = 'exception';

    /** Every type reported, in the order a reader wants them. */
    public const TYPES = ['slow_request', 'slow_query', 'slow_job', 'slow_outgoing_request', self::EXCEPTION];

    /**
     * Every type's top entries, keyed by type.
     *
     * Empty when Pulse has never migrated — a report is not the place to
     * discover that, and an exception here would take the whole snapshot with
     * it.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function checks(): array
    {
        if (! Schema::hasTable('pulse_entries')) {
            return [];
        }

        return collect(self::TYPES)
            ->mapWithKeys(fn (string $type): array => [$type => $this->top($type)])
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
    public function top(string $type): array
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
}
