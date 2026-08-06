<?php

namespace App\Services\Espn\Sync;

use App\Events\GameScoreChanged;
use App\Events\GameWentFinal;
use App\Jobs\FetchGameSummary;
use App\Models\Game;
use App\Models\Season;
use App\Models\Venue;
use App\Models\Week;
use App\Services\Espn\EspnClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Games, from the site scoreboard rather than the core event API.
 *
 * The core API is more normalised but requires roughly eight sub-requests per
 * game (status, both scores, both teams, venue, odds, predictor) — about 7,000
 * requests for one season. The site scoreboard returns many games denormalised
 * in a single call: a full 2025 FBS season is 958 games in 9 requests.
 *
 * ESPN's `groups=80` filter restricts this to FBS, which is what the pick'em
 * cares about; pass a different group to widen it.
 */
class SyncGames
{
    public function __construct(
        private EspnClient $espn,
        private SyncOdds $odds,
    ) {}

    /** ESPN's hard cap on scoreboard results, whatever `limit` says. */
    private const MAX_EVENTS = 1000;

    /** Days per request window. */
    private const WINDOW_DAYS = 30;

    /**
     * Windows overlap so a game on a boundary cannot fall between two requests.
     * Duplicates are free — everything upserts by ESPN event id.
     */
    private const WINDOW_OVERLAP_DAYS = 5;

    /**
     * Tier 4 — the whole season, in overlapping date windows. Nine requests
     * and ~950 games. Reserved for a backfill or a preseason rebuild;
     * everything below is a cheaper way to stay current.
     *
     * Two things force the windowing. First, one request per week silently
     * truncates: week 5 of 2025 returns 25 events against a much larger truth,
     * and raising `limit` does not change it. Only a date range is complete.
     *
     * Second, a season-wide range works but decodes to a 92 MB array peaking
     * near 138 MB, over PHP's default 128 MB limit — it failed as a bare exit
     * 255 with an empty log, and would fail the same way on a queue worker.
     *
     * Games are matched to their week by kickoff date afterwards, which is what
     * the weeks table's date-range index exists for.
     */
    public function season(int $year, int $group = 80): int
    {
        $changed = 0;

        foreach ($this->windows($year) as [$from, $to]) {
            $changed += $this->range($from, $to, $group);
        }

        return $changed;
    }

    /**
     * Tier 3 — one week, one request.
     *
     * The weekly cadence: last week's finals and this week's slate. Note this
     * uses the week's DATE RANGE, not ESPN's `week=` parameter, which silently
     * truncates (week 5 of 2025 returns 25 events against a much larger truth).
     */
    public function week(Week $week, int $group = 80): int
    {
        if ($week->start_date === null || $week->end_date === null) {
            return 0;
        }

        return $this->range(
            $week->start_date->format('Ymd'),
            $week->end_date->format('Ymd'),
            $group
        );
    }

    /**
     * Tier 2 — one day, one request.
     */
    public function day(?CarbonImmutable $date = null, int $group = 80): int
    {
        $date ??= CarbonImmutable::now(config('cfb.timezone'));

        return $this->range($date->format('Ymd'), null, $group);
    }

    /**
     * Tier 1 — refresh whatever is in progress right now. One request.
     *
     * There is deliberately no single-game sync. The obvious design — poll
     * `summary?event={id}` for the game a user is watching — is measurably
     * worse: that payload is 523 KB because it carries boxscore, drives,
     * scoring plays, news and win probability, while the entire day's
     * scoreboard is 440 KB for 25 games. Refreshing one game costs more than
     * refreshing all of them.
     *
     * So N concurrent viewers of N different live games cost exactly one ESPN
     * request between them. v3 cost one request per viewer per 15 seconds.
     */
    public function live(int $group = 80): int
    {
        if (! $this->hasLiveGames()) {
            return 0;
        }

        return $this->day(group: $group);
    }

    public function hasLiveGames(): bool
    {
        return Game::query()->inProgress()->exists();
    }

