<?php

namespace App\Filament\Resources\Groups;

use App\Enums\LobbyFlavor;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\Pages\ViewGroup;
use App\Filament\Resources\Groups\RelationManagers\ContestsRelationManager;
use App\Filament\Resources\Groups\RelationManagers\MembersRelationManager;
use App\Models\Group;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Groups — and rooms, which are the same table wearing `kind = 'lobby'`.
 *
 * A GROUP is the private season-long container; a ROOM is the public
 * one-Saturday version, and it carries a `week_id` and a seat cap. One model,
 * one resource, and the `kind` badge is what tells them apart.
 *
 * The edit form reaches `name` and `member_cap` and nothing else on purpose.
 * `kind`, `flavor` and `week_id` are structural — changing any of them turns a
 * group into a different thing while people are sitting in it — and the mode
 * a group plays moves through `ChangeGroupMode`, which knows what a mid-season
 * change does to slates already published.
 */
class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = "Pick'em";

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(60),
            TextInput::make('member_cap')
                ->numeric()
                ->minValue(2)
                ->helperText('Seats. Blank means uncapped — a private group with no ceiling.'),

            // Nothing else. See the class docblock: kind/flavor/week are
            // structural, and the mode belongs to ChangeGroupMode.
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The group')->columns(2)->schema([
                TextEntry::make('code')->label('Invite code')->fontFamily('mono')->copyable(),
                TextEntry::make('kind')
                    ->formatStateUsing(fn (string $state): string => $state === Group::KIND_LOBBY
                        ? 'Lobby room'
                        : 'Private group'),
                TextEntry::make('flavor')
                    ->label('Flavor')
                    ->state(fn (Group $record): ?string => $record->flavorEnum()?->label())
                    ->placeholder('Standard'),
                TextEntry::make('member_cap')->label('Seats')->placeholder('Uncapped'),
                TextEntry::make('week.number')->label('Week')->placeholder('Season-long')
                    ->helperText('A room is one Saturday; a private group runs the season.'),
                TextEntry::make('filled_at')->label('Filled')->dateTime()->placeholder('Seats left'),
                TextEntry::make('created_at')->label('Created')->dateTime(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('kind')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Group::KIND_LOBBY ? 'Lobby' : 'Private')
                    ->color(fn (string $state): string => $state === Group::KIND_LOBBY ? 'gray' : 'info'),
                TextColumn::make('flavor')
                    ->label('Flavor')
                    // A retired flavor degrades to the placeholder rather than
                    // throwing on a room that still has a URL.
                    ->state(fn (Group $record): ?string => $record->flavorEnum()?->label())
                    ->placeholder('Standard'),
                TextColumn::make('code')
                    ->label('Invite code')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->copyable()
                    ->copyMessage('Code copied')
                    ->searchable(),
                TextColumn::make('members_count')->label('Members')->counts('members')->sortable(),
                TextColumn::make('contests_count')->label('Contests')->counts('contests')->sortable(),
                TextColumn::make('member_cap')->label('Cap')->placeholder('Uncapped')->toggleable(),
                TextColumn::make('week.number')->label('Week')->placeholder('Season-long')->toggleable(),
                TextColumn::make('filled_at')->label('Filled')->since()->color('gray')->placeholder('Seats left'),
                TextColumn::make('created_at')->label('Created')->since()->color('gray')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('kind')->options([
                    Group::KIND_PRIVATE => 'Private',
                    Group::KIND_LOBBY => 'Lobby',
                ]),
                SelectFilter::make('flavor')->options(collect(LobbyFlavor::cases())->mapWithKeys(
                    fn (LobbyFlavor $flavor): array => [$flavor->value => $flavor->label()],
                )),
                TernaryFilter::make('filled_at')
                    ->label('Filled')
                    ->nullable()
                    ->trueLabel('Full')
                    ->falseLabel('Seats left'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No groups yet');
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            ContestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            'view' => ViewGroup::route('/{record}'),
            'edit' => EditGroup::route('/{record}/edit'),
        ];

        // No 'create'. A group is made by a person through CreateGroup, which
        // seats them as commissioner, mints the invite code and opens the
        // season's contest — a hand-made row is a group nobody runs.
    }
}
