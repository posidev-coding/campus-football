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
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

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
     * Measured against the teams that have actually PLAYED, not the whole FBS
     * roll, and only as far as the sync has been asked to reach. Against all
     * 138 this row was red from the first day of every season until ~90% of
     * the conference had both played and synced — several weeks a year of a
     * check that could no longer report the thing it was built for, and a
     * `cfb:doctor` exit code nobody could use.
     *
     * The cutoff is the sync's own cadence rather than the calendar's:
     * `--only=stats` is a weekly Tuesday job and games finish on Saturday, so
     * without it the row would simply move its red to Sat/Sun/Mon. Closing
     * that gap by running the command more often is not available — one
     * request per team, on a weekly cost tier.
     *
     * @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string}
     */
    private function teamStats(int $year): array
    {
        $syncedAt = $this->lastTeamStatsSyncAt();

        $played = $this->fbsTeamsPlayedBy($year, $syncedAt);
        $expected = count($played);

        $actual = $played === [] ? 0 : TeamSeasonStat::where('season_year', $year)
            ->whereIn('team_id', $played)
            ->distinct()
            ->count('team_id');

        return $this->row(
            key: 'team-stats',
            label: "Team season stats {$year}",
            expected: $expected,
            actual: $actual,
            status: $this->ratioStatus($actual, $expected),
            detail: $syncedAt === null
                ? "{$actual} of {$expected} FBS teams that have played have stat rows"
                : sprintf(
                    '%d of %d FBS teams that had played by the last stats sync (%s) have stat rows',
                    $actual,
                    $expected,
                    $syncedAt->toDateString(),
                ),
            remedy: 'cfb:players --only=stats --year=results',
        );
    }

    /**
     * When the scheduled team-stats pass last finished, or null if the ledger
     * holds no completed one.
     *
     * Null is NOT translated into a cutoff. A run row is the only evidence
     * that the sync was ever asked for these games, and without it the check
     * makes no allowance at all — it falls back to every team that has played,
     * which is the strictest claim it can honestly make. Substituting a date
     * here would be inventing a window nothing ran in.
     *
     * `feed_runs` prunes at a fortnight, so this goes null in the offseason,
     * when the command is not scheduled. That is the right time for it to:
     * by then every team has played and every team should have rows.
     */
    private function lastTeamStatsSyncAt(): ?CarbonInterface
    {
        $latest = FeedRun::where('command', 'players:stats')
            ->where('status', FeedRun::COMPLETE)
            ->max('finished_at');

        return $latest ? CarbonImmutable::parse($latest) : null;
    }

    /**
     * FBS team ids with a completed game in this season, optionally only those
     * that had kicked off by `$by`.
     *
     * `kickoff_at` rather than a completion time because games carry no
     * finished-at column; the cutoff is a weekly sync run, so kickoff is
     * precise enough and errs toward asking for MORE coverage, never less.
     *
     * @return list<int>
     */
    private function fbsTeamsPlayedBy(int $year, ?CarbonInterface $by): array
    {
        $games = Game::completed()
            ->whereHas('season', fn ($q) => $q->where('year', $year))
            ->when($by, fn ($q) => $q->where('kickoff_at', '<', $by));

        $played = $games->clone()->pluck('home_team_id')
            ->merge($games->clone()->pluck('away_team_id'))
            ->filter()
            ->unique();

        if ($played->isEmpty()) {
            return [];
        }

        return TeamSeason::where('season_year', $year)
            ->where('classification', 'FBS')
            ->whereIn('team_id', $played->all())
            ->distinct()
            ->pluck('team_id')
            ->all();
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

    /** @return array{key: string, label: string, expected: int|string, actual: int|string, status: string, detail: string, remedy: ?string} */
    private function rankings(bool $inSeason): array
    {
        $latest = Ranking::max('created_at');
        $age = $latest ? now()->diffInDays($latest, true) : null;

        $status = match (true) {
            ! $inSeason => self::OK,
            $age === null => self::FAIL,
            $age > 8 => self::FAIL,
            $age > 4 => self::WARN,
            default => self::OK,
        };

        return $this->row(
            key: 'rankings',
            label: 'Rankings freshness',
            // Not "out of season": in August the scheduler still runs, and a
            // preseason poll is out. The threshold relaxes, the claim does not.
            expected: $inSeason ? '≤ 4 days' : 'relaxed',
            actual: $age === null ? 'never' : sprintf('%.0f days', $age),
            status: $status,
            detail: $latest ? "latest poll rows written {$latest}" : 'no ranking rows at all',
            remedy: 'cfb:sync --only=rankings-current --year=current',
        );
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
