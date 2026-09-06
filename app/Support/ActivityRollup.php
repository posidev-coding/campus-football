<?php

namespace App\Support;

use App\Enums\ActivityArea;
use App\Enums\ActivityFeature;
use App\Enums\ActivityKind;
use App\Enums\ViewportBucket;
use App\Models\ActivityEvent;
use App\Models\PageViewDaily;
use App\Models\UserDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One league day of clickstream, folded into the two tables that live on.
 *
 * The raw rows keep thirty days and carry a person; `page_views_daily` and
 * `user_days` keep counts forever. This is the fold between them, and it is
 * the only writer of either.
 *
 * IDEMPOTENT BY CONSTRUCTION. Every write is an upsert on the table's own
 * unique key — `(day, route, facet, audience, viewport_bucket, installed)` and
 * `(user_id, day)` — so re-rolling a day CORRECTS it and can never double it.
 * That is what makes `--day=` a repair rather than a risk: a rollup bug is
 * fixed by running the fix over the last thirty days, no backfill and no
 * delete.
 *
 * The fold happens in PHP rather than in one GROUP BY, deliberately. Three of
 * the dimensions are not columns — the viewport bucket is
 * `ViewportBucket::for()`, the area bitmask is `Navigation::areas()` read
 * through `ActivityArea::forRoute()`, and the feature bitmask is half
 * clickstream and half truth table. Restating any of them as a SQL CASE would
 * be a second copy of a boundary that already exists in one place, and the
 * two would drift the first time a breakpoint or a nav route moved. The read
 * is chunked and the day is a few thousand rows.
 *
 * `user_days` is NOT built from the clickstream alone. A person who tapped a
 * pick straight out of a push notification and never rendered a second screen
 * still played that day, and the truth tables are what know it — so `picks`,
 * `conversation_posts`, `team_follows`, `group_members` and `group_invites`
 * are joined in per day, each contributing its bit and its own timestamps.
 * That also means a row can exist for somebody with zero views, which is the
 * honest shape: absence of a VIEW is not absence of a person.
 */
class ActivityRollup
{
    /**
     * Routes that count as reading the stats surface.
     *
     * A list rather than a string because this screen has split before — Team
     * Stats and Player Stats were two screens and are one with a sub-toggle
     * now — and the adoption bit has to survive the next time it moves.
     */
    public const STATS_ROUTES = ['stats'];

    /** Reading the talk: the clubhouse facet, and the standalone Talk screen. */
    public const TALK_ROUTES = ['pickem.talk'];

    public const TALK_FACET = 'talk';

    public const LOBBY_ROUTE = 'pickem.lobby';

    /** Rows per upsert statement. */
    private const CHUNK = 500;

    /**
     * Recompute one league day into both tables.
     *
     * @return array{page_views: int, user_days: int}
     */
    public function day(CarbonImmutable $day): array
    {
        /*
         * A `date` cast arrives as midnight UTC, so the calendar date is
         * RE-PINNED in the league timezone rather than converted — converting
         * lands at 20:00 the previous evening and rolls the wrong day. Same
         * distinction `Cadence` draws.
         */
        $date = $day->toDateString();
        $start = CarbonImmutable::parse($date, config('cfb.timezone'))->startOfDay();
        $end = $start->addDay();

        $cells = [];
        $people = [];

        ActivityEvent::query()
            ->where('day', $date)
            ->chunkById(2_000, function (Collection $events) use (&$cells, &$people): void {
                foreach ($events as $event) {
                    $this->fold($event, $cells, $people);
                }
            });

        $this->mergeTruth($people, $start, $end);

        return [
            'page_views' => $this->writeCells($date, $cells),
            'user_days' => $this->writePeople($date, $people),
        ];
    }

    /**
     * Today so far, on the same code path — the dashboards label it "so far"
     * because it is a partial day and saying otherwise would make the last
     * point on every chart read as a collapse.
     *
     * @return array{page_views: int, user_days: int}
     */
    public function today(): array
    {
        return $this->day(CarbonImmutable::now(config('cfb.timezone'))->startOfDay());
    }

    /**
     * The first league day either table holds, or null before anything has
     * been rolled — every window's `since`.
     *
     * Null rather than today: a window that has no data is not a window that
     * measured zero, and the difference is the whole of the `funnel_since`
     * rule generalized one layer up.
     */
    public function since(): ?string
    {
        $days = array_filter([
            PageViewDaily::query()->min('day'),
            UserDay::query()->min('day'),
        ]);

        return $days === [] ? null : substr((string) min($days), 0, 10);
    }

