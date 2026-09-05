<?php

namespace App\Support;

use App\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which day of a week the scoreboard is showing, and the strip of days it
 * offers instead.
 *
 * `GameOrder` fixed the order WITHIN a day. It could not fix the day itself:
 * a reader on a live Saturday at "All FBS" scope still scrolled past every
 * finished Thursday and Friday card — around seventeen of them — before
 * reaching the one game actually being played. Ordering days by urgency would
 * have stranded a Thursday group under the wrong heading, and collapsing them
 * would only have shortened the scroll past something the reader never asked
 * for. So the current week shows ONE day at a time, and the days it holds
 * become the tabs.
 *
 * Only the current week. A past week is review and a future week is planning,
 * and both want the whole week in one scroll — see the component's
 * `isCurrentWeek()` for why "the week NOW is inside" is stricter, and truer,
 * than "the week the app would open on".
 *
 * Everything here compares `Y-m-d` STRINGS in the app's timezone, never
 * instants. A day is a wall-clock question — a 20:00 ET Saturday kickoff is
 * 00:00 UTC Sunday, and grouping it under Sunday would move a whole evening
 * of games off the day the reader watched them on.
 */
class SlateDates
{
    /**
     * The widest strip the day tabs are asked to hold.
     *
     * A regular week spans at most seven days. The postseason's single week
     * row spans up to twenty-one, which no strip can hold at 390px and which
     * the no-horizontal-scroll rule forbids scrolling — so this cap is also
     * what keeps bowl season on the plain full-week list, with no branch
     * anywhere naming the postseason.
     *
     * Seven fits, with the arithmetic from `x-gutter-tabs`' own measurements:
     * a 352px track at 390px, `fill` cells sized to their label plus 16px of
     * padding, and a three-letter label around 22px — 7 x 38 = 266px, leaving
     * 86px of slack. The collision form below is the worst case at five
     * characters: 7 x 49 = 343px, still inside. `fill` rather than `block`
     * because block divides the track equally, and an equal seventh is 50.3px
     * — close enough to a five-character label to clip it.
     */
    public const MAX_TABS = 7;

    /**
     * The days this set of games lands on, in order, as tab items.
     *
     * The incoming order is the query's `kickoff_at`, so chronology is
     * inherited rather than re-derived — the same trick `GameOrder` plays with
     * PHP's stable sort.
     *
     * @param  Collection<int, Game>  $games
     * @return list<array{value: string, label: string}>
     */
    public static function index(Collection $games): array
    {
        $days = $games
            ->map(fn (Game $game) => $game->kickoff_at->setTimezone(config('cfb.timezone')))
            ->unique(fn (CarbonImmutable $kick) => $kick->format('Y-m-d'))
            ->sortBy(fn (CarbonImmutable $kick) => $kick->format('Y-m-d'))
            ->values();

        /*
         * A week CAN hold two of the same weekday. The split opening week runs
         * 8/22 to 9/8 and its segments are narrowed by `activeBounds()`, but
         * the `wk0` segment still spans two calendar Saturdays and only
         * happens to hold games on one of them in 2026.
         *
         * When a weekday repeats, EVERY label switches to the dated form. A
         * mixed strip — "Sat" beside "8/29" — reads as a rendering fault, and
         * the reader cannot tell which of the two the bare one is.
         */
        $abbreviations = $days->map(fn (CarbonImmutable $kick) => $kick->format('D'));
        $format = $abbreviations->duplicates()->isEmpty() ? 'D' : 'n/j';

        return $days
            ->map(fn (CarbonImmutable $kick) => [
                'value' => $kick->format('Y-m-d'),
                'label' => $kick->format($format),
            ])
            ->all();
    }

    /**
     * Which day to open on.
     *
     * Each step is a deliberate choice, not a substituted default, and the
     * order is the order a reader's attention goes:
     *
     * 1. what they asked for, if this week holds it;
     * 2. the day something is being PLAYED on — the busiest such day, because
     *    a Friday night game still in overtime at 12:10am should not outrank
     *    a Saturday slate that has just kicked off;
     * 3. today, when today is on the strip;
     * 4. the most recent day already played, which is what makes a late
     *    Saturday night land on the games just finished rather than on
     *    Tuesday's;
     * 5. the earliest day, for a week entirely ahead of us.
     *
     * @param  list<array{value: string, label: string}>  $index
     * @param  Collection<int, Game>  $games
     */
    public static function focus(array $index, Collection $games, string $requested, ?CarbonImmutable $now = null): ?string
    {
        if ($index === []) {
            return null;
        }

        $values = array_column($index, 'value');

        // A shared link wins, but only if it names a day this week holds.
        if (in_array($requested, $values, true)) {
            return $requested;
        }

        $live = $games
            ->filter(fn (Game $game) => $game->isInProgress())
            ->countBy(fn (Game $game) => self::key($game))
            ->sortByDesc(fn (int $count, string $day) => [$count, -strtotime($day)]);

        if ($live->isNotEmpty()) {
            return (string) $live->keys()->first();
        }

        $today = ($now ?? CarbonImmutable::now(config('cfb.timezone')))->format('Y-m-d');

        if (in_array($today, $values, true)) {
            return $today;
        }

        // $values is ascending, so the last one before today is the most
        // recent day played.
        $played = array_values(array_filter($values, fn (string $value) => $value < $today));

        return $played !== [] ? end($played) : $values[0];
    }

    /** The day a game belongs to, in the app's timezone. */
    public static function key(Game $game): string
    {
        return $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('Y-m-d');
    }
}
