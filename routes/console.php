<?php

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
 * Rankings: one request returns all five polls for a week, so this is the
 * cheapest feed in the app. Polls drop Sunday afternoon and Tuesday evening.
 */
Schedule::command('cfb:sync --only=rankings')
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
