<?php

namespace App\Filament\Pages;

use App\Jobs\FetchGameSummary;
use App\Jobs\SyncTeamSeason;
use App\Models\FeedRun;
use App\Models\Game;
use App\Services\CfbCalendar;
use App\Support\CoverageReport;
use App\Support\SyncSchedule;
use App\Support\TeamGlance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

/**
 * The operational front door: is the schedule healthy, is the data whole, and
 * the levers to fix either without a terminal.
 *
 * Reads three sources — the schedule itself (introspected, never a second
 * registry), the feed_runs ledger, and CoverageReport, which cfb:doctor
 * shares — so the panel and the terminal can never disagree.
 */
class SyncHealth extends Page
{
    protected string $view = 'filament.pages.sync-health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $navigationLabel = 'Sync Health';

    protected static ?string $title = 'Sync Health';

    /**
     * The tasks an admin may queue by hand. A curated allowlist rather than
     * free text, because this dispatches console work from a web form — the
     * options ARE the validation.
     *
     * @return array<string, string>
     */
    public static function runnableTasks(): array
    {
        return [
            'cfb:games --tier=current' => 'Games — current week',
            'cfb:games --tier=recent' => 'Games — recent (last week + this week)',
            'cfb:games --tier=season --year=current' => 'Games — whole current season',
            'cfb:sync --only=standings --year=results' => 'Standings',
            'cfb:sync --only=compute --year=results' => 'Standings — computed cross-check',
            'cfb:sync --only=reconcile --year=results' => 'Standings — reconcile',
            'cfb:sync --only=rankings-current --year=current' => 'Rankings — current week',
            'cfb:sync --only=predictors' => 'Matchup predictors',
            'cfb:sync --only=teams --year=current' => 'Teams',
            'cfb:sync --only=conferences --year=current' => 'Conferences',
            'cfb:sync --only=news' => 'News — general feed',
            'cfb:sync --only=leaders --year=results' => 'National leaders',
            'cfb:players --only=rosters --year=current' => 'Rosters — all teams',
            'cfb:players --only=stats --year=results' => 'Team stats — all teams',
            'cfb:coaches --current' => 'Coaches — current season',
            'cfb:aggregate --year=results' => 'Derive season totals',
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'tasks' => app(SyncSchedule::class)->tasks(),
            'checks' => app(CoverageReport::class)->checks(),
            'failures' => FeedRun::where('status', FeedRun::FAILED)
                ->orderByDesc('started_at')
                ->limit(10)
                ->get(),
            'spendDay' => FeedRun::where('started_at', '>=', now()->subDay())->sum('requests'),
            'spendWeek' => FeedRun::where('started_at', '>=', now()->subWeek())->sum('requests'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runTask')
                ->label('Run a task')
                ->icon(Heroicon::OutlinedPlay)
                ->schema([
                    Select::make('task')
                        ->label('Task')
                        ->options(self::runnableTasks())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    // The options list is the validation: nothing outside the
                    // allowlist can reach Artisan from this form.
                    abort_unless(array_key_exists($data['task'], self::runnableTasks()), 422);

                    // Queued so the request returns immediately; the command's
                    // own TracksFeedRun row is the receipt. The backfill queue
                    // keeps a hand-run pass off the live queue a Saturday needs.
                    Artisan::queue($data['task'])->onQueue('backfill');

                    Notification::make()
                        ->title('Queued')
                        ->body("php artisan {$data['task']} — its run will appear in the ledger.")
                        ->success()
                        ->send();
                }),

            Action::make('refetchSummary')
                ->label('Refetch a box score')
                ->icon(Heroicon::OutlinedArrowPath)
                ->schema([
                    TextInput::make('game')
                        ->label('Game id')
                        ->helperText('The number in the game page URL.')
                        ->numeric()
                        ->required()
                        ->rule('exists:games,id'),
                ])
                ->action(function (array $data): void {
                    $game = Game::findOrFail((int) $data['game']);

                    // Forced: the whole point of a hand-asked refetch is a
                    // summary that is not due one.
                    FetchGameSummary::dispatch($game->id, force: true)->onQueue('live');

                    Notification::make()
                        ->title('Queued')
                        ->body("Summary refetch for {$game->name}.")
                        ->success()
                        ->send();
                }),

            Action::make('resyncTeam')
                ->label('Resync a team')
                ->icon(Heroicon::OutlinedUserGroup)
                ->schema([
                    Select::make('team')
                        ->label('Team')
                        ->options(collect(TeamGlance::fbsTeams())->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $year = app(CfbCalendar::class)->currentYear();

                    SyncTeamSeason::dispatch(
                        teamId: (int) $data['team'],
                        year: $year,
                        rosters: true,
                        stats: true,
                    );

                    Notification::make()
                        ->title('Queued')
                        ->body("Roster and stats resync for team {$data['team']}, {$year}.")
                        ->success()
                        ->send();
                }),

            Action::make('queueMissingSummaries')
                ->label('Queue missing box scores')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->requiresConfirmation()
                ->modalDescription('Queues a fetch for every completed game with no stored summary, on the backfill queue.')
                ->action(function (): void {
                    Artisan::queue('cfb:summaries --missing')->onQueue('backfill');

                    Notification::make()
                        ->title('Queued')
                        ->body('Missing summaries will drain through the backfill queue; the run lands in the ledger.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
