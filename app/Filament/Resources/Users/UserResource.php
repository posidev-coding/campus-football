<?php

namespace App\Filament\Resources\Users;

use App\Enums\ActivityArea;
use App\Enums\ActivityFeature;
use App\Enums\ContentRating;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\FollowedTeamsRelationManager;
use App\Filament\Resources\Users\RelationManagers\GroupsRelationManager;
use App\Filament\Resources\Users\RelationManagers\PicksRelationManager;
use App\Filament\Resources\Users\RelationManagers\WalletEntriesRelationManager;
use App\Models\User;
use App\Models\UserDay;
use App\Support\ActivityRollup;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * People, and the four powers an admin needs over an account: verify by hand,
 * grant or revoke admin, delete, and sign in as them.
 *
 * The template for every FULL resource in this panel — list, view and edit
 * pages, a parameterized heading partial, a KPI header widget, tabbed
 * infolist, and relation managers for what the record OWNS.
 *
 * Two things about this model that decide half the code below. `admin` is
 * deliberately not `#[Fillable]`, because it is a privilege escalation vector
 * the moment it reaches a mass-assignment path — so `forceFill` is the only
 * sanctioned write and there is no admin field on the edit form. And `name` is
 * an ACCESSOR over `first_name`/`last_name`, so every sort and search here
 * addresses the real columns; a `->searchable()` on `name` is a 1054.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'handle';

    /**
     * How far back the Activity tab looks — two pick'em weeks, so a strip
     * holds last Saturday and the one before it rather than a fortnight that
     * happens to cut one of them in half.
     */
    public const ACTIVITY_DAYS = 14;

    /** Real columns only — `name` does not exist to search. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'handle', 'email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->name;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')->required()->maxLength(50),
            TextInput::make('last_name')->required()->maxLength(50),
            TextInput::make('handle')->required()->maxLength(30)
                ->helperText('Lowercase letters, numbers and underscores.'),
            TextInput::make('email')->email()->required()->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(20)
                ->helperText('Changing this does NOT re-verify it — the stamp is the reader\'s own.'),
            Select::make('timezone')->options(fn (): array => collect(timezone_identifiers_list())
                ->mapWithKeys(fn (string $zone): array => [$zone => $zone])->all())
                ->searchable()->native(false),
            Select::make('content_rating')
                ->label('Content rating')
                ->options(collect(ContentRating::cases())->mapWithKeys(
                    fn (ContentRating $rating): array => [$rating->value => $rating->label().' — '.$rating->subLabel()],
                ))
                ->native(false)
                ->helperText('Which register the app talks to them in. Falls DOWN the ladder, never up.'),
            /*
             * NO ->visibility('public'): R2 rejects object ACLs outright, and
             * the upload fails with an error that reads like a credentials
             * problem. The disk is public by configuration instead.
             */
            FileUpload::make('avatar')
                ->image()
                ->disk(config('cfb.upload_disk'))
                ->directory('avatars')
                ->columnSpanFull(),
            Toggle::make('newsletter_opt_in')->label('Newsletter'),
            Toggle::make('pickem_notify_opt_in')->label("Pick'em nudges"),
            Toggle::make('sms_opt_in')->label('SMS')
                ->helperText('Consent only. Texting also needs a verified number.'),

            // `admin` is absent on purpose — see the class docblock. It moves
            // through the Toggle admin ACTION on the view page, which
            // forceFills it and cannot be reached by mass assignment.
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('Profile')->icon(Heroicon::OutlinedIdentification)->columns(2)->schema([
                    TextEntry::make('handle')->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('email')->copyable(),
                    TextEntry::make('email_verified_at')->label('Email verified')
                        ->dateTime()->placeholder('Never verified'),
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('phone_verified_at')->label('Phone verified')
                        ->dateTime()->placeholder('Not verified'),
                    TextEntry::make('timezone')->placeholder('—'),
                    TextEntry::make('content_rating')->label('Content rating')->badge()
                        ->formatStateUsing(fn (ContentRating $state): string => $state->label())
                        ->helperText(fn (ContentRating $state): string => $state->subLabel()),
                    TextEntry::make('created_at')->label('Joined')->dateTime(),
                ]),

                Tab::make('Wallet & play')->icon(Heroicon::OutlinedBolt)->columns(2)->schema([
                    TextEntry::make('xp')->label('XP')
                        ->state(fn (User $record): string => number_format($record->walletTotals()['xp'])),
                    TextEntry::make('credits')->label('Tallboys')
                        ->state(fn (User $record): string => number_format($record->walletTotals()['credits'])),
                    TextEntry::make('pick_record')->label('Pick record')
                        ->state(fn (User $record): string => self::pickRecord($record))
                        ->helperText('Wins-losses-pushes across every contest.'),
                    TextEntry::make('entries_won')->label('Slates won')
                        ->state(fn (User $record): int => $record->slateEntries()->where('won', true)->count()),
                    TextEntry::make('beat_bear')->label('Beat the Bear')
                        ->state(fn (User $record): int => $record->slateEntries()->where('beat_bear', true)->count())
                        ->helperText('Woodshed only — the Bear does not run in the other modes.'),
                ]),

                /*
                 * ATTENTION, and only ever at the resolution `user_days` holds
                 * it: a day, an area bitmask and a feature bitmask. No route
                 * name reaches this tab, and none is joined in to make it — the
                 * sensor keeps route-level rows for thirty days precisely so an
                 * error can be read against its own traffic, not so an admin
                 * can watch one person move through the app.
                 */
                Tab::make('Activity')->icon(Heroicon::OutlinedChartBar)->columns(2)->schema([
                    TextEntry::make('last_seen_at')->label('Last seen')
                        ->dateTime()->placeholder('Never')
                        ->helperText('The activity drain is this column\'s only writer, so it lags by at most one drain.'),
                    TextEntry::make('days_present')->label('Days present')
                        ->state(fn (User $record): string => self::daysPresent($record))
                        ->helperText('Out of the days the sensor was actually counting, which is not always '.self::ACTIVITY_DAYS.'.'),
                    ViewEntry::make('activity_strip')
                        ->label('The last '.self::ACTIVITY_DAYS.' days')
                        ->columnSpanFull()
                        ->view('filament.partials.activity-strip')
                        ->state(fn (User $record): array => self::activityStrip($record)),
                    TextEntry::make('features_seen')->label('Features used')
                        ->badge()
                        ->columnSpanFull()
                        ->state(fn (User $record): array => self::featuresSeen($record))
                        // An empty array is blank(), so this is what shows for
                        // somebody who read screens and did nothing else — which
                        // is a real and quite common shape of fortnight.
                        ->placeholder('Nothing in the last '.self::ACTIVITY_DAYS.' days')
                        ->helperText('Most of these are read from the truth tables at rollup, not from the clickstream.'),
                ]),

                Tab::make('Notifications')->icon(Heroicon::OutlinedBellAlert)->columns(2)->schema([
                    TextEntry::make('newsletter_opt_in')->label('Newsletter')->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'In' : 'Out')
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    TextEntry::make('pickem_notify_opt_in')->label("Pick'em nudges")->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'In' : 'Out')
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    TextEntry::make('sms_opt_in')->label('SMS consent')->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'In' : 'Out')
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    TextEntry::make('sms_opted_in_at')->label('SMS opted in')->dateTime()->placeholder('—'),
                    // The subscription IS the consent — there is no push
                    // column to read, because a row can only exist through a
                    // permission grant on a device.
                    TextEntry::make('push_devices')->label('Push devices')
                        ->state(fn (User $record): int => $record->pushSubscriptions()->count())
                        ->helperText('A grant on a device is the only way one of these exists.'),
                    TextEntry::make('unsubscribed_at')->label('Unsubscribed')->dateTime()->placeholder('—'),
                    TextEntry::make('verification_reminded_at')->label('Verify reminder sent')
                        ->dateTime()->placeholder('—'),
                ]),

                Tab::make('Lifecycle')->icon(Heroicon::OutlinedClock)->columns(2)->schema([
                    TextEntry::make('onboarded_at')->label('Onboarded')->dateTime()->placeholder('Never finished'),
                    TextEntry::make('tour_completed_at')->label('Tour')->dateTime()->placeholder('Not taken'),
                    TextEntry::make('standalone_seen_at')->label('Ran standalone')
                        ->dateTime()->placeholder('Never')
                        ->helperText('The closest server-visible proxy for a home-screen icon. It only ratchets on.'),
                    TextEntry::make('prune_clock')->label('Prune clock')
                        ->columnSpanFull()
                        ->badge()
                        ->color('warning')
                        // Only while it is a live fact. A prune warning on a
                        // verified account is noise that trains people to
                        // ignore the one that matters.
                        ->visible(fn (User $record): bool => ! $record->hasVerifiedEmail())
                        ->state(fn (User $record): string => self::pruneClock($record)),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->circular()
                    ->state(fn (User $record): ?string => $record->avatarUrl()),
                /*
                 * `name` is an accessor, so the column reads it through
                 * `state()` and sorts and searches the real columns behind it.
                 * A bare `->searchable()` here is a 1054 on a column MySQL has
                 * never heard of.
                 */
                TextColumn::make('name')
                    ->state(fn (User $record): string => $record->name)
                    ->weight('medium')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('last_name', $direction)
                        ->orderBy('first_name', $direction))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $query): Builder => $query
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"))),
                TextColumn::make('handle')->fontFamily('mono')->size('xs')->searchable()->placeholder('—'),
                TextColumn::make('email')
                    ->searchable()
                    ->icon(fn (User $record): ?string => $record->hasVerifiedEmail() ? 'heroicon-m-check-badge' : null)
                    ->iconColor('success')
                    ->tooltip(fn (User $record): string => $record->hasVerifiedEmail() ? 'Verified' : 'Not verified'),
                IconColumn::make('admin')->boolean()->sortable(),
                IconColumn::make('onboarded')
                    ->label('Onboarded')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->hasOnboarded()),
                IconColumn::make('installed')
                    ->label('Installed')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->hasInstalled()),
                TextColumn::make('content_rating')->label('Rating')->badge()
                    ->formatStateUsing(fn (ContentRating $state): string => $state->label())
                    ->toggleable(),
                /*
                 * Written by the activity drain and by nothing else, so it
                 * lags by at most one drain cadence — and it is NULL for
                 * everybody who has not been seen since the sensor shipped.
                 * "Never" rather than a dash, because the dash reads as a
                 * column that failed to load.
                 */
                TextColumn::make('last_seen_at')->label('Last seen')->since()->color('gray')
                    ->placeholder('Never')
                    ->tooltip(fn (User $record): ?string => $record->last_seen_at?->format('M j, Y g:ia'))
                    ->sortable(),
                TextColumn::make('created_at')->label('Joined')->since()->color('gray')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Nullable-attribute form: the question is "is the stamp
                // there", not "is a boolean true".
                TernaryFilter::make('email_verified_at')
                    ->label('Verified')
                    ->nullable()
                    ->trueLabel('Verified')
                    ->falseLabel('Not verified'),
                TernaryFilter::make('onboarded_at')
                    ->label('Onboarded')
                    ->nullable()
                    ->trueLabel('Finished onboarding')
                    ->falseLabel('Never finished'),
                TernaryFilter::make('admin')->label('Admin'),
                SelectFilter::make('content_rating')
                    ->label('Content rating')
                    ->options(collect(ContentRating::cases())->mapWithKeys(
                        fn (ContentRating $rating): array => [$rating->value => $rating->label()],
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            // No bulk delete. Deleting an account cascades through picks,
            // entries, memberships, follows and the wallet — that is a
            // decision made one person at a time, with the cascade spelled out
            // in front of you.
            ->toolbarActions([])
            ->emptyStateHeading('Nobody has signed up yet');
    }

    public static function getRelations(): array
    {
        return [
            FollowedTeamsRelationManager::class,
            GroupsRelationManager::class,
            PicksRelationManager::class,
            WalletEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];

        // No 'create'. An account is made by REGISTERING — a hand-made row
        // would skip password hashing rules, the welcome mail and the whole
        // onboarding moment.
    }

    /**
     * The last {@see ACTIVITY_DAYS} league days, one cell each.
     *
     * TWO KINDS OF EMPTY, AND THEY ARE NOT THE SAME CELL. A day the sensor
     * was counting and this person did nothing is a fact about them. A day
     * BEFORE the sensor was counting is a fact about us, and rendering it as
     * a quiet day would draw everybody who joined before analytics shipped as
     * having ignored the app — the `funnel_since` rule, applied to one person
     * instead of to a funnel.
     *
     * There is no zero row in `user_days` for a quiet day and there must
     * never be one, so an absent day here carries no `views` at all rather
     * than a 0 this method made up.
     *
     * @return array{days: array<int, array<string, mixed>>, since: ?string, counted: int, present: int}
     */
    public static function activityStrip(User $record): array
    {
        $today = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();
        $from = $today->subDays(self::ACTIVITY_DAYS - 1);

        $since = app(ActivityRollup::class)->since();

        $rows = UserDay::query()
            ->where('user_id', $record->getKey())
            ->whereBetween('day', [$from->toDateString(), $today->toDateString()])
            ->get()
            ->keyBy(fn (UserDay $day): string => $day->day->toDateString());

        $days = [];
        $counted = 0;

        for ($offset = 0; $offset < self::ACTIVITY_DAYS; $offset++) {
            $date = $from->addDays($offset);
            $key = $date->toDateString();
            $row = $rows->get($key);

            $isCounted = $since !== null && $key >= $since;
            $counted += $isCounted ? 1 : 0;

            $days[] = [
                'date' => $key,
                'weekday' => $date->format('D'),
                'number' => $date->format('j'),
                'counted' => $isCounted,
                'views' => $row?->views,
                'actions' => $row?->actions,
                'areas' => $row === null ? [] : self::areaLabels($row->areas),
                'features' => $row === null ? [] : self::featureLabels($row->features),
            ];
        }

        return [
            'days' => $days,
            'since' => $since,
            'counted' => $counted,
            'present' => $rows->count(),
        ];
    }

    /**
     * "4 of 14 days" — over the days that were COUNTED, never over the
     * nominal fortnight.
     *
     * A denominator of 14 on a sensor that shipped on Tuesday reads as
     * somebody who ignored the app for a week and a half, which is a claim
     * about a stretch of time nothing was watching.
     */
    public static function daysPresent(User $record): string
    {
        $strip = self::activityStrip($record);

        if ($strip['counted'] === 0) {
            return 'Nothing counted yet';
        }

        return $strip['present'].' of '.$strip['counted'].' '.str('day')->plural($strip['counted']);
    }

    /**
     * Every feature bit this person lit in the window, as labels.
     *
     * @return array<int, string>
     */
    public static function featuresSeen(User $record): array
    {
        $today = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();

        // BIT_OR over the fortnight: the union of what they did, not the last
        // day's mask. Read off the query builder rather than through `value()`,
        // which re-selects the column list and cannot carry an aggregate.
        $row = DB::table('user_days')
            ->where('user_id', $record->getKey())
            ->whereBetween('day', [
                $today->subDays(self::ACTIVITY_DAYS - 1)->toDateString(),
                $today->toDateString(),
            ])
            ->selectRaw('coalesce(bit_or(features), 0) as mask')
            ->first();

        return self::featureLabels((int) ($row->mask ?? 0));
    }

    /**
     * @return array<int, string>
     */
    private static function areaLabels(int $mask): array
    {
        return collect(ActivityArea::cases())
            ->filter(fn (ActivityArea $area): bool => $area->in($mask))
            ->map(fn (ActivityArea $area): string => $area->label())
            ->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private static function featureLabels(int $mask): array
    {
        return collect(ActivityFeature::cases())
            ->filter(fn (ActivityFeature $feature): bool => $feature->in($mask))
            ->map(fn (ActivityFeature $feature): string => $feature->label())
            ->values()->all();
    }

    /** "12-4-1", or a plain statement when nothing has been graded. */
    public static function pickRecord(User $record): string
    {
        $counts = $record->picks()
            ->selectRaw('result, count(*) as total')
            ->whereNotNull('result')
            ->groupBy('result')
            ->pluck('total', 'result');

        if ($counts->isEmpty()) {
            return 'Nothing graded yet';
        }

        return ($counts['win'] ?? 0).'-'.($counts['loss'] ?? 0).'-'.($counts['push'] ?? 0);
    }

    /**
     * How long an unverified account has before `model:prune` collects it.
     *
     * Never a countdown that can go negative in the copy: past the window the
     * honest statement is that it is due, and the reminder precondition means
     * an unwarned account is not actually collectable yet.
     */
    private static function pruneClock(User $record): string
    {
        $due = $record->created_at?->addDays(User::VERIFICATION_GRACE_DAYS);

        if ($due === null) {
            return 'Unverified';
        }

        if ($record->verification_reminded_at === null) {
            return 'Unverified — not yet warned, so not yet collectable';
        }

        return $due->isPast()
            ? 'Unverified and past the '.User::VERIFICATION_GRACE_DAYS.'-day grace — due for pruning'
            : 'Unverified — pruned '.$due->diffForHumans();
    }
}
