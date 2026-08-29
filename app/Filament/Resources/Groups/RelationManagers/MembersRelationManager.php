<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Actions\RemoveGroupMember;
use App\Exceptions\NotGroupCommissioner;
use App\Models\GroupMember;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who is in this group, and the one write this surface has.
 *
 * Removing rides `RemoveGroupMember`, which owns the rule that only a
 * commissioner may show somebody the door and that the commissioner cannot
 * remove themselves (that is LeaveGroup's, which knows a group must not lose
 * its runner). Its refusal is surfaced as a failure notification rather than
 * swallowed — an admin pressing Remove and seeing nothing happen would
 * reasonably press it again.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Members';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->circular()
                    ->state(fn (User $record): ?string => $record->avatarUrl()),
                TextColumn::make('name')
                    ->state(fn (User $record): string => $record->name)
                    ->weight('medium'),
                TextColumn::make('handle')->fontFamily('mono')->size('xs')->placeholder('—'),
                TextColumn::make('role')
                    ->badge()
                    ->state(fn (User $record): string => $record->pivot->role === GroupMember::COMMISSIONER
                        ? 'Commissioner'
                        : 'Member')
                    ->color(fn (User $record): string => $record->pivot->role === GroupMember::COMMISSIONER
                        ? 'warning'
                        : 'gray'),
                TextColumn::make('joined')
                    ->label('Joined')
                    ->state(fn (User $record) => $record->pivot->created_at)
                    ->since()
                    ->color('gray'),
            ])
            ->emptyStateHeading('Nobody has joined yet')
            ->headerActions([])
            ->recordActions([$this->remove()])
            ->toolbarActions([]);
    }

    private function remove(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Their picks and results stay on the record — removal ends the membership, it does not rewrite the season.')
            ->action(function (User $record): void {
                try {
                    // Through the Action, always: it is where "only a
                    // commissioner may do this" actually lives.
                    app(RemoveGroupMember::class)->handle(
                        auth()->user(),
                        $this->getOwnerRecord(),
                        $record,
                    );
                } catch (NotGroupCommissioner) {
                    // The admin does not run this group. Say so rather than
                    // failing quietly, which reads as a broken button.
                    Notification::make()->danger()
                        ->title('Only a commissioner of this group can remove somebody')
                        ->body('Removal is the commissioner\'s call, not the panel\'s.')
                        ->send();

                    return;
                }

                Notification::make()->success()->title('Removed')->send();
            });
    }
}
