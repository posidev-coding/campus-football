<?php

use App\Models\ClientError;
use App\Models\FeedRun;
use App\Models\StoredNotification;
use App\Models\User;
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
| Every mutex carries an EXPIRY sized as a small multiple of its cadence —
| 5 for the minute/five-minute tasks, 10 for the live summary sweep, 30 for
| hourlies, 60 for the daily-and-slower block. The 24-hour default survives
| only on `cfb:sync --only=teams` (~165s, the longest inline run): anywhere
| else, a worker OOM'd mid-run would leave an unexpired mutex that froze
| scoreboard, grading and alerts for the rest of the day.
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

/*
 * Its complement, for the entries that keep a REDUCED cadence out of season
 * rather than stopping. Reference data still drifts in the spring —
 * realignment is announced, portal players move, next season's schedule
 * publishes — but hourly is a cadence for a Saturday, not for June.
 *
 * Every wake matters more than the request does: a scheduled task holds a
 * scale-to-zero app cluster up for the whole sleep timeout, so an hourly
 * job in the offseason is what stands between this app and months of
 * genuine sleep.
 */
$offSeason = fn () => ! in_array(now($tz)->month, [8, 9, 10, 11, 12, 1], true);

/*
 * Tier 1 — in-progress games. One request, and the command returns without
 * calling ESPN at all when nothing is live.
 *
 * The window runs to 3am, NOT to midnight. A West Coast night game kicks at
 * 10:30pm Eastern and is still being played at 2am, so a window ending at
 * 23:59 freezes the score of exactly the games people are still awake for —
 * and leaves the final, the box score and every pick'em result waiting until
 * the next morning's tier. Laravel rolls the end time forward a day when it
 * is earlier than the start, so this reads as one continuous slate.
 */
Schedule::command('cfb:games --tier=live')
    ->everyMinute()
    ->timezone($tz)
    ->between('11:00', '03:00')
    ->when($inSeason)
    ->withoutOverlapping(5);

/*
 * Live box scores ride the same window as the live tier. The command's first
 * query is its own guard — no in-progress games means no dispatches and no
 * ESPN cost — and each dispatched job re-checks staleness before spending a
 * request, so viewers and this sweep can never stack fetches for one game.
 */
Schedule::command('cfb:summaries:live')
    ->everyTwoMinutes()
    ->timezone($tz)
    ->between('11:00', '03:00')
    ->when($inSeason)
    ->withoutOverlapping(10);

// Tier 2 — the current week on game days, catching finals and late corrections
// once the live window closes.
Schedule::command('cfb:games --tier=current')
    ->hourly()
    ->timezone($tz)
    ->when($inSeason)
    ->days([ScheduleClass::THURSDAY, ScheduleClass::FRIDAY, ScheduleClass::SATURDAY, ScheduleClass::SUNDAY])
    ->withoutOverlapping(30);

