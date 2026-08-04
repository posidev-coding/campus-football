<?php

use App\Services\Espn\Sync\SyncNews;
use Illuminate\Console\Scheduling\Schedule as ScheduleClass;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Sync schedule
|--------------------------------------------------------------------------
|
| Every cadence below is chosen against a measured request cost, because the
| previous version of this app got this badly wrong: it ran a full games feed
| every five minutes on Saturday afternoons, each run issuing 70-110 sequential
| requests with no throttle, on top of one live ESPN call per page view per
| viewer. That is roughly 20 requests a second in bursts.
|
| Here the entire Saturday live window costs ONE request per minute, total,
| regardless of how many games are in progress or how many people are watching.
|
| withoutOverlapping is on everything. v3's queue `retry_after` was shorter than
| every job timeout, so long syncs were released and re-run while still
| executing — duplicate concurrent runs hammering the same endpoints.
|
*/

$tz = config('cfb.timezone');

/*
 * College football runs August through mid-January. Guarding on the month is
 * not cosmetic: the live tier checks the database for in-progress games, and
 * without this it would issue that query every minute of every day in the
 * offseason — which on a scale-to-zero database means never letting it sleep.
 * The check is pure PHP, so a guarded tick costs nothing at all.
 */
$inSeason = fn () => in_array(now($tz)->month, [8, 9, 10, 11, 12, 1], true);

// Tier 1 — in-progress games. One request, and the command returns without
// calling ESPN at all when nothing is live.
Schedule::command('cfb:games --tier=live')
    ->everyMinute()
    ->timezone($tz)
    ->between('11:00', '23:59')
    ->when($inSeason)
    ->withoutOverlapping();

// Tier 2 — the current week on game days, catching finals and late corrections
// once the live window closes.
Schedule::command('cfb:games --tier=current')
    ->hourly()
    ->timezone($tz)
    ->when($inSeason)
    ->days([ScheduleClass::THURSDAY, ScheduleClass::FRIDAY, ScheduleClass::SATURDAY, ScheduleClass::SUNDAY])
    ->withoutOverlapping();

