<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\Cadence;
use App\Support\LiveState;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The Saturday, while it is happening.
 *
 * LIVE ONLY ON A SATURDAY. `getPollingInterval()` returns 60s when the
 * current pick'em Saturday is today and nothing otherwise, because a
 * dashboard that polls all week is a query every minute for six days to watch
 * a number that cannot move. On a Tuesday this is a static read of the
 * Saturday just played.
 *
 * Read through {@see LiveState} with `names: false` — one implementation of
 * what a slate's state is, shared with the ops snapshot, and the machine skin
 * drops the one user-written field on it. A group's name has no business on a
 * dashboard that is really asking "is the product working right now".
 */
class TodayPickem extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 4;

    protected static ?int $sort = 3;

    /**
     * Sixty seconds ON A SATURDAY, and null every other day.
     *
     * Null rather than a long interval: "do not poll" is the honest state for
     * a page whose numbers are settled, and a five-minute poll is still a
     * standing query nobody reads the result of.
     */
    public function getPollingInterval(): ?string
    {
        return $this->isLiveSaturday() ? '60s' : null;
    }

    protected function getStats(): array
    {
        $saturday = Cadence::currentSaturday();
        $state = app(LiveState::class)->build($saturday, names: false);

        $contests = collect($state['contests']);
        $entries = $contests->sum('entries');
        $made = $contests->sum('picks_made');
        $possible = $contests->sum('picks_possible');

        return [
            Stat::make('Slates', (string) $contests->count())
                ->description($this->isLiveSaturday()
                    ? 'Live now — '.$saturday->format('M j')
                    : 'Saturday '.$saturday->format('M j'))
                ->color($this->isLiveSaturday() ? 'success' : 'gray'),

            Stat::make('Entries', (string) $entries)
                ->description($state['games']['kicked'].' of '.$state['games']['in_window'].' games kicked'),

            // A share, and null under any denominator at all — "0% picked" on
            // a Saturday with no slate is the invented number the whole layer
            // refuses.
            Stat::make(
                'Picks in',
                $possible > 0 ? round($made / $possible * 100).'%' : 'no data',
            )
                ->description($possible > 0
                    ? $made.' of '.$possible.' possible'
                    : 'No slate to measure yet')
                ->color($possible > 0 ? 'info' : 'gray'),
        ];
    }

    private function isLiveSaturday(): bool
    {
        return Cadence::currentSaturday()->isSameDay(CarbonImmutable::now(config('cfb.timezone')));
    }
}
