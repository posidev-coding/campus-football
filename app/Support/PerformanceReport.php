<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

    public const SLOW_REQUEST = 'slow_request';

    /** Every type reported, in the order a reader wants them. */
    public const TYPES = [self::SLOW_REQUEST, 'slow_query', 'slow_job', 'slow_outgoing_request', self::EXCEPTION];

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
            ->mapWithKeys(fn (string $type): array => [
                $type => $type === self::SLOW_REQUEST ? $this->slowRequests() : $this->top($type),
            ])
            ->all();
    }

    /**
     * The slow requests, each beside the traffic it is a share of.
     *
     * "76 slow" is not readable without "out of how many" (CFB-139), so each
     * row carries the screen's page views over the SAME 24 hours and the SAME
     * audience — every one, as Pulse counts every request — and says so in
     * the row. Never the routes section's 28-day member count: a 24-hour
     * all-audience numerator over that is worse than no denominator.
     *
     * Pulse keys a request by its route URI (`["GET","/players/{athlete}",…]`)
     * and the clickstream by route NAME, so the join is the route table's own
     * GET entry for that exact URI — never a guess. Anything else carries a
     * null route and null views: a POST (every Livewire update is filed under
     * its page's path, and an update is not a view), a URI no route serves
     * any more, a route the sensor does not count. Null is "no denominator",
     * as `errors.client` already means it, and a rate nobody can read is
     * skipped rather than borrowed from another screen.
     *
     * Views are screens rendered — 2xx HTML GETs, navigate hops included —
     * while Pulse also sees a slow redirect. Read it as a rate of views.
     *
     * @return list<array<string, mixed>>
     */
    private function slowRequests(): array
    {
        $rows = $this->top(self::SLOW_REQUEST, withKey: true);

        $routes = array_map(fn (array $row): ?string => self::routeForRequest($row['key']), $rows);

        $views = app(AnalyticsCatalog::class)->routesViews(array_values(array_unique(array_filter($routes))), OpsReport::HOURS);

        return array_map(function (array $row, ?string $route) use ($views): array {
            unset($row['key']);

            return [
                ...$row,
                'route' => $route,
                'views_24h' => $route === null ? null : $views[$route],
                'views_audience' => 'all',
            ];
        }, $rows, $routes);
    }

    /**
     * The GET route a Pulse request key names, by its exact URI — or null.
     *
     * Pulse writes the path as `domain + '/' + uri`, so an undomained route's
     * URI is the path less its leading slash, and the root is `/`.
     */
    public static function routeForRequest(string $key): ?string
    {
        $parts = json_decode($key, true);

        if (! is_array($parts) || ($parts[0] ?? null) !== 'GET' || ! is_string($parts[1] ?? null)) {
            return null;
        }

        $uri = ltrim($parts[1], '/');

        $route = Route::getRoutes()->getRoutesByMethod()['GET'][$uri === '' ? '/' : $uri] ?? null;

        return $route?->getName();
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
    public function top(string $type, bool $withKey = false): array
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
                ...$withKey ? ['key' => (string) $row->key] : [],
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