// Tier 3 — last week plus this week. Two requests, nightly. Picks up stat
// corrections and rescheduled games without touching the rest of the season.
Schedule::command('cfb:games --tier=recent')
    ->dailyAt('04:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

/*
 * Rankings. Polls drop Sunday afternoon and Tuesday evening.
 *
 * The CURRENT week only — 6 requests. The full-season variant re-reads all 18
 * weeks, which was ~126 requests twice a week to learn one new week of polls.
 * Published rankings never change retroactively, so re-syncing week 3 in
 * November is pure waste and a pointless write pass against a scale-to-zero
 * database. `cfb:sync --only=rankings` still exists for a backfill.
 */
Schedule::command('cfb:sync --only=rankings-current')
    ->days([ScheduleClass::SUNDAY, ScheduleClass::TUESDAY])
    ->at('19:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

/*
 * Predictors cost one request per game, so they are scoped to upcoming Saturday
 * fixtures — 60-80 a week. Running Wednesday puts fresh matchup quality in
 * front of commissioners before the Wednesday-midnight slate deadline, and
 * ahead of Thursday's autopilot.
 */
Schedule::command('cfb:sync --only=predictors')
    ->weeklyOn(ScheduleClass::WEDNESDAY, '06:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

// Standings follow the games, so they run after the nightly game pass. The
// reconciler runs last and flags any disagreement for the admin panel.
Schedule::command('cfb:sync --only=standings')
    ->dailyAt('04:30')
    ->timezone($tz)
    ->withoutOverlapping();

Schedule::command('cfb:sync --only=compute')
    ->dailyAt('04:40')
    ->timezone($tz)
    ->withoutOverlapping();

Schedule::command('cfb:sync --only=reconcile')
    ->dailyAt('04:45')
    ->timezone($tz)
    ->withoutOverlapping();

// Reference data barely moves. Conference membership can still change mid-year
// when ESPN corrects its tree, so this is weekly rather than annual — but never
// on a game day.
Schedule::command('cfb:sync --only=conferences')
    ->weeklyOn(ScheduleClass::TUESDAY, '03:00')
    ->timezone($tz)
    ->withoutOverlapping();

Schedule::command('cfb:sync --only=teams')
    ->weeklyOn(ScheduleClass::TUESDAY, '03:20')
    ->timezone($tz)
    ->withoutOverlapping();

// A full-season reconcile, deliberately rare. Nine requests.
Schedule::command('cfb:games --tier=season')
    ->weeklyOn(ScheduleClass::TUESDAY, '05:00')
    ->timezone($tz)
    ->withoutOverlapping();

/*
 * The player layer. Rosters change slowly and cost one request per team, so
 * weekly is ample; nothing here is ever on a live path. Recruiting is capped
 * (see SyncRecruiting) and matters most through the signing periods.
 */
Schedule::command('cfb:players --only=rosters')
    ->weeklyOn(ScheduleClass::TUESDAY, '06:00')
    ->timezone($tz)
    ->withoutOverlapping();

Schedule::command('cfb:players --only=stats')
    ->weeklyOn(ScheduleClass::TUESDAY, '06:40')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

/*
 * Coach records. `--current` touches only each coach's LATEST season — career
 * history never changes retroactively, the same reasoning that stopped the
 * rankings sync re-reading eighteen weeks to learn one. Weekly during the
 * season keeps tenure records one game behind at worst; the full backfill is
 * `cfb:coaches --missing`, run once. Queued job per coach, so the scheduler
 * process stays free and one bad coach cannot abort the rest.
 */
Schedule::command('cfb:coaches --current')
    ->weeklyOn(ScheduleClass::TUESDAY, '07:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

Schedule::command('cfb:sync --only=injuries')
    ->days([ScheduleClass::THURSDAY, ScheduleClass::FRIDAY])
    ->at('12:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

Schedule::command('cfb:sync --only=recruiting')
    ->weeklyOn(ScheduleClass::WEDNESDAY, '03:00')
    ->timezone($tz)
    ->withoutOverlapping();

/*
 * News. The feed is a rolling window of roughly six days and clamps `limit` to
 * 50 whatever you ask for, so history is ACCUMULATED here rather than
 * backfilled — missing a run loses those articles permanently. Cheap enough
 * (one request) to run often.
 */
Schedule::command('cfb:sync --only=news')
    ->hourly()
    ->timezone($tz)
    ->withoutOverlapping();

/*
 * Per-team news, but only for teams somebody actually follows. 136 teams is too
 * many to refresh blindly for content nobody has asked to see; everyone else's
 * team page fetches on demand and caches. Cost tracks interest.
 */
Schedule::call(fn () => app(SyncNews::class)->followed())
    ->twiceDaily(7, 19)
    ->timezone($tz)
    ->name('cfb:news:followed')
    ->withoutOverlapping();

/*
 * Box scores for games that have finished.
 *
 * One request per game at 544 KB, so this is capped and runs after the nightly
 * game pass rather than during the day. A final game's summary can never
 * change, so `--missing` means each game is fetched exactly once, ever, and a
 * normal in-season night is only the ~60 games just played.
 *
 * This QUEUES one job per game rather than fetching inline — the scheduler
 * process stays free, and memory is bounded by the worker rather than growing
 * across a run. The shared rate limiter keeps the fan-out from raising upstream
 * load.
 *
 * A SAFETY NET, not the main path. SyncGames dispatches a summary the moment a
 * game flips to completed, so a Saturday 11pm final has its box score within
 * about a minute rather than at 05:00 the next morning. This catches anything
 * that job dropped — a failed fetch, a game finished while the live tier was
 * outside its window.
 */
Schedule::command('cfb:summaries --missing --limit=150')
    ->dailyAt('05:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

/*
 * National leaders: one request for 1,300 leaderboard rows, the cheapest feed
 * in the app. The athlete resolve pass that follows is capped, so a leaderboard
 * naming players we have never seen cannot turn this into a slow job.
 */
Schedule::command('cfb:sync --only=leaders')
    ->dailyAt('05:30')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

Schedule::command('cfb:sync --only=athletes')
    ->dailyAt('05:40')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

/*
 * Season totals, derived from box scores we already hold.
 *
 * Costs ZERO ESPN requests — it is arithmetic, not a feed — so it can run
 * often. Placed after the summary sweep so a Saturday's box scores are folded
 * in before anyone reads a leaderboard on Sunday morning.
 *
 * It exists because ESPN's national leaders feed spans every division and only
 * about half its top 100 is FBS, so a scoped leaderboard read from it collapses:
 * the MAC had FOUR players in the national top 100 for passing yards. Ranking
 * our own aggregates gives that conference 43.
 */
Schedule::command('cfb:aggregate')
    ->dailyAt('05:15')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();
