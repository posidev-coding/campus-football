<?php

namespace App\Filament\Widgets;

use App\Models\Pick;
use App\Services\CfbCalendar;
use App\Support\Brand;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Picks made per Saturday, across the season being played.
 *
 * The season comes from `CfbCalendar::currentYear()` and never from a
 * hardcoded year or "the latest season in the table" — a season exists in the
 * database months before it is played.
 *
 * Weeks nobody has played yet are ABSENT, not zero. A zero-filled line reads
 * as "nobody picked" for a Saturday that has not happened, which is a
 * fabricated data point wearing a real one's clothes.
 */
class PicksTrendChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Picks by Saturday';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        return 'Season '.app(CfbCalendar::class)->currentYear().'. Saturdays nobody has played yet are not on the line.';
    }

    protected function getData(): array
    {
        $year = app(CfbCalendar::class)->currentYear();

        $rows = Pick::query()
            ->join('slate_games', 'picks.slate_game_id', '=', 'slate_games.id')
            ->join('slates', 'slate_games.slate_id', '=', 'slates.id')
            ->join('contests', 'slates.contest_id', '=', 'contests.id')
            ->where('contests.season_year', $year)
            ->groupBy('slates.saturday')
            ->orderBy('slates.saturday')
            ->selectRaw('slates.saturday as saturday, count(*) as total')
            ->get();

        return [
            'labels' => $rows
                ->map(fn ($row): string => Carbon::parse($row->saturday)->format('M j'))
                ->all(),
            'datasets' => [
                [
                    'label' => 'Picks',
                    'data' => $rows->map(fn ($row): int => (int) $row->total)->all(),
                    'borderColor' => Brand::color('lager'),
                    'fill' => false,
                ],
            ],
        ];
    }

    protected ?array $options = [
        'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        'plugins' => ['legend' => ['display' => false]],
    ];
}