    // ------------------------------------------------------------ the fold

    /**
     * @param  array<string, array<string, mixed>>  $cells
     * @param  array<int, array<string, mixed>>  $people
     */
    private function fold(ActivityEvent $event, array &$cells, array &$people): void
    {
        $view = $event->kind === ActivityKind::PageView;
        $bucket = ViewportBucket::for($event->viewport)->value;
        $installed = match ($event->standalone) {
            null => PageViewDaily::UNKNOWN,
            true => PageViewDaily::STANDALONE,
            false => PageViewDaily::BROWSER,
        };

        if ($view) {
            $facet = (string) $event->facet;
            $key = implode('|', [$event->route, $facet, $event->audience, $bucket, $installed]);

            $cell = $cells[$key] ?? [
                'route' => $event->route,
                'facet' => $facet,
                'audience' => (int) $event->audience,
                'viewport_bucket' => $bucket,
                'installed' => $installed,
                'views' => 0,
                'navigate_views' => 0,
                'visitors' => [],
            ];

            $cell['views']++;
            $cell['navigate_views'] += $event->via_navigate ? 1 : 0;
            /*
             * A SET, counted at write time. `visitors` is distinct INSIDE the
             * cell and non-additive across cells, which is exactly why it
             * cannot be summed later out of the daily table — one person on
             * two viewports is two rows and one person.
             */
            $cell['visitors'][$event->user_id !== null ? 'u'.$event->user_id : 'v'.$event->visitor] = true;

            $cells[$key] = $cell;
        }

        if ($event->user_id === null) {
            return;
        }

        $person = $people[$event->user_id] ?? $this->person();

        $person['views'] += $view ? 1 : 0;
        $person['actions'] += $view ? 0 : 1;
        $person['areas'] |= ActivityArea::forRoute($event->route)?->value ?? 0;
        $person['features'] |= $this->featureFor($event);
        /*
         * Kept as the STORED string rather than a Carbon, because the truth
         * tables below contribute plain strings out of `min()`/`max()` and a
         * mixed comparison between a Carbon and a string is decided by
         * __toString — a coincidence, not a guarantee. Both sides are UTC
         * `Y-m-d H:i:s`, where lexical order is chronological order.
         */
        $at = $event->occurred_at->format('Y-m-d H:i:s');

        $person['first_seen_at'] = min($person['first_seen_at'] ?? $at, $at);
        $person['last_seen_at'] = max($person['last_seen_at'] ?? $at, $at);

        if ($view) {
            $person['buckets'][$bucket] = ($person['buckets'][$bucket] ?? 0) + 1;
        }

        $people[$event->user_id] = $person;
    }

    /**
     * The feature bits ONE event can prove — the ones with no truth table
     * anywhere, which is the whole reason the clickstream exists. Everything
     * else is joined in by {@see mergeTruth()}.
     */
    private function featureFor(ActivityEvent $event): int
    {
        $bits = 0;

        if ($event->kind === ActivityKind::PageView) {
            if ($event->facet === self::TALK_FACET || in_array($event->route, self::TALK_ROUTES, true)) {
                $bits |= ActivityFeature::ReadTalk->value;
            }

            if ($event->route === self::LOBBY_ROUTE) {
                $bits |= ActivityFeature::Lobby->value;
            }

            if (in_array($event->route, self::STATS_ROUTES, true)) {
                $bits |= ActivityFeature::Stats->value;
            }

            // True only, never `!== null`: "not reported" is not a browser and
            // it is certainly not an installed app.
            if ($event->standalone === true) {
                $bits |= ActivityFeature::Installed->value;
            }
        }

        return $bits | match ($event->kind) {
            ActivityKind::Searched => ActivityFeature::Searched->value,
            ActivityKind::StatAsked, ActivityKind::HelpAsked => ActivityFeature::Asked->value,
            default => 0,
        };
    }

