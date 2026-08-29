<?php

namespace App\Filament\Widgets;

use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Group;
use App\Models\Pick;
use App\Models\WalletEntry;
use App\Support\Cadence;
use App\Support\LiveState;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * What people are actually doing with the thing — groups, contests, picks and
 * the wallet, in one row.
 *
 * The group counts come from `LiveState::groups()` for the same reason the
 * funnel reads `people()`: one implementation, so the panel and the telemetry
 * payload cannot drift apart.
 */
class EngagementStats extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $groups = app(LiveState::class)->groups()['by_kind'];
        $private = $groups[Group::KIND_PRIVATE]['total'] ?? 0;
        $lobby = $groups[Group::KIND_LOBBY]['total'] ?? 0;

        $wallet = WalletEntry::query()
            ->selectRaw('coalesce(sum(xp), 0) as xp_total, coalesce(sum(lattes), 0) as latte_total')
            ->first();

        $saturday = Cadence::currentSaturday();

        return [
            Stat::make('Groups', number_format($private + $lobby))
                ->description($private.' private · '.$lobby.' lobby')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray'),

            Stat::make('Contests', number_format(array_sum($this->byMode())))
                ->description($this->modeBreakdown())
                ->descriptionIcon('heroicon-m-trophy')
                ->color('gray'),

            Stat::make('Picks this week', number_format($this->picksOn()))
                ->description('for '.$saturday->format('M j'))
                ->descriptionIcon('heroicon-m-hand-raised')
                ->color('gray'),

            Stat::make('XP awarded', number_format((int) $wallet->xp_total))
                ->description(number_format((int) $wallet->latte_total).' Beast Lattes poured')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('warning'),
        ];
    }

    /**
     * One grouped query, not a count per mode.
     *
     * @return array<string, int>
     */
    private function byMode(): array
    {
        return $this->modes ??= Contest::query()
            ->groupBy('mode')
            ->pluck(DB::raw('count(*)'), 'mode')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /** @var array<string, int>|null */
    private ?array $modes = null;

    /**
     * "Shotgun 3 · The Woodshed 1" — the mode's own name first, because the
     * labels are proper nouns and "1 the woodshed" is not a sentence.
     *
     * Modes nobody is playing are absent rather than listed at zero, and no
     * contests at all says so in words instead of a row of zeroes.
     */
    private function modeBreakdown(): string
    {
        $parts = collect($this->byMode())
            ->map(fn (int $count, string $mode): string => (ContestMode::tryFrom($mode)?->label() ?? $mode)
                .' '.$count)
            ->values();

        return $parts->isEmpty() ? 'none running yet' : $parts->implode(' · ');
    }

    /**
     * Picks made for the Saturday this pick'em week is on.
     *
     * Joined through `slate_games` to `slates.saturday`, which is the slate's
     * real identity — `week_id` is ESPN's week and one of those can hold two
     * Saturdays.
     */
    private function picksOn(): int
    {
        return Pick::query()
            ->whereHas('slateGame.slate', fn ($query) => $query
                ->whereDate('saturday', Cadence::currentSaturday()->toDateString()))
            ->count();
    }
}
