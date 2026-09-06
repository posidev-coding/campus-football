<?php

namespace App\Filament\Widgets\Analytics;

use App\Models\ClientError;
use App\Support\AnalyticsCatalog;
use App\Support\OpsReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Browser errors against the traffic that produced them.
 *
 * A count of errors is not a finding; a count over its own denominator is.
 * Eleven errors on a screen two thousand people opened and eleven on a screen
 * eleven people opened are different problems, and only the second one is
 * probably fatal.
 *
 * THE RATE IS WITHHELD UNDER {@see MIN_VIEWS} VIEWS. Not hidden — the counts
 * still render, and the column says "too few". A percentage over nine views
 * moves eleven points per error and would put a rounding artifact at the top
 * of a table sorted by severity. A bug on a screen ten people opened is still
 * a bug; its evidence is the report count, not a percentage.
 */
class ErrorRateByRoute extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 12;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    /** Views below which a rate is not drawn — the plan's own floor. */
    public const MIN_VIEWS = 50;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Browser errors by screen')
            ->description('The one signal no server-side log sees. Rates are withheld under '.self::MIN_VIEWS.' views.')
            ->records(fn (): array => $this->rows())
            ->columns([
                /*
                 * ->state(), not ->formatStateUsing(), on every column that
                 * can be null. Filament renders its PLACEHOLDER for a null
                 * state and never calls the formatter — so "no data" written
                 * as a formatter is a string that only ever appears in the
                 * source. The words have to be the state itself.
                 */
                TextColumn::make('route')
                    ->label('Screen')
                    ->weight('medium')
                    // Null is a path the router no longer matches — an old
                    // deploy, or a URL that never existed. Saying so beats
                    // echoing a path with an id in it.
                    ->state(fn (array $record): string => $record['route'] ?? 'unresolved')
                    ->description(fn (array $record) => $record['message']),

                TextColumn::make('reports')
                    ->label('Errors')
                    ->alignEnd()
                    ->weight('medium')
                    ->color('danger'),

                TextColumn::make('views_24h')
                    ->label('Views')
                    ->alignEnd()
                    // "no data" and never 0: zero views beside eleven errors
                    // is an impossible pair that reads as a catastrophe.
                    ->state(fn (array $record): string => $record['views_24h'] === null
                        ? 'no data'
                        : number_format($record['views_24h']))
                    ->color('gray'),

                TextColumn::make('rate')
                    ->label('Rate')
                    ->alignEnd()
                    ->state(fn (array $record): string => $record['rate'] ?? 'too few')
                    ->color(fn (array $record): string => $record['rate'] === null ? 'gray' : 'warning'),

                TextColumn::make('viewport')
                    ->label('Width')
                    ->alignEnd()
                    ->state(fn (array $record): string => $record['viewport'] === null
                        ? 'not reported'
                        : $record['viewport'].'px')
                    ->color('gray')
                    ->toggleable(),
            ])
            ->paginated(false);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        $catalog = app(AnalyticsCatalog::class);

        return ClientError::query()
            ->where('created_at', '>=', now()->subHours(OpsReport::HOURS))
            ->orderByDesc('reports')
            ->limit(20)
            ->get()
            ->mapWithKeys(function (ClientError $error) use ($catalog): array {
                $route = $catalog->routeFor($error->path);
                $views = $catalog->routeViews($route, OpsReport::HOURS);

                return [$error->id => [
                    'route' => $route,
                    'message' => $error->message,
                    'reports' => $error->reports,
                    'views_24h' => $views,
                    'viewport' => $error->viewport,
                    'rate' => $views !== null && $views >= self::MIN_VIEWS
                        ? round($error->reports / $views * 100, 1).'%'
                        : null,
                ]];
            })
            ->all();
    }
}
