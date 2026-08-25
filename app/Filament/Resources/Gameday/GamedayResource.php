<?php

namespace App\Filament\Resources\Gameday;

use App\Enums\GamedayStatus;
use App\Filament\Resources\Gameday\Pages\ManageGameday;
use App\Models\GamedayWeek;
use App\Support\GamedayResolver;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The human with the final word on where GameDay is.
 *
 * Same philosophy as the commissioner owning the line: the routine makes a
 * suggestion, a person decides. When the feed lags or the location resolves
 * to nothing, typing a city here takes about ten seconds — and a CONFIRMED
 * row is never overwritten by a later run, so the answer sticks.
 *
 * The form asks for a CITY, not a game. Picking a game from a dropdown of
 * several hundred is the slow, error-prone version of the same act, and the
 * resolver already turns a place and a date into the one game we hold there.
 * If it cannot, the row still saves what was typed — a human overriding the
 * data is allowed to be right — but it says so rather than quietly linking
 * nothing.
 */
class GamedayResource extends Resource
{
    protected static ?string $model = GamedayWeek::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTv;

    protected static ?string $navigationLabel = 'College GameDay';

    protected static string|UnitEnum|null $navigationGroup = 'Work';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'GameDay week';

    protected static ?string $slug = 'gameday';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('city')
                ->label('City')
                ->required()
                ->maxLength(120)
                ->helperText('The campus town, as ESPN says it. The game and host team are resolved from this and the Saturday.'),
            TextInput::make('state')
                ->label('State')
                ->required()
                ->length(2)
                ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                ->helperText('Two letters — LA, TX, OK.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('saturday')->label('Saturday')->date('D, M j')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (GamedayStatus $state): string => $state->label())
                    ->color(fn (GamedayStatus $state): string => match ($state) {
                        GamedayStatus::Confirmed => 'success',
                        GamedayStatus::Proposed => 'info',
                        GamedayStatus::Unknown => 'gray',
                    }),
                TextColumn::make('city')
                    ->label('Location')
                    ->state(fn (GamedayWeek $record): ?string => $record->city === null
                        ? null
                        : $record->city.', '.$record->state)
                    ->placeholder('Not announced'),
                TextColumn::make('team.display_name')->label('Host')->placeholder('—'),
                TextColumn::make('game.name')->label('Game')->placeholder('—')->limit(40),
                TextColumn::make('checked_at')->label('Checked')->since()->placeholder('never'),
            ])
            ->defaultSort('saturday', 'desc')
            ->recordActions([
                EditAction::make()
                    ->label('Set the site')
                    ->modalHeading('Where is GameDay?')
                    /*
                     * Resolve on the way in, so the row that lands is already
                     * joined to our own data — and mark it confirmed, because
                     * a person typing it IS the confirmation.
                     */
                    ->mutateDataUsing(function (array $data, GamedayWeek $record): array {
                        $resolver = app(GamedayResolver::class);
                        $data['state'] = mb_strtoupper(trim($data['state']));
                        $data['city'] = trim($data['city']);

                        $game = $resolver->resolve($data['city'], $data['state'], $record->saturday);

                        if ($game === null) {
                            // Saved anyway — but never silently, and never
                            // linked to a game nobody is playing there.
                            Notification::make()
                                ->title('No game found there that Saturday')
                                ->body('The site was saved, but nothing in our own schedule matches it, so the card will show the place without a game.')
                                ->warning()
                                ->send();
                        }

                        return [
                            ...$data,
                            'site' => $game?->venue?->name,
                            'team_id' => $game === null ? null : $resolver->hostTeam($game)?->id,
                            'game_id' => $game?->id,
                            'status' => GamedayStatus::Confirmed,
                            'announced_at' => $record->announced_at ?? now(),
                            'checked_at' => now(),
                        ];
                    }),

                /*
                 * Confirming what the feed already proposed, without retyping
                 * it. The common case by far: the routine got it right and a
                 * person is agreeing, which is what stops the next run from
                 * being able to change its mind.
                 */
                Action::make('confirm')
                    ->label('Confirm')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (GamedayWeek $record): bool => $record->status === GamedayStatus::Proposed)
                    ->requiresConfirmation()
                    ->modalDescription('Locks this week in. Later runs will leave it alone.')
                    ->action(fn (GamedayWeek $record) => $record->update(['status' => GamedayStatus::Confirmed])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGameday::route('/'),
        ];
    }
}
