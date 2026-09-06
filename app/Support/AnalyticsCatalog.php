<?php

namespace App\Support;

use App\Enums\ActivityFeature;
use App\Enums\UxSignal;
use App\Enums\ViewportBucket;
use App\Http\Middleware\RecordPageView;
use App\Models\ActivityEvent;
use App\Models\PageViewDaily;
use App\Models\UserDay;
use App\Models\UxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The named questions of docs/plans/analytics.md's analysis table, one method
 * each — and the only place any of them is answered.
 *
 * ONE IMPLEMENTATION, TWO SURFACES, the rule {@see CoverageReport} set: the
 * Analytics dashboards and {@see TelemetrySnapshot} both call this, so the
 * panel a human reads and the payload the advisor reads cannot disagree about
 * how many people came back. Every method returns its NUMERATOR and its
 * DENOMINATOR beside the rate, because a rate whose sides cannot be seen is a
 * number nobody can check — and because the advisor is required to quote both
 * in its evidence.
 *
 * NULL IS "TOO FEW TO READ", AND IT IS NEVER ZERO. Below the floor a share is
 * null and the counts stay, so a reader can see there were four people rather
 * than being told nobody came back. This is the app-wide missing-data rule
 * applied to statistics: a rate over three people is not a small rate, it is
 * not a rate.
 *
 * EVERY WINDOWED NUMBER CARRIES `since`, the {@see AnalyticsWindow} half of
 * the same idea — a window that starts before the sensor did is not that
 * window's number, and a 90-day count off a fortnight-old sensor reads as a
 * collapse that never happened.
 *
 * Question 13 of the table — the onboarding funnel — deliberately has NO
 * method here. It is already answered exactly once, by
 * `TelemetrySnapshot::funnel()` over `ux_events`, and a second implementation
 * is the disagreement this class exists to prevent. {@see funnelWeek()} is the
 * one thing this class reads out of that table: the weekly registration count
 * a cohort is sized against.
 */
class AnalyticsCatalog
{
    /**
     * People below which a rate is not reported.
     *
     * Ten, and it is a floor on the DENOMINATOR rather than on the answer: at
     * nine people one person moves the number eleven points, so the honest
     * report is that there is no number yet.
     */
    public const MIN_PEOPLE = 10;

    /**
     * Entries below which a pick'em share is not reported — a slate is a much
     * smaller population than the app, and five entries is where "half of them
     * picked late" starts meaning anything.
     */
    public const MIN_ENTRIES = 5;

    /** How long a cohort must have existed before its activation can be read. */
    public const MATURITY_DAYS = 7;

    /** Views under five over the window makes a screen quiet. */
    public const QUIET_VIEWS = 5;

    /** Cohorts reported, and the retention grid's width. */
    public const COHORT_WEEKS = 8;

    /** Saturday pairs reported. */
    public const SATURDAY_PAIRS = 6;

    /**
     * THE CLOSED VOCABULARY — the named questions this class will answer, and
     * the only thing a model is allowed to emit.
     *
     * `HelpTopics::TOPICS`, applied to analytics: ONE LIST FEEDS BOTH ENDS.
     * {@see vocabulary()} is what the classifier is shown and {@see keys()} is
     * the schema enum it must answer from, so the prompt and the resolver
     * cannot drift — a question added here is one the model can name and the
     * app can run, in the same commit, or neither.
     *
     * The summaries have to SEPARATE THE NEAR NEIGHBORS, because a
     * twelve-way enum misroutes between siblings long before it misroutes to
     * a stranger: traffic is how much was read, actives is how many people
     * were here, and routes is which screens they read; cohorts is who
     * signed up, retention is whether they came back at all, and
     * `saturday_retention` is whether they played two Saturdays running.
     *
     * @var array<string, array{title: string, summary: string}>
     */
    public const QUESTIONS = [
        'traffic' => [
            'title' => 'Screens read',
            'summary' => 'how much was read: page views and distinct visitors over the window, split guest, member and staff',
        ],
        'actives' => [
            'title' => 'Actives and stickiness',
            'summary' => 'how many PEOPLE were here: daily, weekly and monthly actives, and daily over monthly as stickiness',
        ],
        'adoption' => [
            'title' => 'Feature adoption',
            'summary' => 'what share of the people who were here this week picked, talked, followed, searched, opened the Lobby or read from an installed app',
        ],
        'cohorts' => [
            'title' => 'Signups by week',
            'summary' => 'who signed up, week by week, and what share of each week activated — registrations and onboarding, never whether they came back',
        ],
        'lifecycle' => [
            'title' => 'The lifecycle funnel',
            'summary' => 'the stages from registered to verified to onboarded to following a team to joining a group to making a pick, and where people stop',
        ],
        'retention' => [
            'title' => 'Cohort retention',
            'summary' => 'whether a signup week came BACK in later weeks — the grid of week-N return rates',
        ],
        'saturday_retention' => [
            'title' => 'Saturday-to-Saturday retention',
            'summary' => "whether the people who played one Saturday's pick'em played the next one",
        ],
        'routes' => [
            'title' => 'Screens by attention',
            'summary' => 'WHICH screens get opened: the most-read routes, and the quiet ones nobody opens',
        ],
        'devices' => [
            'title' => 'Devices and installs',
            'summary' => 'what people read on: screen-width buckets, and the share reading from an installed app rather than a browser tab',
        ],
        'time_of_week' => [
            'title' => 'When people are here',
            'summary' => 'WHEN in the week the app is read — the weekday and hour heat, not how much',
        ],
        'pickem_health' => [
            'title' => "This Saturday's pick'em",
            'summary' => 'the Saturday being played: slates, entries against members, late picks and whether the reminder moved anybody',
        ],
    ];

