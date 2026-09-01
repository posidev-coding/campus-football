<?php

namespace App\Filament\Resources\WalletEntries;

use App\Actions\GrantWalletEntry;
use App\Filament\Resources\WalletEntries\Pages\ManageWalletEntries;
use App\Models\User;
use App\Models\WalletEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The wallet ledger, whole — an AUDIT surface, and append-only.
 *
 * There is deliberately no balance column anywhere in this app: totals are
 * SUMs over these rows, which is what stops a balance drifting from its own
 * history. That is also why nothing here edits or deletes. Correcting a
 * mistake means granting the opposite entry, which leaves both facts on the
 * record; an edit would silently move a number somebody already saw.
 */
class WalletEntryResource extends Resource
{
    protected static ?string $model = WalletEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Wallet ledger';

    protected static ?string $modelLabel = 'wallet entry';

    public static function form(Schema $schema): Schema
    {
        // Nothing is editable — see the class docblock.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->color('gray')->sortable(),
                TextColumn::make('user')
                    ->label('Who')
                    ->state(fn (WalletEntry $record): ?string => $record->user?->name)
                    ->placeholder('—')
                    ->weight('medium'),
                TextColumn::make('reason')->badge()->color('gray'),
                TextColumn::make('xp')
                    ->label('XP')
                    ->formatStateUsing(fn (int $state): string => sprintf('%+d', $state))
                    ->color(fn (int $state): string => $state >= 0 ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('credits')
                    ->label('Credits')
                    ->formatStateUsing(fn (int $state): string => sprintf('%+d', $state))
                    ->color(fn (int $state): string => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('key')
                    ->label('Idempotency key')
                    ->fontFamily('mono')
                    ->size('xs')
                    // Null is not missing data — it means a REPEATABLE entry,
                    // which is a real and different kind of row.
                    ->placeholder('Repeatable')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('reason')
                    ->options(fn (): array => WalletEntry::query()
                        ->distinct()
                        ->orderBy('reason')
                        ->pluck('reason', 'reason')
                        ->all()),
            ])
            ->headerActions([self::grant()])
            // No record actions and no bulk actions. Ever.
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing earned or spent yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWalletEntries::route('/'),
        ];
    }

    /**
     * A hand grant, through the one doorway every earn and spend uses.
     *
     * Never an insert: `GrantWalletEntry` is where the idempotency rule lives,
     * and a second writer is how that rule gets forgotten.
     */
    private static function grant(): Action
    {
        return Action::make('grant')
            ->label('Grant')
            ->icon(Heroicon::OutlinedGift)
            ->schema([
                Select::make('user_id')
                    ->label('Who')
                    ->required()
                    ->searchable()
                    // Real columns — `name` is an accessor and does not exist
                    // for MySQL to search.
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->where(fn (Builder $query) => $query
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('handle', 'like', "%{$search}%"))
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [$user->id => $user->name])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => User::find($value)?->name),
                TextInput::make('xp')->numeric()->default(0)->required()
                    ->helperText('Negative to take some back — the ledger records both directions.'),
                TextInput::make('credits')->numeric()->default(0)->required(),
                TextInput::make('reason')->required()->maxLength(40),
            ])
            ->action(function (array $data): void {
                $user = User::findOrFail($data['user_id']);

                // No key: a hand grant is a one-off by definition, and a keyed
                // one would no-op the second time an admin meant to give it.
                app(GrantWalletEntry::class)->handle(
                    $user,
                    (int) $data['xp'],
                    (int) $data['credits'],
                    $data['reason'],
                );

                Notification::make()->success()->title('Granted')->send();
            });
    }
}
