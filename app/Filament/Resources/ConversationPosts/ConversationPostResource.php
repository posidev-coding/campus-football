<?php

namespace App\Filament\Resources\ConversationPosts;

use App\Actions\DeleteConversationPost;
use App\Exceptions\CannotModeratePost;
use App\Filament\Resources\ConversationPosts\Pages\ManageConversationPosts;
use App\Models\ConversationPost;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Moderation for the conversations — one surface across game, team and group
 * threads, which is what an admin actually needs and what no single product
 * screen provides.
 *
 * A conversation IS its (topic_type, topic_id) pair; there is no parent table.
 * The three topic types are morph-mapped aliases (`game`, `team`, `group`), so
 * the topic column matches on those strings rather than on class names.
 *
 * DELETE ONLY, and every delete rides `DeleteConversationPost` — never
 * `$post->delete()`. `conversation_posts` has no `updated_at` on purpose:
 * moderation removes a post, it never rewrites one, because an editable post
 * lets a quote be made to lie about what was said. The Action is also where
 * "who may moderate" lives, so the panel cannot become a fourth answer to it.
 */
class ConversationPostResource extends Resource
{
    protected static ?string $model = ConversationPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Conversations';

    protected static ?string $modelLabel = 'post';

    public static function form(Schema $schema): Schema
    {
        // A post is never edited. See the class docblock.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // The topic and author columns both read a relation, and lazy
            // loading is off — an unnamed one here is a 500.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['topic', 'user']))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->color('gray')->sortable(),
                TextColumn::make('user')
                    ->label('Who')
                    ->state(fn (ConversationPost $record): ?string => $record->user?->name)
                    ->placeholder('Deleted account')
                    ->weight('medium'),
                TextColumn::make('topic')
                    ->label('Where')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->state(fn (ConversationPost $record): ?string => self::topicLabel($record)),
                TextColumn::make('body')->wrap()->limit(140),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('topic_type')
                    ->label('Kind')
                    // The morph-map ALIASES, which is what the column holds —
                    // Relation::enforceMorphMap means an unmapped class throws
                    // on write, so these three are the whole vocabulary.
                    ->options([
                        'game' => 'Game',
                        'team' => 'Team',
                        'group' => 'Group',
                    ]),
            ])
            ->recordActions([self::deletePost()])
            ->toolbarActions([self::deleteSelected()])
            ->emptyStateHeading('Nothing has been said yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageConversationPosts::route('/'),
        ];
    }

    /** "Tennessee Volunteers", "The Vol Network" — whichever kind of thread. */
    public static function topicLabel(ConversationPost $record): ?string
    {
        $topic = $record->topic;

        if ($topic === null) {
            return null;
        }

        return match ($record->topic_type) {
            'game' => $topic->short_name ?? $topic->name,
            'team' => $topic->display_name,
            'group' => $topic->name,
            default => null,
        };
    }

    private static function deletePost(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The post is removed from the thread. Posts are never edited — an editable post lets a quote be made to lie about what was said.')
            ->action(function (ConversationPost $record): void {
                try {
                    // Through the Action, always: it owns the rule about who
                    // may moderate what.
                    app(DeleteConversationPost::class)->handle(auth()->user(), $record);
                } catch (CannotModeratePost) {
                    Notification::make()->danger()->title('You cannot moderate this post')->send();

                    return;
                }

                Notification::make()->success()->title('Post deleted')->send();
            });
    }

    private static function deleteSelected(): BulkAction
    {
        return BulkAction::make('delete')
            ->label('Delete selected')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $refused = 0;

                foreach ($records as $post) {
                    try {
                        // Per-post, through the same Action. A bulk
                        // `$query->delete()` would skip the moderation rule
                        // for every row at once.
                        app(DeleteConversationPost::class)->handle(auth()->user(), $post);
                    } catch (CannotModeratePost) {
                        $refused++;
                    }
                }

                $refused === 0
                    ? Notification::make()->success()->title('Posts deleted')->send()
                    : Notification::make()->warning()
                        ->title("{$refused} could not be moderated by you")
                        ->send();
            });
    }
}