    public function __construct(private LiveState $live) {}

    /**
     * The keys a classifier may answer with.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::QUESTIONS);
    }

    /** One line per question, for the prompt — the same list the schema enumerates. */
    public static function vocabulary(): string
    {
        return implode("\n", array_map(
            fn (string $key, array $question): string => "- `{$key}` — {$question['summary']}",
            array_keys(self::QUESTIONS),
            array_values(self::QUESTIONS),
        ));
    }

    /**
     * Run ONE named question, or nothing.
     *
     * The other half of the rule the AI layer is built on: a model names the
     * key and the APPLICATION runs the query. An unknown key is null rather
     * than a nearest match — a question we do not answer must read as one we
     * do not answer, not as a different question's numbers under the wrong
     * heading.
     *
     * SOME QUESTIONS IGNORE THE RANGE, and they say so themselves by not
     * returning a `window_days`: `actives` is a fixed 1/7/28 shape, `cohorts`
     * and `retention` are counted in cohort weeks, `saturday_retention`
     * counts Saturdays and `pickem_health` is one Saturday. A caller reads
     * that off the payload rather than off a second list of which ones are
     * windowed — a list like that drifts, and its drift is a range label over
     * numbers that do not honor it.
     *
     * @return array{key: string, title: string, data: array<string, mixed>}|null
     */
    public function answer(string $key, ?AnalyticsWindow $window = null): ?array
    {
        $question = self::QUESTIONS[$key] ?? null;

        if ($question === null) {
            return null;
        }

        return [
            'key' => $key,
            'title' => $question['title'],
            'data' => match ($key) {
                'traffic' => $this->traffic($window),
                'actives' => $this->actives(),
                'adoption' => $this->adoption($window),
                'cohorts' => $this->cohorts(),
                'lifecycle' => $this->lifecycle($window),
                'retention' => $this->retention(),
                'saturday_retention' => $this->saturdayRetention(),
                'routes' => $this->routes($window),
                'devices' => $this->devices($window),
                'time_of_week' => $this->timeOfWeek($window),
                'pickem_health' => $this->pickemHealth(),
            },
        ];
    }

    // ------------------------------------------------------- 11. traffic

    /**
     * Guest versus member traffic (question 11).
     *
     * `visitors` is recomputed from `activity_events` rather than summed out
     * of `page_views_daily`, because the daily table's own docblock says it
     * cannot be: `visitors` is distinct INSIDE a cell, so adding it across
     * viewports and days counts one person once per cell they appear in. That
     * is the other reason the raw table keeps thirty days, and it caps this
     * question's window at what the raw table still holds.
     *
     * @return array<string, mixed>
     */
    public function traffic(?AnalyticsWindow $window = null): array
    {
        $window ??= AnalyticsWindow::of(7);

        $views = $this->keyed(
            PageViewDaily::query()
                ->whereBetween('day', [$window->fromDate(), $window->toDate()])
                ->groupBy('audience')
                ->selectRaw('audience as k, sum(views) as v'),
        );

        $visitors = $this->keyed(
            ActivityEvent::query()
                ->whereBetween('day', [$window->fromDate(), $window->toDate()])
                ->groupBy('audience')
                // One person is one visitor whichever column identifies them,
                // and the prefixes stop user 12 and visitor hash "12" reading
                // as the same reader.
                ->selectRaw('audience as k, count(distinct coalesce(concat("u", user_id), concat("v", visitor))) as v'),
        );

        return [
            'window_days' => $window->days,
            'views' => [
                'guest' => $views[ActivityEvent::GUEST] ?? 0,
                'member' => $views[ActivityEvent::MEMBER] ?? 0,
                // Kept separate rather than dropped: at pilot scale the
                // founder's own browsing is most of the traffic, and a number
                // that quietly includes it is the one that misleads.
                'staff' => $views[ActivityEvent::STAFF] ?? 0,
            ],
            'visitors' => [
                'guest' => $visitors[ActivityEvent::GUEST] ?? 0,
                'member' => $visitors[ActivityEvent::MEMBER] ?? 0,
            ],
            'since' => $window->sinceDate(),
        ];
    }

    // ------------------------------------------------------- 3. actives

