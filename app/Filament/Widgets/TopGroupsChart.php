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
