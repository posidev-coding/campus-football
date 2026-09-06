<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Analytics\AdvisorLedger;
use App\Filament\Widgets\Analytics\ErrorRateByRoute;
use App\Filament\Widgets\Analytics\IngestBuffers;
use App\Filament\Widgets\Analytics\OpsChecks;
use App\Filament\Widgets\Analytics\PerformanceTop;
use App\Support\AnalyticsCatalog;
use App\Support\OpsAnswer;
use App\Support\OpsReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Health — the same rows `cfb:telemetry` prints and `/ops/telemetry` serves,
 * for somebody standing at a keyboard.
 *
 * NO FILTERS, deliberately. Every row here is already scoped to
 * `OpsReport::HOURS`, and a range selector would invite reading a
 * twenty-four-hour check over ninety days, which is not the same check with a
 * wider window — it is a different question with the same label.
 *
 * Every widget reads the SAME support class the snapshot does — `OpsReport`,
 * `PerformanceReport`, `AnalyticsCatalog`. That is the `CoverageReport` rule
 * and it is the whole reason this page can be trusted beside the payload: the
 * panel and the advisor cannot disagree about which query is slowest, because
 * there is one implementation and two skins on it.
 */
class HealthDashboard extends BaseDashboard
{
    protected static ?string $title = 'Health';

    protected static UnitEnum|string|null $navigationGroup = 'Analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 2;

    protected static string $routePath = 'health';

    /**
     * The last answer, held on the page so the modal can re-render with it.
     *
     * @var array<string, mixed>|null
     */
    public ?array $answer = null;

    /** Why there is no answer, when there is none. Plain, for the asker. */
    public ?string $miss = null;

    public function getColumns(): int|array
    {
        return 12;
    }

    /**
     * "Ask the data" — a sentence in, ONE named question out.
     *
     * The model names a key from {@see AnalyticsCatalog::QUESTIONS}
     * and a window token, and the application runs it. There is nowhere in
     * that exchange for a number to be invented, which is the house rule and
     * the reason `docs/plans/analytics.md` turned down a plugin that writes
     * SQL and narrates the rows.
     *
     * Hidden entirely when the AI layer is off, rather than shown and
     * refusing: a door that never opens is worse than no door.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('askTheData')
                ->label('Ask the data')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (): bool => OpsAnswer::available())
                ->modalHeading('Ask the data')
                ->modalDescription('One sentence. It names one of the questions the catalog already answers and runs it — it never makes up a number.')
                ->modalSubmitActionLabel('Ask')
                ->modalContent(fn (): ?View => $this->answer === null && $this->miss === null
                    ? null
                    : view('filament.partials.ops-answer', ['answer' => $this->answer, 'miss' => $this->miss]))
                ->schema([
                    TextInput::make('question')
                        ->label('Your question')
                        ->placeholder('How many people were here this week?')
                        ->required()
                        ->maxLength(200),
                ])
                ->action(function (array $data, Action $action): void {
                    [$this->answer, $reason] = app(OpsAnswer::class)->for($data['question']);

                    // The developer reason goes to the log, never to the
                    // screen: it names budgets and classifier states, and the
                    // asker's answer to all of them is the same sentence.
                    $this->miss = $this->answer === null ? OpsAnswer::MISS : null;

                    if ($this->answer === null) {
                        Log::info('Ops question declined.', ['reason' => $reason]);
                    }

                    // Halt, so the modal stays open and re-renders with the
                    // answer above the box rather than closing on the one
                    // thing the asker opened it for.
                    $action->halt();
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'The last '.OpsReport::HOURS.' hours. The same rows php artisan cfb:telemetry prints.';
    }

    /** @return list<class-string> */
    public function getWidgets(): array
    {
        return [
            OpsChecks::class,
            IngestBuffers::class,
            ErrorRateByRoute::class,
            PerformanceTop::class,
            AdvisorLedger::class,
        ];
    }
}
