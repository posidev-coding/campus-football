<?php

namespace App\Filament\Pages;

use App\Models\Group;
use App\Models\PickemSetting;
use App\Support\Cadence;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The league's clock, editable without a deploy — the App Branding
 * pattern: one row of overrides, blank means the shipped default on
 * App\Support\Cadence, and Reset is nulling columns.
 *
 * Two moments live here. The SLATE DEADLINE is when a commissioner's
 * unpublished board forfeits to the standard slate (historically Tuesday
 * midnight or Wednesday end-of-day, Eastern). The OFFICIAL FINAL is when a
 * week's results stop being preliminary — the stat-settling window that
 * lets ESPN's occasional day-after corrections land before a tiebreaker
 * pays the wrong person.
 *
 * @property-read Schema $form
 */
class PickemSettings extends Page
{
    protected string $view = 'filament.pages.pickem-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = "Pick'em Settings";

    protected static ?string $title = "Pick'em Settings";

    private const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
    ];

    private const RESULT_DAYS = [
        0 => 'Sunday',
        1 => 'Monday',
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(PickemSetting::current()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Slate deadline')
                        ->description('When an unpublished board gets the standard slate. Blank means the shipped default: Tuesday, end of day Eastern.')
                        ->schema([
                            Select::make('slate_deadline_dow')
                                ->label('Day')
                                ->options(self::WEEKDAYS)
                                ->placeholder(self::WEEKDAYS[Cadence::DEADLINE_DOW]),
                            TimePicker::make('slate_deadline_time')
                                ->label('Time (Eastern)')
                                ->seconds(false)
                                ->placeholder(substr(Cadence::DEADLINE_TIME, 0, 5)),
                        ])
                        ->columns(2),

                    Section::make('Official final')
                        ->description("When a week's results stop being preliminary — after ESPN's late stat corrections have had time to land. Blank means the shipped default: Sunday, noon Eastern.")
                        ->schema([
                            Select::make('official_final_dow')
                                ->label('Day')
                                ->options(self::RESULT_DAYS)
                                ->placeholder(self::RESULT_DAYS[Cadence::OFFICIAL_DOW]),
                            TimePicker::make('official_final_time')
                                ->label('Time (Eastern)')
                                ->seconds(false)
                                ->placeholder(substr(Cadence::OFFICIAL_TIME, 0, 5)),
                        ])
                        ->columns(2),

                    Section::make('Public rooms')
                        ->description('Seats in each transient public contest. When a room fills, the next one opens itself. Blank means the shipped default: '.Group::DEFAULT_LOBBY_CAP.'.')
                        ->schema([
                            TextInput::make('lobby_member_cap')
                                ->label('Seats per room')
                                ->numeric()
                                ->minValue(2)
                                ->maxValue(500)
                                ->placeholder((string) Group::DEFAULT_LOBBY_CAP),
                        ])
                        ->columns(2),

                    Actions::make([
                        Action::make('save')
                            ->label('Save')
                            ->action('save'),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        PickemSetting::current()->update($this->form->getState());

        Notification::make()
            ->success()
            ->title('League clock saved')
            ->send();
    }
}
