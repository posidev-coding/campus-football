<?php

namespace App\Filament\Widgets;

use App\Models\Team;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which schools the pilot actually follows, and which of those are somebody's
 * number one.
 *
 * Horizontal (`indexAxis: y`) so the team labels read left to right — a
 * vertical bar chart with ten school abbreviations under it is a row of
 * rotated text nobody can scan.
 */
class TopTeamsChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Most followed teams';

    protected ?string $description = 'Follows, and how many of them are a favorite.';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        /*
         * `team_follows.team_id` is indexed for exactly this query, and
         * position 1 is the favorite — there is no favorite_team_id column
         * anywhere, the ORDER is the model.
         */
        $teams = Team::query()
            ->withCount([
                'followers',
                'followers as favorites_count' => fn (Builder $query) => $query->where('team_follows.position', 1),
            ])
            ->orderByDesc('followers_count')
            ->having('followers_count', '>', 0)
            ->limit(10)
            ->get();

        return [
            'labels' => $teams->map(fn (Team $team): string => $team->abbreviation ?? $team->placeName())->all(),
            'datasets' => [
                [
                    'label' => 'Follows',
                    'data' => $teams->pluck('followers_count')->all(),
                    // The school's own color, which is the one thing that makes
                    // this chart readable at a glance. Null for a team ESPN
                    // gave us no color for — never a fabricated brand color.
                    'backgroundColor' => $teams->map(fn (Team $team): string => $team->accentColor() ?? '#9ca3af')->all(),
                ],
                [
                    'label' => 'Favorites',
                    'data' => $teams->pluck('favorites_count')->all(),
                    'backgroundColor' => '#d1d5db',
                ],
            ],
        ];
    }

    protected ?array $options = [
        'indexAxis' => 'y',
        'scales' => ['x' => ['ticks' => ['precision' => 0]]],
    ];
}