// Tier 3 — last week plus this week. Two requests, nightly. Picks up stat
// corrections and rescheduled games without touching the rest of the season.
Schedule::command('cfb:games --tier=recent')
    ->dailyAt('04:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

/*
 * Rankings. Polls drop Sunday afternoon and Tuesday evening.
 *
 * The CURRENT week only — 6 requests. The full-season variant re-reads all 18
 * weeks, which was ~126 requests twice a week to learn one new week of polls.
 * Published rankings never change retroactively, so re-syncing week 3 in
 * November is pure waste and a pointless write pass against a scale-to-zero
 * database. `cfb:sync --only=rankings` still exists for a backfill.
 */
Schedule::command('cfb:sync --only=rankings-current --year=current')
    ->days([ScheduleClass::SUNDAY, ScheduleClass::TUESDAY])
    ->at('19:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

/*
 * Predictors cost one request per game, so they are scoped to upcoming
 * fixtures — 80-100 a week per pass. Wednesday puts fresh matchup quality in
 * front of commissioners before the Wednesday-midnight slate deadline and
 * ahead of Thursday's autopilot; Thursday and Saturday morning keep the game
 * page's matchup predictor current through the week ESPN actually re-models.
 * The feed serves UPCOMING games only, so a projection not captured before
 * kickoff is unrecoverable — the cadence is the capture.
 */
foreach ([[ScheduleClass::WEDNESDAY, '06:00'], [ScheduleClass::THURSDAY, '06:00'], [ScheduleClass::SATURDAY, '08:00']] as [$day, $at]) {
    Schedule::command('cfb:sync --only=predictors')
        ->weeklyOn($day, $at)
        ->timezone($tz)
        ->when($inSeason)
        ->withoutOverlapping(60);
}

// Standings follow the games, so they run after the nightly game pass. The
// reconciler runs last and flags any disagreement for the admin panel.
Schedule::command('cfb:sync --only=standings --year=results')
    ->dailyAt('04:30')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

Schedule::command('cfb:sync --only=compute --year=results')
    ->dailyAt('04:40')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

Schedule::command('cfb:sync --only=reconcile --year=results')
    ->dailyAt('04:45')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

// Reference data barely moves. Conference membership can still change mid-year
// when ESPN corrects its tree, so this is weekly rather than annual — but never
// on a game day.
Schedule::command('cfb:sync --only=conferences --year=current')
    ->weeklyOn(ScheduleClass::TUESDAY, '03:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

Schedule::command('cfb:sync --only=conferences --year=current')
    ->monthlyOn(1, '03:00')
    ->timezone($tz)
    ->when($offSeason)
    ->withoutOverlapping(60);

/*
 * Teams is the longest inline command in the schedule — 800 requests and
 * ~165 seconds, measured. It holds the app cluster awake for all of it, so
 * it runs at 03:20 on a Tuesday and monthly once the season ends.
 */
Schedule::command('cfb:sync --only=teams --year=current')
    ->weeklyOn(ScheduleClass::TUESDAY, '03:20')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping();

Schedule::command('cfb:sync --only=teams --year=current')
    ->monthlyOn(1, '03:20')
    ->timezone($tz)
    ->when($offSeason)
    ->withoutOverlapping();

// A full-season reconcile, deliberately rare. Nine requests. Next season's
// schedule publishes in the spring, so this keeps a monthly beat out of season.
Schedule::command('cfb:games --tier=season --year=current')
    ->weeklyOn(ScheduleClass::TUESDAY, '05:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

Schedule::command('cfb:games --tier=season --year=current')
    ->monthlyOn(1, '05:00')
    ->timezone($tz)
    ->when($offSeason)
    ->withoutOverlapping(60);

/*
 * The player layer. Rosters change slowly and cost one request per team, so
 * weekly is ample; nothing here is ever on a live path. Recruiting is capped
 * (see SyncRecruiting) and matters most through the signing periods.
 */
Schedule::command('cfb:players --only=rosters --year=current')
    ->weeklyOn(ScheduleClass::TUESDAY, '06:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

// The portal and signing classes still move rosters in the spring, just not
// weekly. This command only QUEUES a job per team, so it is cheap either way.
Schedule::command('cfb:players --only=rosters --year=current')
    ->monthlyOn(1, '06:00')
    ->timezone($tz)
    ->when($offSeason)
    ->withoutOverlapping(60);

Schedule::command('cfb:players --only=stats --year=results')
    ->weeklyOn(ScheduleClass::TUESDAY, '06:40')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

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
    ->withoutOverlapping(60);

Schedule::command('cfb:sync --only=injuries --year=current')
    ->days([ScheduleClass::THURSDAY, ScheduleClass::FRIDAY])
    ->at('12:00')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

/*
 * Recruiting. A whole class is SIX requests now — the collection serves 1,000
 * a page and each item already carries its full document, so nothing is
 * fetched per prospect. That is why this syncs two classes rather than one.
 *
 * The classes worth refreshing are the one that just signed and the one being
 * recruited — `current` and `next`, resolved by the COMMAND at run time. It
 * used to sync `config('cfb.season')`, a fixed value that drifts a year
 * behind the class anybody is actually following; then it resolved
 * `currentYear()` HERE, which queried `seasons` while this file loaded —
 * and this file loads during every artisan command, including
 * package:discover on a deploy build whose database has no tables yet, so
 * the deploy died before migrations ran. Nothing in this file may touch the
 * database at load time; anything data-dependent belongs in a closure or in
 * the command itself.
 */
foreach (['current', 'next'] as $class) {
    Schedule::command("cfb:sync --only=recruiting --year={$class}")
        ->weeklyOn(ScheduleClass::WEDNESDAY, '03:00')
        ->timezone($tz)
        ->withoutOverlapping(60);
}

/*
 * News. The general feed is a rolling window of roughly six days and clamps
 * `limit` to 50 whatever you ask for, so history is ACCUMULATED here — a
 * missed run in season loses those articles from the national feed.
 *
 * Hourly in season, four times a day out of it. The request costs nothing;
 * the WAKE does. This was the only entry in the whole schedule running hourly
 * year-round, which meant that in June — with every other job guarded — it
 * alone woke a scale-to-zero app cluster 24 times a day to read a feed that
 * barely moves. Six-day window, six-hourly checks: nothing ages out unseen.
 *
 * (A team's own feed reaches back years, so `SyncTeamNews` can rebuild depth
 * later regardless; only the undifferentiated national feed is a true window.)
 */
Schedule::command('cfb:sync --only=news')
    ->hourly()
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(30);

Schedule::command('cfb:sync --only=news')
    ->everySixHours()
    ->timezone($tz)
    ->when($offSeason)
    ->withoutOverlapping(30);

/*
 * Per-team news, but only for teams somebody actually follows. 136 teams is too
 * many to refresh blindly for content nobody has asked to see; everyone else's
 * team page fetches on demand and caches. Cost tracks interest.
 */
Schedule::command('cfb:news:followed')
    ->twiceDaily(7, 19)
    ->timezone($tz)
    ->name('cfb:news:followed')
    ->when($inSeason)
    ->withoutOverlapping(60);

Schedule::command('cfb:news:followed')
    ->dailyAt('07:00')
    ->timezone($tz)
    ->name('cfb:news:followed:offseason')
    ->when($offSeason)
    ->withoutOverlapping(60);

/*
 * The weekly email.
 *
 * TUESDAY morning, moved off Sunday 2026-08-22 when the pick'em results
 * announcement claimed Sunday noon. Both are BULK mail and both spend the
 * same `mail_daily_budget`, so sharing a day meant the second one released
 * its tail into Monday — and results that arrive after the group has
 * finished arguing are results nobody reads.
 *
 * Tuesday is not merely "not Sunday": it is the pick'em week's own turnover
 * (Cadence::TURNOVER_DOW), so the digest now lands as the new week opens
 * rather than competing with the old week's payoff. Still after the
 * 04:00-05:40 nightly block, which is the constraint that put it at 08:00 —
 * sending before it would report Saturday from Friday's data.
 *
 * Deliberately NOT `->when($inSeason)`. Almost everything else in this file is
 * gated, because there is nothing upstream to fetch in June; this is the
 * opposite case. The offseason is precisely when a football app has to keep
 * turning up, and the digest degrades on its own — no games means the empty
 * line, not an empty email.
 */
Schedule::command('cfb:newsletter')
    ->weeklyOn(ScheduleClass::TUESDAY, '08:00')
    ->timezone($tz)
    ->withoutOverlapping(60);

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
    ->withoutOverlapping(60);

/*
 * National leaders: one request for 1,300 leaderboard rows, the cheapest feed
 * in the app. The athlete resolve pass that follows is capped, so a leaderboard
 * naming players we have never seen cannot turn this into a slow job.
 */
Schedule::command('cfb:sync --only=leaders --year=results')
    ->dailyAt('05:30')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

Schedule::command('cfb:sync --only=athletes')
    ->dailyAt('05:40')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

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
/*
 * The CURRENT season only. A finished season's totals cannot change, and
 * recomputing all six nightly is ~18 season/type rounds over 305,000
 * box-score lines — half an hour of compute to learn what one season did
 * yesterday. Same reasoning as the rankings tier, which stopped re-reading
 * eighteen weeks of published polls to pick up one new week.
 *
 * Scoping matters for more than cost: a scheduled command holds the app
 * cluster awake, and one that outruns the sleep timeout can be cut off
 * mid-pass. `cfb:aggregate` with no --year is still the backfill path.
 */
Schedule::command('cfb:aggregate --year=results')
    ->dailyAt('05:15')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

/*
 * Four prunables ride the same wake. Read inbox rows retire at ninety
 * days (StoredNotification — unread ones never age out). Reported JavaScript
 * errors keep a month, which is four passes of the weekly advisor that reads
 * them. The feed-run ledger keeps a fortnight —
 * the live tier writes a row a minute all Saturday, so in season this trims
 * daily; off season the writers are monthly and the trim rides an hour the
 * news sync is already keeping the cluster awake for.
 *
 * Users joins the pass for the verification self-destruct: never-verified
 * accounts older than User::VERIFICATION_GRACE_DAYS go, and only ever AFTER
 * the reminder below has warned them — prunable() refuses anyone without a
 * three-day-old `verification_reminded_at`, so the weekly off-season cadence
 * can only ever delay past the promise, never beat it.
 */
Schedule::command('model:prune', ['--model' => [ClientError::class, FeedRun::class, User::class, StoredNotification::class]])
    ->dailyAt('04:50')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

Schedule::command('model:prune', ['--model' => [ClientError::class, FeedRun::class, User::class, StoredNotification::class]])
    ->weeklyOn(ScheduleClass::SUNDAY, '07:10')
    ->timezone($tz)
    ->when($offSeason)
    ->withoutOverlapping(60);

/*
 * The funnel counters, out of Redis and into `ux_events`.
 *
 * Rides the same 04:00-07:00 wake as the prunes above rather than earning one
 * of its own — it spends no ESPN requests and writes at most eight rows, and a
 * scheduled task holds a scale-to-zero cluster up for the whole sleep timeout.
 * UNGATED by season: onboarding, invites and the tour happen year-round, and a
 * counter that only persists in-season loses exactly the quiet months where a
 * funnel problem is cheapest to find.
 */
/*
 * Where College GameDay is broadcasting from this Saturday.
 *
 * SUNDAY THROUGH THURSDAY, and the days are the whole design. ESPN announces
 * the site about a week ahead, usually Sunday or Monday, so the window opens
 * on Sunday; Friday and Saturday are pointless because by then the answer is
 * either known or it is not coming. The command stops for the week the moment
 * a Saturday resolves, so a normal week is one or two runs of the five.
 *
 * ONE request, not through EspnClient, so it spends nothing from the ESPN
 * budget. 09:07 rather than 09:00 keeps it off the hour everything else
 * reaches for, and it rides a wake the morning block already needs.
 */
Schedule::command('cfb:gameday')
    ->days([ScheduleClass::SUNDAY, ScheduleClass::MONDAY, ScheduleClass::TUESDAY, ScheduleClass::WEDNESDAY, ScheduleClass::THURSDAY])
    ->at('09:07')
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(60);

Schedule::command('cfb:ux-rollup')
    ->dailyAt('04:55')
    ->timezone($tz)
    ->withoutOverlapping(60);

/*
 * The self-destruct warning, three days ahead of the prune above. Ungated
 * like the newsletter — signups are year-round, so the countdown has to be —
 * and at 07:00 it rides a wake the followed-news sync already pays for in
 * both halves of the year. Sending is what ARMS the purge: an account the
 * mail never reached is never deleted.
 */
Schedule::command('cfb:verification-reminders')
    ->dailyAt('07:00')
    ->timezone($tz)
    ->withoutOverlapping(60);

/*
 * Kickoff alerts, swept every five minutes across a fifteen-minute
 * lookahead — an alert lands ten to fifteen minutes before kick. Confined
 * to the live window the every-minute score tier already keeps awake, and
 * season-gated with it, so this adds no scale-to-zero wakes of its own.
 * The per-game stamp inside the command is what makes the overlap of
 * window and cadence send once.
 */
Schedule::command('cfb:kickoff-alerts')
    ->everyFiveMinutes()
    ->timezone($tz)
    ->between('11:00', '03:00')
    ->when($inSeason)
    ->withoutOverlapping(5);

/*
 * The pick'em deadline sweep: past the week's slate deadline, any contest
 * still without a published slate gets the standard card, so a group is
 * never hung out to dry by a commissioner who lost track of Tuesday.
 * Hourly and DB-only — before the deadline each run exits in one query,
 * and the deadline itself is admin-configurable (Cadence), so the hour
 * grain is what makes "end of day" mean end of day whatever it is set to.
 */
Schedule::command('pickem:publish-slates')
    ->hourly()
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(30);

/*
 * "Your picks are due" — a day out from first kickoff, then ninety minutes
 * out. Anchored on the first KICKOFF, never the commissioner's deadline:
 * that is when an unpublished slate forfeits to the standard card, while
 * players lock game by game at kickoff.
 *
 * This is the one NEW wake pattern the notification phase adds, and the
 * window is why. The live tier already holds 11:00-03:00 awake, but a
 * Friday-lunchtime wave one and a Saturday-morning last call both fall
 * outside it, so the window has to open at 08:00. The cost is small and
 * bounded: the sweep is DB-only, and its first query is its own guard —
 * `status = published AND stamp IS NULL` over a table bounded by contests
 * times Saturdays, so a tick with nothing due is one indexed read.
 */
Schedule::command('pickem:remind')
    ->everyFifteenMinutes()
    ->timezone($tz)
    ->between('08:00', '23:45')
    ->when($inSeason)
    ->withoutOverlapping(30);

/*
 * The settle sweep: rescue grading for games that went final without their
 * event, and turn slates official once the week passes the stat-settling
 * window (Cadence::officialFinal — Sunday noon ET by default). Payouts
 * happen only here, keyed, so an hourly cadence risks nothing twice.
 */
Schedule::command('pickem:settle')
    ->hourly()
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(30);

/*
 * The lobby's shelf: at least one open public room per available mode for
 * the current week. The join hook restocks on fill in real time; this is
 * the belt that opens the week's first rooms and repairs any gap.
 */
Schedule::command('pickem:open-lobbies')
    ->hourly()
    ->timezone($tz)
    ->when($inSeason)
    ->withoutOverlapping(30);