    /**
     * One scoreboard request over a date or date range.
     *
     * Returns the number of games whose data actually CHANGED. Unchanged games
     * are not written at all — on a scale-to-zero database, a no-op write is
     * not free, and this also means a caller can broadcast only real updates.
     */
    public function range(string $from, ?string $to = null, int $group = 80): int
    {
        $body = $this->espn->site('scoreboard', [
            'limit' => self::MAX_EVENTS,
            'dates' => $to === null ? $from : "{$from}-{$to}",
            'groups' => $group,
            // Deliberately uncached: these payloads are hundreds of kilobytes
            // to tens of megabytes, and live data must never be served stale.
        ], ttl: 0);

        if ($body === null || empty($body['events'])) {
            return 0;
        }

        if (count($body['events']) >= self::MAX_EVENTS) {
            Log::warning('Scoreboard hit the event cap; this window may be truncated', [
                'window' => $to === null ? $from : "{$from}-{$to}",
            ]);
        }

        $years = $this->yearsIn($body['events']);
        $seasons = Season::whereIn('year', $years)->get()->keyBy(fn (Season $s) => $s->year.':'.$s->type);
        $weeks = $this->weeksFor($seasons);

        $changed = 0;
        $seen = [];

        foreach ($body['events'] as $event) {
            $id = (int) ($event['id'] ?? 0);

            if ($id === 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            /*
             * Per-event, so one unstorable game cannot take out the rest of the
             * window. It already did once: an unannounced fixture carries a
             * NEGATIVE team id, which threw against an unsigned foreign key and
             * aborted the whole request — silently truncating the 2026 season at
             * the first conference championship and losing every bowl behind it.
             *
             * The same isolation the job fan-out buys between teams, bought here
             * between events in one payload.
             */
            try {
                if ($this->store($event, $seasons, $weeks)) {
                    $changed++;
                }
            } catch (Throwable $e) {
                Log::warning('Skipped an unstorable game', [
                    'game' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $changed;
    }

    /**
     * @return list<int>
     */
    private function yearsIn(array $events): array
    {
        $years = [];

        foreach ($events as $event) {
            if (isset($event['season']['year'])) {
                $years[(int) $event['season']['year']] = true;
            }
        }

        return array_keys($years) ?: [(int) date('Y')];
    }

    /**
     * Overlapping windows spanning a season, August through the CFP final.
     *
     * @return list<array{0:string,1:string}>
     */
    private function windows(int $year): array
    {
        $cursor = CarbonImmutable::create($year, 8, 1);
        $end = CarbonImmutable::create($year + 1, 3, 1);

        $windows = [];

        while ($cursor->lessThan($end)) {
            $stop = $cursor->addDays(self::WINDOW_DAYS)->min($end);

            $windows[] = [$cursor->format('Ymd'), $stop->format('Ymd')];

            // Stop once the window reaches the end. Without this the cursor
            // rewinds by the overlap and the next window clamps to `end` again,
            // looping forever — which fails as a bare exit 255 with no output
            // at all, since the fatal is memory exhaustion inside the loop.
            if ($stop->greaterThanOrEqualTo($end)) {
                break;
            }

            $cursor = $stop->subDays(self::WINDOW_OVERLAP_DAYS);
        }

        return $windows;
    }

    /**
     * @return array<int, Collection<int, Week>> keyed by season id
     */
    private function weeksFor($seasons): array
    {
        if ($seasons->isEmpty()) {
            return [];
        }

        return Week::whereIn('season_id', $seasons->pluck('id'))
            ->get()
            ->groupBy('season_id')
            ->all();
    }

    /**
     * Match a kickoff to its week by date range.
     *
     * v3 did this lookup with `->first()->id` and no null guard, so a game
     * whose kickoff fell in a calendar gap threw and killed the run.
     */
    private function resolveWeek(iterable $weeks, CarbonImmutable $kickoff): ?Week
    {
        foreach ($weeks as $week) {
            if ($week->start_date === null || $week->end_date === null) {
                continue;
            }

            if ($kickoff->betweenIncluded($week->start_date, $week->end_date)) {
                return $week;
            }
        }

        return null;
    }

    private function store(array $event, $seasons, array $weeks): bool
    {
        $competition = $event['competitions'][0] ?? null;

        if ($competition === null || ! isset($event['id'], $event['date'])) {
            return false;
        }

        $home = $this->competitor($competition, 'home');
        $away = $this->competitor($competition, 'away');

        if ($home === null || $away === null) {
            return false;
        }

        // Each event names its own season and type, so a range spanning the
        // regular season and the playoff files both correctly.
        $seasonYear = (int) ($event['season']['year'] ?? date('Y'));
        $seasonType = (int) ($event['season']['type'] ?? Season::REGULAR);

        $season = $seasons["{$seasonYear}:{$seasonType}"]
            ?? $seasons["{$seasonYear}:".Season::REGULAR]
            ?? null;

        if ($season === null) {
            return false;
        }

        $kickoff = CarbonImmutable::parse($event['date']);
        $week = $this->resolveWeek($weeks[$season->id] ?? [], $kickoff);
        $status = $event['status'] ?? [];
        $type = $status['type'] ?? [];

        $game = Game::firstOrNew(['id' => (int) $event['id']]);

        $game->fill([
            'season_id' => $season->id,
            'week_id' => $week?->id,
            'venue_id' => $this->venue($competition['venue'] ?? null),
            'kickoff_at' => $kickoff,
            /*
                 * Day of week in Eastern, computed here rather than derived in
                 * SQL. Contests may only slate Saturday games, and a CFB season
                 * straddles EDT and EST — so this must go through a named zone,
                 * once, at write time.
                 */
            'kickoff_day' => $kickoff->setTimezone(config('cfb.timezone'))->format('D'),
            'name' => $event['name'] ?? 'Unknown matchup',
            'short_name' => $event['shortName'] ?? null,
            // "Rose Bowl Presented by Prudential", "College Football Playoff
            // National Championship". Absent on regular-season games, which is
            // itself the signal that a game is one.
            'note' => $this->note($competition),
            'neutral_site' => (bool) ($competition['neutralSite'] ?? false),
            'conference_game' => (bool) ($competition['conferenceCompetition'] ?? false),
            'attendance' => $competition['attendance'] ?? null,
            'broadcasts' => $this->broadcasts($competition),

            'home_team_id' => $this->teamId($home),
            'home_score' => (int) ($home['score'] ?? 0),
            'home_rank' => $this->rank($home),
            'home_record' => $this->record($home),
            'home_line_scores' => $this->lineScores($home),

            'away_team_id' => $this->teamId($away),
            'away_score' => (int) ($away['score'] ?? 0),
            'away_rank' => $this->rank($away),
            'away_record' => $this->record($away),
            'away_line_scores' => $this->lineScores($away),

            'status' => $type['state'] ?? null,
            'status_detail' => $type['shortDetail'] ?? null,
            'period' => (int) ($status['period'] ?? 0),
            'clock' => $status['displayClock'] ?? null,
            'completed' => (bool) ($type['completed'] ?? false),
        ]);

        /*
         * Write only when something actually moved.
         *
         * The live tier re-reads the whole day every minute, and on a typical
         * Saturday most of those games have not changed since the last pass. A
         * no-op UPDATE is not free against a scale-to-zero database, and
         * skipping it also means the caller can broadcast on real changes
         * rather than on every sync tick.
         */
        $changed = ! $game->exists || $game->isDirty();

        /*
         * Did this pass just finish the game?
         *
         * Read BEFORE save, while `completed` is still dirty — afterwards the
         * original and current values match and the transition is invisible.
         */
        $justFinished = $game->isDirty('completed') && $game->completed;

        /*
         * Did the score or status move on an EXISTING row? Also read before
         * save, for the same reason. `$game->exists` keeps a season backfill
         * from firing 950 "score changed" events for rows being created.
         */
        $scoreMoved = $game->exists
            && ($game->isDirty('home_score') || $game->isDirty('away_score') || $game->isDirty('status'));

        if ($changed) {
            $game->save();
        }

        /*
         * The pick'em subscription points, dispatched AFTER save so a future
         * listener reads the new database state. No listeners exist yet;
         * the completing pass fires both (status goes dirty on the flip),
         * so listeners must treat them as idempotent signals, not deltas.
         */
        if ($scoreMoved) {
            GameScoreChanged::dispatch(
                $game->id,
                $game->home_score,
                $game->away_score,
                (string) $game->status,
            );
        }

        if ($justFinished) {
            GameWentFinal::dispatch($game->id);
        }

        /*
         * Box scores land minutes after the whistle, not the next morning.
         *
         * A nightly sweep meant an 11pm Saturday final had no box score until
         * 05:00 Sunday — the window in which people most want to look at it.
         * The live tier already detects the transition, so this costs one
         * queued job per game per season rather than a scan.
         *
         * Unique per game, so a game flapping between states cannot queue the
         * same fetch twice.
         */
        if ($justFinished) {
            // Forced: what this fetches is the FINAL truth, and a live fetch
            // that landed seconds ago must not make it a no-op. `live` queue —
            // a Saturday's finals must not wait behind a draining backfill.
            FetchGameSummary::dispatch($game->id, force: true)->onQueue('live');
        }

        /*
         * Odds ride along on the payload we already have, so this costs no
         * extra request. Run it even when the game row itself is unchanged —
         * a line can move while the score does not, and that movement is the
         * signal the Game Quality Score depends on.
         */
        $this->odds->fromCompetition(
            (int) $event['id'],
            $competition,
            gameStarted: ($type['state'] ?? 'pre') !== 'pre',
        );

        return $changed;
    }

    /**
     * A competitor's team id, or null when the slot is not filled yet.
     *
     * ESPN publishes an unassigned slot as a real competitor whose team id is
     * NEGATIVE — `-1` for home, `-2` for away — with the name "TBD". Every bowl
     * and playoff game is announced this way months ahead, and so is a
     * conference championship until its standings resolve.
     *
     * `games.home_team_id` is `mediumint unsigned` with a foreign key, so
     * writing -1 fails outright. It is nullable, though, and `x-team-link`
     * already renders a null team as "TBD" — so nulling the slot stores the
     * fixture with its date, venue and bowl name intact and the matchup blank,
     * which is exactly what the schedule is at that point.
     *
     * Same rule as the box-score pseudo-athletes: ESPN uses non-positive ids
     * for things that are not real entities. Never store one.
     */
    private function teamId(array $competitor): ?int
    {
        $id = (int) ($competitor['id'] ?? $competitor['team']['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function competitor(array $competition, string $side): ?array
    {
        foreach ($competition['competitors'] ?? [] as $competitor) {
            if (($competitor['homeAway'] ?? null) === $side) {
                return $competitor;
            }
        }

        return null;
    }

    /**
     * ESPN uses 99 as its unranked sentinel. Storing that verbatim — as v3 did —
     * means every ordering and "is this team ranked" check has to know the magic
     * number. Null is the honest representation.
     */
    private function rank(array $competitor): ?int
    {
        $rank = $competitor['curatedRank']['current'] ?? null;

        return ($rank === null || (int) $rank >= 99) ? null : (int) $rank;
    }

    private function record(array $competitor): ?string
    {
        foreach ($competitor['records'] ?? [] as $record) {
            if (($record['type'] ?? null) === 'total') {
                return $record['summary'] ?? null;
            }
        }

        return null;
    }

    /**
     * @return list<int>|null
     */
    private function lineScores(array $competitor): ?array
    {
        $scores = $competitor['linescores'] ?? null;

        if (empty($scores)) {
            return null;
        }

        return array_map(fn (array $line) => (int) ($line['value'] ?? 0), $scores);
    }

    /**
     * @return list<string>|null
     */
    /**
     * The event's own name, where it has one.
     *
     * Only postseason games carry this, and that is exactly what makes it
     * useful: it is both the bowl's proper name for display and the only way to
     * tell a playoff game from any other bowl. Verified live for 2025 — 41 of
     * 41 postseason events have it, and the 11 playoff games all begin
     * "College Football Playoff".
     */
    private function note(array $competition): ?string
    {
        $headline = data_get($competition, 'notes.0.headline');

        return is_string($headline) && $headline !== '' ? $headline : null;
    }

    private function broadcasts(array $competition): ?array
    {
        $names = [];

        foreach ($competition['broadcasts'] ?? [] as $broadcast) {
            foreach ($broadcast['names'] ?? [] as $name) {
                $names[] = $name;
            }
        }

        return $names === [] ? null : array_values(array_unique($names));
    }

    private function venue(?array $venue): ?int
    {
        if ($venue === null || ! isset($venue['id'])) {
            return null;
        }

        Venue::updateOrCreate(
            ['id' => (int) $venue['id']],
            [
                'name' => $venue['fullName'] ?? "Venue {$venue['id']}",
                'city' => $venue['address']['city'] ?? null,
                'state' => $venue['address']['state'] ?? null,
                'capacity' => $venue['capacity'] ?? null,
                'indoor' => (bool) ($venue['indoor'] ?? false),
            ]
        );

        return (int) $venue['id'];
    }
}
