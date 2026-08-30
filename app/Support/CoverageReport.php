<?php

namespace App\Support;

use App\Enums\SeasonPhase;
use App\Models\Article;
use App\Models\AthleteSeasonStat;
use App\Models\AthleteTeamSeason;
use App\Models\FeedRun;
use App\Models\Game;
use App\Models\GamePredictor;
use App\Models\Ranking;
use App\Models\Standing;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Services\CfbCalendar;
use App\Services\Stats\AggregateAthleteStats;

/**
 * Expected-versus-actual assertions over the data the sync is supposed to be
 * keeping. This is the layer that turns "the command reported success and
 * wrote nothing" — the 403 story, and the production team-stats gap — into a
 * red row instead of a mystery.
 *
 * Every check names its remedy, because a dashboard that says "broken"
 * without saying "run this" just moves the mystery one screen over.
 * Shared verbatim by the Filament Sync Health page and `cfb:doctor`.
 */
class CoverageReport
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * Rankings thresholds, in days, set to bound the sync's real cadence.
     *
     * `--only=rankings-current` runs Sunday and Tuesday at 19:00, so the
     * honest worst case between two healthy runs is the five days from Tuesday
     * to Sunday. Against the old four-day warn this row went amber every
     * weekend of the season — on the weekend, which is when someone is most
     * likely to be looking at the board. Six days leaves a day of headroom
     * over that gap; nine is two consecutive missed runs, which is a sync that
     * has genuinely stopped.
     *
     * The other way to close this was a mid-week run, and it buys nothing:
     * polls drop Sunday afternoon and Tuesday evening, so a Thursday pass
     * spends six requests reconciling a poll that cannot have changed.
     */
    private const RANKINGS_AGING_DAYS = 6;

    private const RANKINGS_STALE_DAYS = 9;

    public function __construct(private CfbCalendar $calendar) {}

    /**
     * @return list<array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string}>
     */
    public function checks(): array
    {
        $results = $this->calendar->resultsYear();
        $current = $this->calendar->currentYear();
        $inSeason = ! in_array($this->calendar->phase(), [SeasonPhase::Offseason, SeasonPhase::Preseason], true);

        return [
            $this->teamStats($results),
            $this->summaries($results),
            $this->standings($results),
            $this->rosters($current),
            $this->aggregates($results),
            $this->predictors(),
            $this->rankings($inSeason),
            $this->news($inSeason),
        ];
    }

    public function failing(): int
    {
        return collect($this->checks())->where('status', self::FAIL)->count();
    }

    /**
     * The production gap this class exists for: cfb:players --only=stats
     * queues jobs and exits 0, so with no worker the table stays empty while
     * every console line reads success.
     *
     * @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string}
     */
    private function teamStats(int $year): array
    {
        $expected = $this->fbsTeamCount($year);

        $actual = TeamSeasonStat::where('season_year', $year)
            ->distinct()
            ->count('team_id');

        return $this->row(
            key: 'team-stats',
            label: "Team season stats {$year}",
            expected: $expected,
            actual: $actual,
            status: $this->ratioStatus($actual, $expected),
            detail: "{$actual} of {$expected} FBS teams have stat rows",
            remedy: 'cfb:players --only=stats --year=results',
        );
    }

    /** @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string} */
    private function summaries(int $year): array
    {
        $completed = Game::completed()
            ->whereHas('season', fn ($q) => $q->where('year', $year))
            ->count();

        $with = Game::completed()
            ->whereHas('season', fn ($q) => $q->where('year', $year))
            ->whereHas('summary')
            ->count();

        return $this->row(
            key: 'summaries',
            label: "Box scores {$year}",
            expected: $completed,
            actual: $with,
            status: $this->ratioStatus($with, $completed),
            detail: sprintf('%d of %d completed games have a summary', $with, $completed),
            remedy: 'cfb:summaries --missing',
        );
    }

    /** @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string} */
    private function standings(int $year): array
    {
        // FBS and FCS conference members: ESPN publishes standings for those
        // two divisions only, and an independent has no conference standing
        // to have — measured, D2/D3's 400 members carry zero rows by design,
        // so counting them turns a healthy 265/265 into a red 265/796.
        $expected = TeamSeason::where('season_year', $year)
            ->whereNotNull('conference_id')
            ->whereIn('classification', ['FBS', 'FCS'])
            ->distinct()
            ->count('team_id');

        $actual = Standing::fromEspn()
            ->where('season_year', $year)
            ->distinct()
            ->count('team_id');

        return $this->row(
            key: 'standings',
            label: "Standings {$year}",
            expected: $expected,
            actual: $actual,
            status: $this->ratioStatus($actual, $expected, fail: 0.5),
            detail: "{$actual} of {$expected} conference members have an ESPN standing",
            remedy: 'cfb:sync --only=standings --year=results',
        );
    }

    /** @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string} */
    private function rosters(int $year): array
    {
        $expected = $this->fbsTeamCount($year);

        $actual = AthleteTeamSeason::where('season_year', $year)
            ->distinct()
            ->count('team_id');

        return $this->row(
            key: 'rosters',
            label: "Rosters {$year}",
            expected: $expected,
            actual: $actual,
            status: $this->ratioStatus(min($actual, $expected), $expected),
            detail: "{$actual} teams have {$year} roster rows, {$expected} FBS expected",
            remedy: 'cfb:players --only=rosters --year=current',
        );
    }

    /** @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string} */
    private function aggregates(int $year): array
    {
        // The FULL-season rows are what the leaderboards read; regular-season
        // rows existing without them means the fold never ran.
        $actual = AthleteSeasonStat::where('season_year', $year)
            ->where('season_type', AggregateAthleteStats::FULL_SEASON)
            ->count();

        $haveBoxScores = Game::completed()
            ->whereHas('season', fn ($q) => $q->where('year', $year))
            ->exists();

        return $this->row(
            key: 'aggregates',
            label: "Derived season totals {$year}",
            expected: $haveBoxScores ? 'rows' : 'none yet',
            actual: number_format($actual),
            status: ! $haveBoxScores || $actual > 0 ? self::OK : self::FAIL,
            detail: $haveBoxScores
                ? number_format($actual).' full-season athlete-category rows'
                : 'no completed games to derive from yet',
            remedy: 'cfb:aggregate --year=results',
        );
    }

    /**
     * Predictors serve UPCOMING games only, so a gap here is about to become
     * permanent — the check that earns its row twice over.
     *
     * @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string}
     */
    private function predictors(): array
    {
        $upcoming = Game::query()
            ->where('completed', false)
            ->whereBetween('kickoff_at', [now(), now()->addDays(10)])
            ->whereNotNull('home_team_id')
            ->pluck('id');

        if ($upcoming->isEmpty()) {
            return $this->row(
                key: 'predictors',
                label: 'Matchup predictors',
                expected: 0,
                actual: 0,
                status: self::OK,
                detail: 'no fixtures in the next 10 days',
                remedy: null,
            );
        }

        $with = GamePredictor::whereIn('game_id', $upcoming)->count();

        return $this->row(
            key: 'predictors',
            label: 'Matchup predictors',
            expected: $upcoming->count(),
            actual: $with,
            // ESPN genuinely declines to model some games (FCS opponents),
            // so full coverage is not the bar — most coverage is.
            status: $this->ratioStatus($with, $upcoming->count(), warn: 0.8, fail: 0.5),
            detail: sprintf('%d of %d fixtures in the next 10 days are modelled', $with, $upcoming->count()),
            remedy: 'cfb:sync --only=predictors',
        );
    }

    /**
     * Rankings freshness, measured against when the polls were last CONFIRMED
     * rather than when a row was last written.
     *
     * Those differ, and the gap is not an accident. `SyncRankings` reconciles
     * with `updateOrCreate`, so Eloquent's `save()` skips the write entirely
     * when nothing is dirty — the rule that keeps writes off a scale-to-zero
     * database is exactly what stops any row timestamp recording that a run
     * happened. Between Tuesday and Sunday the poll does not change, so a
     * perfectly healthy run writes nothing; in the preseason one poll stands
     * unchanged for weeks. Read off the rows alone, this check reported six
     * days stale for data a completed sync had confirmed the day before, and
     * `created_at` versus `updated_at` was never the difference.
     *
     * So a completed rankings run that reconciled rows counts as evidence too.
     * `records > 0` is doing real work there: the site host answers a
     * disallowed User-Agent with a 403, which `EspnClient` returns as null and
     * the sync completes on having written nothing. A run row alone would make
     * that silence look like freshness — the precise failure this class was
     * built to catch — so only a run that actually reconciled a poll counts.
     *
     * @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string}
     */
    private function rankings(bool $inSeason): array
    {
        $written = Ranking::max('updated_at');

        /*
         * A run refines the age of data we hold; it cannot invent data we do
         * not. With no ranking rows at all there is nothing for a run row to
         * be evidence ABOUT, so the ledger is not consulted and the check
         * stays FAIL — a freshness check that reports a recent date over an
         * empty table is worse than no check.
         */
        $confirmed = $written === null ? null : $this->lastRankingsSyncAt();

        $latest = match (true) {
            $written === null => null,
            $confirmed === null => $written,
            default => max($written, $confirmed),
        };

        $age = $latest ? now()->diffInDays($latest, true) : null;

        $status = match (true) {
            ! $inSeason => self::OK,
            $age === null => self::FAIL,
            $age > self::RANKINGS_STALE_DAYS => self::FAIL,
            $age > self::RANKINGS_AGING_DAYS => self::WARN,
            default => self::OK,
        };

        return $this->row(
            key: 'rankings',
            label: 'Rankings freshness',
            // Not "out of season": in August the scheduler still runs, and a
            // preseason poll is out. The threshold relaxes, the claim does not.
            expected: $inSeason ? '≤ '.self::RANKINGS_AGING_DAYS.' days' : 'relaxed',
            actual: $age === null ? 'never' : sprintf('%.0f days', $age),
            status: $status,
            detail: match (true) {
                $latest === null => 'no ranking rows at all',
                $confirmed !== null && $confirmed > $written => "polls last reconciled {$confirmed}, unchanged since {$written}",
                default => "latest poll rows written {$written}",
            },
            remedy: 'cfb:sync --only=rankings-current --year=current',
        );
    }

    /**
     * When a rankings sync last reconciled an actual poll, or null if the
     * ledger holds none.
     *
     * Both the weekly current-week pass and the full-season backfill are real
     * evidence; nothing else writes poll rows.
     */
    private function lastRankingsSyncAt(): ?string
    {
        return FeedRun::whereIn('command', ['sync:rankings-current', 'sync:rankings'])
            ->where('status', FeedRun::COMPLETE)
            ->where('records', '>', 0)
            ->max('finished_at');
    }

    /** @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string} */
    private function news(bool $inSeason): array
    {
        $latest = Article::max('published_at');
        $ceiling = $inSeason ? 24 : 24 * 7;
        $age = $latest ? now()->diffInHours($latest, true) : null;

        $status = match (true) {
            $age === null => self::FAIL,
            $age > $ceiling * 2 => self::FAIL,
            $age > $ceiling => self::WARN,
            default => self::OK,
        };

        return $this->row(
            key: 'news',
            label: 'News freshness',
            expected: '≤ '.($inSeason ? '24 hours' : '7 days'),
            actual: $age === null ? 'never' : sprintf('%.0f hours', $age),
            status: $status,
            detail: $latest ? "newest article published {$latest}" : 'no articles at all',
            remedy: 'cfb:sync --only=news',
        );
    }

    private function fbsTeamCount(int $year): int
    {
        return TeamSeason::where('season_year', $year)
            ->where('classification', 'FBS')
            ->distinct()
            ->count('team_id');
    }

    private function ratioStatus(int $actual, int $expected, float $warn = 0.98, float $fail = 0.9): string
    {
        if ($expected === 0) {
            return self::OK;
        }

        $ratio = $actual / $expected;

        return match (true) {
            $ratio < $fail => self::FAIL,
            $ratio < $warn => self::WARN,
            default => self::OK,
        };
    }

    /**
     * @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string}
     */
    private function row(
        string $key,
        string $label,
        int|string $expected,
        int|string $actual,
        string $status,
        string $detail,
        ?string $remedy,
    ): array {
        return compact('key', 'label', 'expected', 'actual', 'status', 'detail', 'remedy');
    }
}
