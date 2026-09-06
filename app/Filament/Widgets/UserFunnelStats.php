<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\LiveState;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Who has signed up, and how far each of them got.
 *
 * The counts come from `LiveState::people()` rather than a second set of
 * queries here, so the dashboard and the telemetry payload can never disagree
 * about how many people exist. The two numbers LiveState does NOT carry —
 * textable and installed — are counted locally on purpose: adding them to
 * LiveState would move the `/ops/telemetry` payload shape, and the advisor
 * routine reads that shape.
 */
class UserFunnelStats extends BaseWidget
{
    /*
     * PARKED, not deleted. Overview lists its widgets explicitly now, so
     * discovery no longer decides what lands on the front page — and this one
     * comes back converted in phase 6 or 7 of docs/plans/analytics.md. Left
     * registered and tested in the meantime, because deleting a widget to
     * re-type it a fortnight later is how the reasoning in its docblock gets
     * lost.
     */
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $people = app(LiveState::class)->people();
        $users = $people['users'];

        return [
            Stat::make('Accounts', number_format($users))
                ->description($users === 1 ? 'one so far' : 'people with a login')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray'),

            Stat::make('Verified', number_format($people['verified']))
                ->description($this->share($people['verified'], $users, 'can earn and play'))
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Onboarded', number_format($people['onboarded']))
                ->description($this->share($people['onboarded'], $users, 'finished picking teams'))
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('gray'),

            Stat::make('Installed', number_format($this->installed()))
                ->description($this->share($this->installed(), $users, 'have run it standalone'))
                ->descriptionIcon('heroicon-m-device-phone-mobile')
                ->color('gray'),

            Stat::make('Reachable by push', number_format($people['push_people']))
                ->description($this->share($people['push_people'], $users, 'granted on a device'))
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color('gray'),

            Stat::make('Textable', number_format($this->textable()))
                ->description($this->share($this->textable(), $users, 'opted in and verified a number'))
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('gray'),

            Stat::make('Admins', number_format($people['admins']))
                ->description('can reach this panel')
                ->descriptionIcon('heroicon-m-key')
                ->color('warning'),
        ];
    }

    /**
     * Opted in AND verified the number — the same two conditions
     * `User::canReceiveSms()` asks, minus the per-row `filled($phone)` check
     * a verified stamp already implies.
     */
    private function textable(): int
    {
        return User::query()
            ->where('sms_opt_in', true)
            ->whereNotNull('phone_verified_at')
            ->count();
    }

    private function installed(): int
    {
        return User::query()->whereNotNull('standalone_seen_at')->count();
    }

    /**
     * A percentage, or the plain fact when there is nobody to be a percentage
     * of. Zero users must never render "0% of accounts" — that is a fabricated
     * denominator, and a pilot dashboard reads it as a real signal.
     */
    private function share(int $count, int $total, string $what): string
    {
        if ($total === 0) {
            return $what;
        }

        return round($count / $total * 100).'% of accounts · '.$what;
    }
}