    /**
     * The five bits a truth table owns, per person, for this league day.
     *
     * Each one is read from the table that already holds the fact rather than
     * from a clickstream row nobody emits — a second row for a pick would be
     * a second counter that can disagree with `picks`, and a stream entry can
     * be trimmed under load where a truth row cannot.
     *
     * The timestamps come along because they may be the person's ONLY moment
     * that day: `first_seen_at` and `last_seen_at` are not null, and a member
     * who picked from a deep link has bounds that live in `picks` and nowhere
     * else.
     *
     * @param  array<int, array<string, mixed>>  $people
     */
    private function mergeTruth(array &$people, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $sources = [
            // A CHANGED pick is playing too, so this reads updated_at — the
            // one truth column here that is not a creation stamp.
            ['picks', 'user_id', 'updated_at', ActivityFeature::Picked],
            ['conversation_posts', 'user_id', 'created_at', ActivityFeature::Talked],
            ['team_follows', 'user_id', 'created_at', ActivityFeature::Followed],
            ['group_members', 'user_id', 'created_at', ActivityFeature::Joined],
            // The INVITER: sending an invite is the adoption being measured;
            // receiving one is somebody else's.
            ['group_invites', 'inviter_id', 'created_at', ActivityFeature::Invited],
        ];

        foreach ($sources as [$table, $column, $stamp, $feature]) {
            $rows = DB::table($table)
                ->selectRaw("{$column} as person, min({$stamp}) as first_at, max({$stamp}) as last_at")
                // Half-open, so a row at midnight belongs to exactly one day.
                ->where($stamp, '>=', $start->utc())
                ->where($stamp, '<', $end->utc())
                ->whereNotNull($column)
                ->groupBy($column)
                ->get();

            foreach ($rows as $row) {
                $person = $people[$row->person] ?? $this->person();

                $person['features'] |= $feature->value;
                $person['first_seen_at'] = min($person['first_seen_at'] ?? $row->first_at, (string) $row->first_at);
                $person['last_seen_at'] = max($person['last_seen_at'] ?? $row->last_at, (string) $row->last_at);

                $people[$row->person] = $person;
            }
        }
    }

    /** @return array<string, mixed> */
    private function person(): array
    {
        return [
            'views' => 0,
            'actions' => 0,
            'areas' => 0,
            'features' => 0,
            'first_seen_at' => null,
            'last_seen_at' => null,
            'buckets' => [],
        ];
    }

    // ----------------------------------------------------------- the write

    /** @param  array<string, array<string, mixed>>  $cells */
    private function writeCells(string $date, array $cells): int
    {
        if ($cells === []) {
            return 0;
        }

        $rows = array_map(fn (array $cell): array => [
            'day' => $date,
            'route' => $cell['route'],
            'facet' => $cell['facet'],
            'audience' => $cell['audience'],
            'viewport_bucket' => $cell['viewport_bucket'],
            'installed' => $cell['installed'],
            'views' => $cell['views'],
            'visitors' => count($cell['visitors']),
            'navigate_views' => $cell['navigate_views'],
        ], array_values($cells));

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            PageViewDaily::query()->upsert(
                $chunk,
                ['day', 'route', 'facet', 'audience', 'viewport_bucket', 'installed'],
                ['views', 'visitors', 'navigate_views'],
            );
        }

        return count($rows);
    }

    /** @param  array<int, array<string, mixed>>  $people */
    private function writePeople(string $date, array $people): int
    {
        if ($people === []) {
            return 0;
        }

        $rows = [];

        foreach ($people as $id => $person) {
            $rows[] = [
                'user_id' => $id,
                'day' => $date,
                'views' => $person['views'],
                'actions' => $person['actions'],
                'areas' => $person['areas'],
                'features' => $person['features'],
                'first_seen_at' => $person['first_seen_at'],
                'last_seen_at' => $person['last_seen_at'],
                'viewport_bucket' => $this->mode($person['buckets']),
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            UserDay::query()->upsert(
                $chunk,
                ['user_id', 'day'],
                ['views', 'actions', 'areas', 'features', 'first_seen_at', 'last_seen_at', 'viewport_bucket'],
            );
        }

        return count($rows);
    }

    /**
     * The width this person read most of the day on.
     *
     * Ties break toward a REPORTED bucket and then toward the narrower one:
     * the product is designed at 390px, so when a day is split evenly the
     * phone is the honest thing to draw the day as. `Unknown` wins only when
     * it genuinely led, which happens to somebody whose whole day was first
     * loads.
     *
     * @param  array<int, int>  $buckets
     */
    private function mode(array $buckets): int
    {
        if ($buckets === []) {
            return ViewportBucket::Unknown->value;
        }

        $best = ViewportBucket::Unknown->value;
        $bestCount = -1;

        foreach ($buckets as $bucket => $count) {
            $better = $count > $bestCount
                || ($count === $bestCount && $best === ViewportBucket::Unknown->value)
                || ($count === $bestCount && $bucket !== ViewportBucket::Unknown->value && $bucket < $best);

            if ($better) {
                $best = $bucket;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
