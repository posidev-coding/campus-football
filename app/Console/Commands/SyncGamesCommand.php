<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Models\Season;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Services\Espn\EspnClient;
use App\Services\Espn\Sync\SyncGames;
use App\Services\Espn\Sync\SyncOdds;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Game sync at a chosen cost tier.
 *
 * A full season is nine requests and roughly 950 games; running that on a live
 * cadence would be absurd. These tiers exist so the scheduler can spend the
 * minimum that keeps data correct.
 */
class SyncGamesCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:games
        {--tier=current : live|today|current|recent|week|season}
        {--year= : Season year, or current|results resolved at run time (defaults to current)}
        {--week= : Week number, with --tier=week}
        {--date= : A specific date (Y-m-d), with --tier=today}';

    protected $description = 'Sync games at a given cost tier';

    public function handle(SyncGames $games, EspnClient $espn): int
    {
        /*
         * A bare invocation means THE SEASON BEING PLAYED, resolved from the
         * calendar — never `config('cfb.season')`. The schedule ran the
         * current and recent tiers bare, the config default was 2025, and
         * for the whole of September 2026 they synced 2025's final week
         * (Dec 8-13) every hour while this season's games went unwritten.
         */
        $year = app(CfbCalendar::class)->resolveYear($this->option('year') ?? 'current');
        $tier = $this->option('tier');

        // Checked BEFORE the run is recorded — a typo is not a feed run.
        if (! in_array($tier, ['live', 'today', 'current', 'recent', 'week', 'season'], true)) {
            $this->error("Unknown tier [{$tier}].");

            return self::FAILURE;
        }

        $started = microtime(true);

        $changed = $this->trackRun("games:{$tier}", $year, fn (): int => match ($tier) {
            // One request, and only when something is actually in progress.
            'live' => $games->live(),

            'today' => $games->day($this->date()),

            // This week — the default, and the right thing to run hourly on a
            // game day.
            // Plus the core-API odds fallback for any Saturday game the
            // scoreboard left without a fresh line — zero requests on a week
            // the scoreboard behaves. See SyncOdds::refreshStale().
            'current' => $this->currentTier($games, $year),

            // Last week plus this week. Catches late stat corrections and
            // rescheduled games without touching the rest of the season.
            'recent' => $this->syncWeek($games, $year, $this->currentWeekNumber($year) - 1)
                + $this->syncWeek($games, $year, $this->currentWeekNumber($year)),

            'week' => $this->syncWeek($games, $year, (int) $this->option('week')),

            'season' => $games->season($year),
        });

        $this->line(sprintf(
            '  <fg=green>✓</> %-8s %d changed  <fg=gray>%d requests, %.1fs</>',
            $tier,
            $changed,
            $espn->callCount(),
            microtime(true) - $started
        ));

        return self::SUCCESS;
    }

    /**
     * The week, then the odds fallback — and the fallback runs even when the
     * scoreboard throws, because a refused scoreboard is exactly the week
     * the fallback exists for. The failure still propagates, so the run is
     * recorded failed rather than "complete".
     */
    private function currentTier(SyncGames $games, int $year): int
    {
        try {
            $changed = $this->syncWeek($games, $year, $this->currentWeekNumber($year));
        } catch (Throwable $e) {
            $this->refreshStaleOdds($year);

            throw $e;
        }

        return $changed + $this->refreshStaleOdds($year);
    }

    private function refreshStaleOdds(int $year): int
    {
        $season = Season::where('year', $year)->where('type', Season::REGULAR)->first();
        $number = $this->currentWeekNumber($year);

        $week = $season === null || $number < 1
            ? null
            : Week::where('season_id', $season->id)->where('number', $number)->first();

        if ($week === null) {
            return 0;
        }

        $lined = app(SyncOdds::class)->refreshStale($week);

        if ($lined > 0) {
            $this->line("  <fg=yellow>!</> {$lined} Saturday lines taken from the core odds fallback");
        }

        return $lined;
    }

    private function syncWeek(SyncGames $games, int $year, int $number): int
    {
        if ($number < 1) {
            return 0;
        }

        $season = Season::where('year', $year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return 0;
        }

        $week = Week::where('season_id', $season->id)->where('number', $number)->first();

        return $week ? $games->week($week) : 0;
    }

    /**
     * Falls back to the most recent week when today sits outside every week's
     * range — the offseason, or a gap between the regular season and the bowls.
     */
    private function currentWeekNumber(int $year): int
    {
        $season = Season::where('year', $year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return 0;
        }

        $now = CarbonImmutable::now();

        $current = Week::where('season_id', $season->id)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->value('number');

        return (int) ($current ?? Week::where('season_id', $season->id)->max('number') ?? 0);
    }

    private function date(): CarbonImmutable
    {
        $date = $this->option('date');

        return $date
            ? CarbonImmutable::parse($date, config('cfb.timezone'))
            : CarbonImmutable::now(config('cfb.timezone'));
    }
}