    /**
     * Daily, weekly and monthly actives, and how sticky they are (question 3).
     *
     * Counts, so there is no floor — but the stickiness RATE has one.
     * Stickiness divides the mean daily actives by the monthly, and it is
     * divided by the days actually COVERED rather than by 28: a sensor that
     * has been counting for three days would otherwise read as a ninety
     * percent collapse in daily use.
     *
     * @return array<string, mixed>
     */
    public function actives(): array
    {
        $window = AnalyticsWindow::of(28);
        $today = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();
        $weekFrom = Cadence::currentSaturday()->subDays(4);

        $mau = $this->distinctPeople($window->fromDate(), $window->toDate());
        $covered = $window->coveredDays();

        /*
         * The sum of each day's distinct people. `user_days` is unique on
         * (user_id, day), so one row IS one person on one day and counting
         * rows needs no distinct — the uniqueness in the schema is what makes
         * this the mean rather than an overcount.
         */
        $dailySum = UserDay::query()
            ->whereBetween('day', [$window->fromDate(), $window->toDate()])
            ->count();

        return [
            'dau' => $this->distinctPeople($today->toDateString(), $today->toDateString()),
            // Tuesday through Monday, the week the whole product turns over
            // on — never a rolling seven days, or two adjacent numbers hold
            // different amounts of Saturday.
            'wau' => $this->distinctPeople($weekFrom->toDateString(), $today->toDateString()),
            'mau' => $mau,
            'stickiness_28d' => $mau >= self::MIN_PEOPLE && $covered > 0
                ? round($dailySum / $covered / $mau, 3)
                : null,
            'covered_days' => $covered,
            'since' => $window->sinceDate(),
        ];
    }

    // ------------------------------------------------------- 6. adoption

    /**
     * Feature adoption: the share of weekly actives who did each thing
     * (question 6).
     *
     * The denominator is weekly ACTIVES, not registered users — "do the people
     * who are here use this" is a different question from "do the people who
     * signed up in March", and only the first one is actionable this week.
     *
     * @return array<string, mixed>
     */
    public function adoption(?AnalyticsWindow $window = null): array
    {
        $window ??= AnalyticsWindow::of(7);
        $wau = $this->distinctPeople($window->fromDate(), $window->toDate());

        $features = [];

        foreach (ActivityFeature::cases() as $feature) {
            $users = (int) UserDay::query()
                ->whereBetween('day', [$window->fromDate(), $window->toDate()])
                ->whereRaw('(features & ?) > 0', [$feature->value])
                ->distinct()
                ->count('user_id');

            $features[$this->snake($feature->name)] = [
                'users' => $users,
                'share' => $wau >= self::MIN_PEOPLE ? round($users / $wau, 3) : null,
            ];
        }

        return [
            'wau' => $wau,
            'window_days' => $window->days,
            'since' => $window->sinceDate(),
            'features' => $features,
        ];
    }

    // ------------------------------------- 1 & 2. cohorts and activation

