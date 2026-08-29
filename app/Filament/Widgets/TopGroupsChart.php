<?php

namespace App\Filament\Widgets;

use App\Models\Group;
use App\Support\Brand;
use Filament\Widgets\ChartWidget;

/**
 * The biggest rooms and groups, by how many people are actually in them.
 *
 * Horizontal for the same reason the teams chart is: group names are prose,
 * not abbreviations, and prose under a vertical bar is unreadable.
 */
class TopGroupsChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Biggest groups';

    protected ?string $description = 'Members, private groups and lobby rooms together.';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $groups = Group::query()
            ->withCount('members')
            ->orderByDesc('members_count')
            ->having('members_count', '>', 0)
            ->limit(10)
            ->get();

        return [
            'labels' => $groups->pluck('name')->all(),
            'datasets' => [
                [
                    'label' => 'Members',
                    'data' => $groups->pluck('members_count')->all(),
                    // One series color, read from the brand at request time —
                    // the same source the panel's accent comes from, so an
                    // edit on App Branding reaches this without a deploy.
                    'backgroundColor' => Brand::color('lager'),
                ],
            ],
        ];
    }

    protected ?array $options = [
        'indexAxis' => 'y',
        'scales' => ['x' => ['ticks' => ['precision' => 0]]],
        'plugins' => ['legend' => ['display' => false]],
    ];
}
