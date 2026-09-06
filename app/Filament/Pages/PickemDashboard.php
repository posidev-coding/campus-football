<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Analytics\LatePickShare;
use App\Filament\Widgets\Analytics\ParticipationBySlate;
use App\Filament\Widgets\Analytics\PickemStats;
use App\Filament\Widgets\Analytics\PicksBySaturday;
use App\Filament\Widgets\Analytics\PickTiming;
use App\Filament\Widgets\Analytics\ReminderLift;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Cadence;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Pick'em — one Saturday at a time.
 *
 * The other Analytics pages ask about days and weeks. This one is filtered by
 * SATURDAY, because a pick'em product does not happen on a rolling window: it
 * happens on a card, and every question worth asking here ("did they enter",
 * "did they pick late", "did the reminder move anybody") is a question about
 * one slate on one day.
 *
 * The Saturdays come from `Cadence::saturdaysIn()` over the season's weeks —
 * a split week has two, and a calendar-Saturday list would offer dates no
 * game was ever played on. The season comes from `CfbCalendar` and never from
 * "the latest season in the table": a season exists in the database months
 * before it is played.
 */
class PickemDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = "Pick'em";

    protected static UnitEnum|string|null $navigationGroup = 'Analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = 3;

    protected static string $routePath = 'pickem';

    public function getColumns(): int|array
    {
        return 12;
    }

    /** @return list<class-string> */
    public function getWidgets(): array
    {
        return [
            PickemStats::class,
            ReminderLift::class,
            ParticipationBySlate::class,
            LatePickShare::class,
            PickTiming::class,
            PicksBySaturday::class,
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('saturday')
                    ->label('Saturday')
                    ->options(self::saturdays())
                    ->default(Cadence::currentSaturday()->toDateString())
                    ->selectablePlaceholder(false),
            ]),
        ]);
    }

    /**
     * Every Saturday the season actually fields games on, newest first.
     *
     * @return array<string, string>
     */
    public static function saturdays(): array
    {
        $year = app(CfbCalendar::class)->currentYear();

        $days = Week::query()
            ->whereHas('season', fn ($query) => $query->where('year', $year))
            ->get()
            ->flatMap(fn (Week $week): array => Cadence::saturdaysIn($week))
            ->map(fn (CarbonImmutable $day): string => $day->toDateString())
            ->unique()
            ->sortDesc()
            ->values();

        // The current Saturday is always offered, even before its games are
        // synced — a scheduled-but-unplayed week is a real state, and a filter
        // whose default is not in its own options renders empty.
        $current = Cadence::currentSaturday()->toDateString();

        if (! $days->contains($current)) {
            $days = $days->prepend($current);
        }

        return $days
            ->mapWithKeys(fn (string $day): array => [
                $day => CarbonImmutable::parse($day)->format('M j, Y'),
            ])
            ->all();
    }
}
