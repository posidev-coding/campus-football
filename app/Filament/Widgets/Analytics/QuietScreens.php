<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * The screens nobody opens.
 *
 * THE TABLE REFUSES TO ANSWER until the rollup covers the whole window, and
 * that refusal is the entire safety of this widget. A screen looks dead for
 * exactly the reason a new funnel signal reads zero — nothing was counting yet
 * — and the finding this list invites is "delete the door". Filed off a
 * two-day-old rollup that is the `funnel_since` bug with a bigger blast
 * radius, so the heading says `since` every time.
 *
 * A screen with NO ROW AT ALL is the point: absence cannot be read out of a
 * table of what happened, so the catalog walks the route table instead — and
 * only over routes the sensor actually runs on.
 */
class QuietScreens extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 12;

    protected static ?int $sort = 9;

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        $window = AnalyticsWindow::of(28);
        $routes = app(AnalyticsCatalog::class)->routes($window);

        return $table
            ->heading('Quiet screens')
            ->description($this->describe($routes, $window))
            ->records(fn (): array => collect($routes['quiet'] ?? [])
                ->keyBy('route')
                ->all())
            ->columns([
                TextColumn::make('route')
                    ->label('Screen')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->size('xs'),

                TextColumn::make('views')
                    ->label('Views · 28d')
                    ->alignEnd()
                    ->color('gray'),
            ])
            ->emptyStateHeading($routes['quiet'] === null
                ? 'Not enough history yet'
                : 'Every screen is being opened')
            ->emptyStateDescription($routes['quiet'] === null
                ? 'The rollup does not cover 28 days yet, so a quiet screen cannot be told from one nothing was counting.'
                : 'No named screen is under '.AnalyticsCatalog::QUIET_VIEWS.' views over the window.')
            ->paginated(false);
    }

    /** @param  array<string, mixed>  $routes */
    private function describe(array $routes, AnalyticsWindow $window): string
    {
        if ($routes['quiet'] === null) {
            return 'Withheld until the rollup covers the window'
                .($window->sinceDate() === null ? '' : ' — counting since '.$window->sinceDate());
        }

        return 'Under '.AnalyticsCatalog::QUIET_VIEWS.' member views in 28 days · since '.$window->sinceDate();
    }
}