    /**
     * Acquisition by cohort week, and activation inside it (questions 1 and 2).
     *
     * A cohort is a registration week, Tuesday to Monday, because that is the
     * week the product itself turns over on.
     *
     * TWO DENOMINATORS, AND THEY ARE BOTH REPORTED. `registered` is the
     * `onboarding_registered` signal summed over the week — the funnel's own
     * count, and zero for any week before that signal shipped. `cohort` is the
     * `users` rows created in the week, which is the only population whose
     * verification, onboarding and first entry can actually be followed, and
     * so the only honest denominator for `activated_7d`. Reporting one without
     * the other would hand the advisor a rate it cannot check.
     *
     * `activated_7d` is null two ways over: below the floor, and before the
     * cohort is {@see MATURITY_DAYS} old. The second is the trap — a cohort
     * registered on Thursday has not had its seven days yet, and dividing
     * anyway prints a collapse every single week.
     *
     * @return list<array<string, mixed>>
     */
    public function cohorts(): array
    {
        $rows = [];
        $now = CarbonImmutable::now(config('cfb.timezone'));
        $weeks = $this->cohortWeeks();
        $people = $this->cohortUsers();
        $registered = $this->registrationsByWeek($weeks);

        foreach ($weeks as $start) {
            $users = $people[$start->toDateString()] ?? collect();
            $size = $users->count();
            $matured = $start->addDays(self::MATURITY_DAYS)->lte($now);

            $activated = $users->filter(fn (object $user): bool => $user->first_entry_at !== null
                && CarbonImmutable::parse($user->first_entry_at)
                    ->lte(CarbonImmutable::parse($user->created_at)->addDays(self::MATURITY_DAYS)))
                ->count();

            $rows[] = [
                'week' => $start->toDateString(),
                'registered' => $registered[$start->toDateString()] ?? 0,
                'cohort' => $size,
                'verified' => $users->whereNotNull('email_verified_at')->count(),
                'onboarded' => $users->whereNotNull('onboarded_at')->count(),
                'reached_picks' => $users->whereNotNull('picks_first_seen_at')->count(),
                'entered' => $users->whereNotNull('first_entry_at')->count(),
                'installed' => $users->whereNotNull('standalone_seen_at')->count(),
                'activated_7d' => $matured && $size >= self::MIN_PEOPLE
                    ? round($activated / $size, 3)
                    : null,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------- the lifecycle funnel

    /**
     * Registered → verified → onboarded → reached Picks → entered → installed,
     * over a window.
     *
     * THE FIRST BAR COMES FROM `ux_events`, NOT FROM `users`, and that is the
     * whole correctness of this funnel. Unverified accounts are pruned at
     * fourteen days, so counting `users` rows created in a window silently
     * loses every person who registered and never came back — which is exactly
     * the population a lifecycle funnel exists to measure. Counting the rows
     * would make the drop-off disappear and the funnel report its best number
     * on its worst week.
     *
     * Every later stage IS a `users` count, because each one is a durable
     * stamp on a surviving account. The steps therefore do not share a
     * denominator, and the widget prints counts rather than percentages
     * between them.
     *
     * @return array{registered: int, verified: int, onboarded: int, reached_picks: int, entered: int, installed: int, since: ?string, window_days: int}
     */
    public function lifecycle(?AnalyticsWindow $window = null): array
    {
        $window ??= AnalyticsWindow::of(AnalyticsWindow::DEFAULT_DAYS);

        $from = $window->from->utc();
        $to = $window->to->addDay()->utc();

        $users = DB::table('users')
            ->leftJoinSub(
                DB::table('slate_entries')->groupBy('user_id')->selectRaw('user_id, min(created_at) as first_entry_at'),
                'entries',
                'entries.user_id',
                '=',
                'users.id',
            )
            ->where('users.created_at', '>=', $from)
            ->where('users.created_at', '<', $to)
            ->selectRaw('
                sum(users.email_verified_at is not null) as verified,
                sum(users.onboarded_at is not null) as onboarded,
                sum(users.picks_first_seen_at is not null) as reached_picks,
                sum(entries.first_entry_at is not null) as entered,
                sum(users.standalone_seen_at is not null) as installed
            ')
            ->first();

        return [
            'registered' => (int) UxEvent::query()
                ->where('signal', UxSignal::OnboardingRegistered->value)
                ->whereBetween('day', [$window->fromDate(), $window->toDate()])
                ->sum('count'),
            'verified' => (int) ($users->verified ?? 0),
            'onboarded' => (int) ($users->onboarded ?? 0),
            'reached_picks' => (int) ($users->reached_picks ?? 0),
            'entered' => (int) ($users->entered ?? 0),
            'installed' => (int) ($users->installed ?? 0),
            'window_days' => $window->days,
            'since' => $window->sinceDate(),
        ];
    }

    // ------------------------------------------------ actives by cohort age

    /**
     * Weekly actives split by how long the person has been here — new, one to
     * four weeks, older.
     *
     * The question underneath is the one a pilot cannot answer any other way:
     * is the app holding people, or is every good week a different set of
     * strangers? A flat total of actives looks identical in both cases.
     *
     * @return list<array{week: string, new: int, recent: int, older: int}>
     */
    public function activesByCohortAge(?AnalyticsWindow $window = null): array
    {
        $window ??= AnalyticsWindow::of(AnalyticsWindow::DEFAULT_DAYS);

        if ($window->since === null) {
            return [];
        }

        $joined = DB::table('users')->pluck('created_at', 'id');

        $rows = UserDay::query()
            ->whereBetween('day', [$window->sinceDate(), $window->toDate()])
            ->distinct()
            ->get(['user_id', 'day']);

        $weeks = [];

        foreach ($rows as $row) {
            $day = CarbonImmutable::parse($row->day, config('cfb.timezone'))->startOfDay();
            // Tuesday-start weeks, the only week this product has.
            $offset = ($day->dayOfWeek - Cadence::TURNOVER_DOW + 7) % 7;
            $week = $day->subDays($offset)->toDateString();

            $created = $joined[$row->user_id] ?? null;

            $age = $created === null
                ? 'older'
                : $this->cohortAge(CarbonImmutable::parse($created, 'UTC')->setTimezone(config('cfb.timezone')), $day);

            $weeks[$week] ??= ['week' => $week, 'new' => 0, 'recent' => 0, 'older' => 0];
            $weeks[$week][$age]++;
        }

        ksort($weeks);

        return array_values($weeks);
    }

    /** Under a week is new, under five is recent, past that is older. */
    private function cohortAge(CarbonImmutable $created, CarbonImmutable $day): string
    {
        $weeks = $created->diffInWeeks($day);

        return match (true) {
            $weeks < 1 => 'new',
            $weeks < 5 => 'recent',
            default => 'older',
        };
    }

    // ------------------------------------------------------- 4. retention

    /**
     * Weekly retention: of the people who registered in week N, how many were
     * still here in week N+k (question 4).
     *
     * A cell is null under the floor rather than 0. The difference matters
     * more here than anywhere else in this class — a retention grid full of
     * honest-looking zeros is the single most persuasive wrong chart an early
     * product can draw itself.
     *
     * @return list<array<string, mixed>>
     */
    public function retention(): array
    {
        $rows = [];
        $today = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();
        $weeks = $this->cohortWeeks();
        $people = $this->cohortUsers();

        /*
         * Every cohort's presence, in ONE query, bucketed in PHP — the
         * standing "one query per CONCERN, never one per row" rule. Eight
         * cohorts by eight weeks is sixty-four cells, and asking the database
         * once per cell is how a snapshot becomes a minute long.
         */
        $ids = $people->flatten(1)->pluck('id')->all();

        $active = $ids === [] ? collect() : UserDay::query()
            ->whereIn('user_id', $ids)
            ->where('day', '>=', $weeks[0]->toDateString())
            ->distinct()
            ->get(['user_id', 'day'])
            ->groupBy(fn ($row): string => (string) $row->user_id)
            ->map(fn ($rows) => $rows->map(fn ($row): string => CarbonImmutable::parse($row->day)->toDateString())->all());

        foreach ($weeks as $start) {
            $users = $people[$start->toDateString()] ?? collect();
            $size = $users->count();
            $cells = [];

            for ($k = 0; $k < self::COHORT_WEEKS; $k++) {
                $from = $start->addWeeks($k);

                if ($from->gt($today)) {
                    break;
                }

                $to = $from->addDays(6)->toDateString();
                $fromDate = $from->toDateString();

                $here = $users->filter(function (object $user) use ($active, $fromDate, $to): bool {
                    foreach ($active[(string) $user->id] ?? [] as $day) {
                        if ($day >= $fromDate && $day <= $to) {
                            return true;
                        }
                    }

                    return false;
                })->count();

                $cells[] = $size >= self::MIN_PEOPLE ? round($here / $size, 3) : null;
            }

            $rows[] = ['cohort' => $start->toDateString(), 'size' => $size, 'weeks' => $cells];
        }

        return $rows;
    }

    // ---------------------------------------------- 5. Saturday retention

    /**
     * Saturday to Saturday (question 5) — the one retention number this
     * product is actually about.
     *
     * Week-over-week retention on a pick'em app is really "did they come back
     * for the next slate", and comparing a Saturday to a Wednesday says
     * nothing at all. Six pairs, so a season's shape is visible without the
     * payload growing a table.
     *
     * @return list<array<string, mixed>>
     */
    public function saturdayRetention(): array
    {
        $current = Cadence::currentSaturday();

        $saturdays = collect(range(self::SATURDAY_PAIRS, 0))
            ->map(fn (int $back): string => $current->subWeeks($back)->toDateString());

        // One query for all seven Saturdays; the pairing is set arithmetic,
        // which does not need a round trip each.
        $present = UserDay::query()
            ->whereIn('day', $saturdays->all())
            ->distinct()
            ->get(['user_id', 'day'])
            ->groupBy(fn ($row): string => CarbonImmutable::parse($row->day)->toDateString())
            ->map(fn ($rows): array => $rows->pluck('user_id')->all());

        $rows = [];

        foreach ($saturdays->slice(0, self::SATURDAY_PAIRS) as $i => $from) {
            $to = $saturdays[$i + 1];
            $before = $present[$from] ?? [];
            $after = $present[$to] ?? [];
            $retained = count(array_intersect($before, $after));

            $rows[] = [
                'from' => $from,
                'to' => $to,
                'active' => count($before),
                'retained' => $retained,
                'share' => count($before) >= self::MIN_PEOPLE
                    ? round($retained / count($before), 3)
                    : null,
            ];
        }

        return $rows;
    }

    // --------------------------------------------------------- 7. routes

    /**
     * Route popularity, and the screens nobody opens (question 7).
     *
     * Members only and staff excluded, because "does anybody open Rankings" is
     * a question about readers, and at pilot scale the staff row would answer
     * it with the founder's own browsing.
     *
     * `quiet` IS NULL UNTIL THE WINDOW IS COVERED, and that is the whole
     * safety of this section. A screen looks dead for exactly the same reason
     * a new funnel signal reads zero — the sensor was not counting yet — and a
     * "nobody opens this, delete it" finding filed off a three-day-old rollup
     * is the `funnel_since` bug with a bigger blast radius.
     *
     * A quiet screen is found by walking the ROUTE TABLE rather than the
     * rollup, because a screen with no views has no row: absence is the datum,
     * and it cannot be read out of a table that only holds what happened.
     *
     * @return array<string, mixed>
     */
    public function routes(?AnalyticsWindow $window = null): array
    {
        $window ??= AnalyticsWindow::of(28);

        $views = $this->keyed(
            PageViewDaily::query()
                ->whereBetween('day', [$window->fromDate(), $window->toDate()])
                ->where('audience', ActivityEvent::MEMBER)
                ->groupBy('route')
                ->selectRaw('route as k, sum(views) as v'),
        );

        // Recomputed, never summed — see traffic() and the daily table's own
        // note on why `visitors` cannot be added up.
        $visitors = $this->keyed(
            ActivityEvent::query()
                ->whereBetween('day', [$window->fromDate(), $window->toDate()])
                ->where('audience', ActivityEvent::MEMBER)
                ->groupBy('route')
                ->selectRaw('route as k, count(distinct user_id) as v'),
        );

        $top = collect($views)->sortDesc()->take(20)
            ->map(fn (int $count, string $route): array => [
                'route' => $route,
                'views' => $count,
                'visitors' => $visitors[$route] ?? 0,
            ])
            ->values()
            ->all();

        return [
            'window_days' => $window->days,
            'since' => $window->sinceDate(),
            'top' => $top,
            'quiet' => $window->covered
                ? collect($this->screenRoutes())
                    ->filter(fn (string $route): bool => ($views[$route] ?? 0) < self::QUIET_VIEWS)
                    ->map(fn (string $route): array => ['route' => $route, 'views' => $views[$route] ?? 0])
                    ->sortBy('views')
                    ->values()
                    ->all()
                : null,
        ];
    }

    // -------------------------------------------------------- 8. devices

    /**
     * Device mix (question 8) — informational, and the reason it is in here at
     * all is that the whole product is designed at 390px.
     *
     * "Not reported" is its own bucket and never folded into Phone. The first
     * HTML response of a session is sent before the client cookie exists, so a
     * real share of views genuinely have no width, and bucketing those as the
     * most likely answer would invent the exact number the bucket measures.
     *
     * @return array<string, mixed>
     */
    public function devices(?AnalyticsWindow $window = null): array
    {
        $window ??= AnalyticsWindow::of(28);

        $rows = $this->keyed(
            PageViewDaily::query()
                ->whereBetween('day', [$window->fromDate(), $window->toDate()])
                ->groupBy('viewport_bucket')
                ->selectRaw('viewport_bucket as k, sum(views) as v'),
        );

        $byBucket = [];

        foreach (ViewportBucket::cases() as $bucket) {
            $byBucket[$this->snake($bucket->name)] = $rows[$bucket->value] ?? 0;
        }

        $installed = $this->keyed(
            PageViewDaily::query()
                ->whereBetween('day', [$window->fromDate(), $window->toDate()])
                ->groupBy('installed')
                ->selectRaw('installed as k, sum(views) as v'),
        );

        // Unknown is excluded from BOTH sides: "we were not told" is not a
        // browser, so it cannot sit in the denominator of "how many read this
        // installed" either.
        $standalone = $installed[PageViewDaily::STANDALONE] ?? 0;
        $known = ($installed[PageViewDaily::BROWSER] ?? 0) + $standalone;

        return [
            'window_days' => $window->days,
            'since' => $window->sinceDate(),
            'by_bucket' => $byBucket,
            'installed_views' => $standalone,
            'reported_views' => $known,
            'installed_share' => $known > 0 ? round($standalone / $known, 3) : null,
        ];
    }

    // ------------------------------------------------------ 9. time of week

    /**
     * Weekday by league hour (question 9) — 168 cells, which is why this is a
     * dashboard heat map and never enters the snapshot. A model handed 168
     * numbers finds a pattern in them whether or not one is there.
     *
     * The hour is read off the stored `hour` column rather than asked for in
     * SQL: `CONVERT_TZ` does not know about DST the way the drain did when it
     * wrote the value.
     *
     * @return list<array{weekday: int, hour: int, views: int}>
     */
    public function timeOfWeek(?AnalyticsWindow $window = null): array
    {
        $window ??= AnalyticsWindow::of(28);

        return ActivityEvent::query()
            ->whereBetween('day', [$window->fromDate(), $window->toDate()])
            // The GROUPED expression must be the SELECTED expression, to the
            // character: `only_full_group_by` rejects `dayofweek(day) - 1`
            // grouped by `dayofweek(day)` as a 1055, not as a wrong answer.
            ->groupByRaw('dayofweek(day) - 1, hour')
            ->orderByRaw('dayofweek(day) - 1')
            ->orderBy('hour')
            ->get([DB::raw('dayofweek(day) - 1 as weekday'), 'hour', DB::raw('count(*) as views')])
            ->map(fn ($row): array => [
                'weekday' => (int) $row->weekday,
                'hour' => (int) $row->hour,
                'views' => (int) $row->views,
            ])
            ->all();
    }

    // -------------------------------------------------- 10. pick'em health

    /**
     * Pick'em health per slate (question 10), for this Saturday and last.
     *
     * The rows come from {@see LiveState} with `names: false` — one
     * implementation of what a slate's state is, and the machine skin drops
     * the one user-written field on it. `members` is added here because it is
     * the denominator the two rates below divide by: who could have entered,
     * counted at first kickoff rather than now, so a group that grew on Sunday
     * does not retroactively make Saturday look worse.
     *
     * `late_share` and `reminder_lift` are NULL UNTIL PHASE 7 supplies them on
     * LiveState's own rows, and they are read through `??` rather than
     * computed here so that when it does, this needs no edit. Null is the
     * honest answer for a number nothing measures yet; a zero would be a claim
     * that nobody picks late.
     *
     * @return list<array<string, mixed>>
     */
    public function pickemHealth(): array
    {
        $current = Cadence::currentSaturday();
        $rows = [];

        foreach ([$current->subWeek(), $current] as $saturday) {
            $state = $this->live->build($saturday, names: false);

            foreach ($state['contests'] as $contest) {
                $kickoff = $contest['first_kickoff'] === null
                    ? null
                    : CarbonImmutable::parse($contest['first_kickoff']);

                // The floor is applied HERE rather than wherever the rates
                // are computed, so the phase that adds them cannot ship one
                // unfloored: four entries is not a low late-pick share, it is
                // one person changing their mind.
                $readable = $contest['entries'] >= self::MIN_ENTRIES;

                $rows[] = [
                    ...$contest,
                    'saturday' => $saturday->toDateString(),
                    'members' => $this->membersAt($contest['group_id'], $kickoff),
                    'late_share' => $readable ? $contest['late_share'] ?? null : null,
                    'reminder_lift' => $readable ? $contest['reminder_lift'] ?? null : null,
                ];
            }
        }

        return $rows;
    }

    // ---------------------------------------------------- 12. error rates

    /**
     * A route's traffic in the error window (question 12), so a browser error
     * count can be read as a rate.
     *
     * NULL WITH NO ROWS, never 0. A route the raw table holds nothing for is a
     * route we cannot size the error against — possibly because it was pruned,
     * possibly because the sensor missed it — and "zero views but eleven
     * errors" is an impossible pair that reads as a catastrophe.
     */
    public function routeViews(?string $route, int $hours): ?int
    {
        if ($route === null) {
            return null;
        }

        $views = ActivityEvent::query()
            ->where('route', $route)
            ->where('occurred_at', '>=', now()->subHours($hours))
            ->count();

        return $views > 0 ? $views : null;
    }

    /**
     * The route name a path belongs to, or null when nothing matches.
     *
     * Null rather than the path itself: a path carries ids, and an invite code
     * or a signed link riding into the payload is the one thing the whole
     * sensor design refuses. The router is asked rather than a regex, so a
     * route that moves stays resolvable.
     */
    public function routeFor(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            return Route::getRoutes()
                ->match(Request::create($path, 'GET'))
                ->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------ 14. pick timing

    /**
     * When picks are actually made inside a slate (question 14) — dashboard
     * only, and the raw material phase 7's late-pick share divides.
     *
     * Both stamps: `created_at` is when somebody first committed, `updated_at`
     * is when they last changed their mind, and the gap between them is most
     * of what a slate's Saturday looks like.
     *
     * @return array<string, mixed>
     */
    public function pickTiming(int $slateId): array
    {
        $slate = DB::table('slates')->where('id', $slateId)->first();

        if ($slate === null) {
            return [
                'slate_id' => $slateId, 'picks' => 0, 'first_at' => null, 'last_at' => null,
                'published_at' => null, 'picks_reminded_at' => null, 'last_call' => null,
            ];
        }

        $row = DB::table('picks')
            ->join('slate_games', 'picks.slate_game_id', '=', 'slate_games.id')
            ->where('slate_games.slate_id', $slateId)
            ->selectRaw('count(*) as picks, min(picks.created_at) as first_at, max(picks.updated_at) as last_at')
            ->first();

        return [
            'slate_id' => $slateId,
            'picks' => (int) ($row->picks ?? 0),
            'first_at' => $row->first_at,
            'last_at' => $row->last_at,
            'published_at' => $slate->published_at,
            'picks_reminded_at' => $slate->picks_reminded_at,
            'last_call' => $slate->last_call_sent_at,
        ];
    }

    // --------------------------------------------------------------- parts

    /**
     * Registrations per cohort week, off the funnel's own table.
     *
     * Read rather than reimplemented — `ux_events` is the one counter for this
     * moment — and BACKTICKED, because `signal` and `count` are both reserved
     * words in MySQL 8 where an unquoted one is a 1064 rather than a wrong
     * answer.
     *
     * @param  list<CarbonImmutable>  $weeks
     * @return array<string, int>
     */
    private function registrationsByWeek(array $weeks): array
    {
        $days = UxEvent::query()
            ->where('signal', UxSignal::OnboardingRegistered->value)
            ->where('day', '>=', $weeks[0]->toDateString())
            ->selectRaw('`day`, sum(`count`) as total')
            ->groupBy('day')
            ->get();

        $out = [];

        foreach ($days as $row) {
            $day = CarbonImmutable::parse($row->day)->toDateString();

            foreach ($weeks as $start) {
                if ($day >= $start->toDateString() && $day < $start->addDays(7)->toDateString()) {
                    $key = $start->toDateString();
                    $out[$key] = ($out[$key] ?? 0) + (int) $row->total;
                    break;
                }
            }
        }

        return $out;
    }

    /** Distinct people with a `user_days` row inside the dates, inclusive. */
    private function distinctPeople(string $from, string $to): int
    {
        return (int) UserDay::query()
            ->whereBetween('day', [$from, $to])
            ->distinct()
            ->count('user_id');
    }

    /**
     * The cohort weeks reported, oldest first — Tuesday starts, because
     * `Cadence::TURNOVER_DOW` is what the product's own week turns over on.
     *
     * @return list<CarbonImmutable>
     */
    private function cohortWeeks(): array
    {
        $now = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();
        $offset = ($now->dayOfWeek - Cadence::TURNOVER_DOW + 7) % 7;
        $current = $now->subDays($offset);

        return collect(range(self::COHORT_WEEKS - 1, 0))
            ->map(fn (int $back): CarbonImmutable => $current->subWeeks($back))
            ->all();
    }

    /**
     * Every reported cohort's people, keyed by the week they registered in,
     * with the first slate entry each of them ever made.
     *
     * ONE QUERY for all eight weeks, and one subquery join for the entries —
     * never a lookup per person, and never a lookup per week. The bucketing is
     * done here rather than in SQL because the week starts on
     * `Cadence::TURNOVER_DOW` in the LEAGUE timezone, and MySQL's `WEEK()`
     * knows neither.
     *
     * @return Collection<string, Collection<int, object>>
     */
    private function cohortUsers(): Collection
    {
        $weeks = $this->cohortWeeks();
        $starts = collect($weeks)->map(fn (CarbonImmutable $day): string => $day->toDateString());

        $users = DB::table('users')
            ->leftJoinSub(
                DB::table('slate_entries')->groupBy('user_id')->selectRaw('user_id, min(created_at) as first_entry_at'),
                'entries',
                'entries.user_id',
                '=',
                'users.id',
            )
            ->where('users.created_at', '>=', $weeks[0]->utc())
            ->get([
                'users.id', 'users.created_at', 'users.email_verified_at', 'users.onboarded_at',
                'users.picks_first_seen_at', 'users.standalone_seen_at', 'entries.first_entry_at',
            ]);

        return $users->groupBy(function (object $user) use ($starts): string {
            $day = CarbonImmutable::parse($user->created_at, 'UTC')
                ->setTimezone(config('cfb.timezone'))
                ->toDateString();

            // The latest week start on or before the day they registered.
            // Half-open by construction, so nobody lands in two cohorts.
            return $starts->last(fn (string $start): bool => $start <= $day) ?? '';
        });
    }

    /**
     * How many people were in the group when the games started — the
     * denominator for anything about who did not pick.
     *
     * Counted AT FIRST KICKOFF, not now: somebody who joined on Sunday could
     * not have entered on Saturday, and including them turns growth into a
     * participation problem.
     *
     * NULL WITHOUT A KICKOFF, and never today's roster. A slate with no games
     * on it has no moment to count at, and falling back to "everybody in the
     * group right now" would answer a question nobody asked with a number that
     * looks exactly like the real one — the substitution this whole layer
     * refuses, in the denominator of the one rate that can earn `high`.
     */
    private function membersAt(?int $groupId, ?CarbonImmutable $kickoff): ?int
    {
        if ($groupId === null || $kickoff === null) {
            return null;
        }

        return (int) DB::table('group_members')
            ->where('group_id', $groupId)
            ->where('created_at', '<=', $kickoff->utc())
            ->count();
    }

    /**
     * Every named route the sensor would record as a screen.
     *
     * The predicate is {@see RecordPageView::isScreenRoute()} — the sensor's
     * own, called rather than restated, so "what counts as a screen" cannot
     * mean two things. A second copy of that list would drift the first time a
     * skip prefix moved, and the drift would surface as a screen reported dead
     * that nothing was ever counting.
     *
     * A route only qualifies if the SENSOR ACTUALLY RUNS ON IT — the
     * middleware groups are expanded and checked for {@see RecordPageView},
     * rather than assuming every named GET is a page. `/ops/telemetry` and the
     * local storage route are named GETs that live outside the `web` group
     * entirely, and reporting either as a screen nobody opens would be
     * perfectly true and completely useless.
     *
     * @return list<string>
     */
    private function screenRoutes(): array
    {
        $groups = collect(Route::getMiddlewareGroups())
            ->filter(fn (array $middleware): bool => in_array(RecordPageView::class, $middleware, true))
            ->keys();

        return collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn ($route, string $name): bool => in_array('GET', $route->methods(), true)
                && $groups->intersect($route->gatherMiddleware())->isNotEmpty()
                && RecordPageView::isScreenRoute($name))
            ->keys()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * A grouped aggregate as `key => int`.
     *
     * Written out rather than reached through `pluck(DB::raw(...))`, which
     * appends its own select to one that already has an aggregate in it and
     * returns the wrong column without erroring.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>  $query
     * @return array<array-key, int>
     */
    private function keyed($query): array
    {
        $out = [];

        foreach ($query->get() as $row) {
            $out[$row->k] = (int) $row->v;
        }

        return $out;
    }

    /** `ReadTalk` => `read_talk`, so an enum case names its own payload key. */
    private function snake(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
