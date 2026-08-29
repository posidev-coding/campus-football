<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Actions\GrantWalletEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The wallet ledger, which is APPEND-ONLY.
 *
 * There is deliberately no balance column anywhere — totals are SUMs over
 * these rows — so an edit or a delete here would rewrite history and move a
 * total that somebody already saw. Correcting a mistake means granting the
 * opposite entry, which leaves both facts on the record.
 *
 * The Grant action rides `GrantWalletEntry` rather than inserting, because
 * that Action is the only place the idempotency rule lives.
 */
class WalletEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'walletEntries';

    protected static ?string $title = 'Wallet';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->color('gray')->sortable(),
                TextColumn::make('reason')->badge()->color('gray'),
                TextColumn::make('xp')
                    ->label('XP')
                    ->formatStateUsing(fn (int $state): string => sprintf('%+d', $state))
                    ->color(fn (int $state): string => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('lattes')
                    ->label('Lattes')
                    ->formatStateUsing(fn (int $state): string => sprintf('%+d', $state))
                    ->color(fn (int $state): string => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('key')
                    ->label('Idempotency key')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->placeholder('Repeatable')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nothing earned or spent yet')
            ->headerActions([$this->grant()])
            // No edit, no delete, ever. See the class docblock.
            ->recordActions([])
            ->toolbarActions([]);
    }

    private function grant(): Action
    {
        return Action::make('grant')
            ->label('Grant')
            ->icon(Heroicon::OutlinedGift)
            ->schema([
                TextInput::make('xp')->numeric()->default(0)->required()
                    ->helperText('Negative to take some back — the ledger records both directions.'),
                TextInput::make('lattes')->numeric()->default(0)->required(),
                TextInput::make('reason')->required()->maxLength(40)
                    ->helperText('What this was for. It shows on their wallet.'),
            ])
            ->action(function (array $data): void {
                // No key: a hand grant is a one-off by definition, and a keyed
                // one would silently no-op the second time an admin meant to
                // give it twice.
                app(GrantWalletEntry::class)->handle(
                    $this->getOwnerRecord(),
                    (int) $data['xp'],
                    (int) $data['lattes'],
                    $data['reason'],
                );

                Notification::make()->success()->title('Granted')->send();
            });
    }
}
