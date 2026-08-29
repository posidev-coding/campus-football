<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Group;
use App\Models\GroupMember;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Where this person plays, and whether they run any of it.
 *
 * Read-only: joining and leaving are product flows with their own guards
 * (JoinGroup checks the cap and the flag; LeaveGroup refuses to leave a group
 * without a runner), and a panel row that writes the pivot directly walks
 * around both.
 */
class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'groups';

    protected static ?string $title = 'Groups';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('medium'),
                TextColumn::make('kind')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Group::KIND_LOBBY ? 'Lobby' : 'Private')
                    ->color(fn (string $state): string => $state === Group::KIND_LOBBY ? 'gray' : 'info'),
                TextColumn::make('flavor')
                    ->label('Flavor')
                    // A room whose flavor no longer exists in the enum degrades
                    // to the placeholder rather than throwing — the same
                    // tolerance flavorEnum() has.
                    ->state(fn (Group $record): ?string => $record->flavorEnum()?->label())
                    ->placeholder('Standard'),
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->state(fn (Group $record): string => $record->pivot->role === GroupMember::COMMISSIONER
                        ? 'Commissioner'
                        : 'Member')
                    ->color(fn (Group $record): string => $record->pivot->role === GroupMember::COMMISSIONER
                        ? 'warning'
                        : 'gray'),
                TextColumn::make('joined')
                    ->label('Joined')
                    ->state(fn (Group $record) => $record->pivot->created_at)
                    ->since()
                    ->color('gray'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Not in any group')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
