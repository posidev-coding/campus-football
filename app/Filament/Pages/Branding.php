<?php

namespace App\Filament\Pages;

use App\Models\BrandSetting;
use App\Support\Brand;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The app's own brand, editable without a deploy.
 *
 * Everything here writes ONE row of overrides. A blank field means "use the
 * shipped default" — the files in public/brand and the constants on
 * App\Support\Brand, both of which are in git — so a partial change is safe,
 * Reset is a matter of nulling columns rather than restoring a fixture, and an
 * override whose uploaded file has gone missing degrades to the shipped brand
 * rather than to a broken image.
 *
 * Named "App Branding" because TeamResource already owns "Team Branding", which
 * is an entirely different thing: that one picks a header treatment for one of
 * 136 schools, this one is the product's own identity.
 *
 * Built from Filament's own components throughout. The panel does NOT load
 * resources/css/app.css, so a Tailwind utility written in an admin view has no
 * definition behind it — the preview below is inline styles for the same reason.
 *
 * @property-read Schema $form
 */
class Branding extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected string $view = 'filament.pages.branding';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?string $navigationLabel = 'App Branding';

    protected static ?string $title = 'App Branding';

    /**
     * The icon slots, in the order they matter: what a tab shows, what a phone
     * puts on its home screen, what a shared link renders as.
     *
     * @var array<string, array{label: string, hint: string, types: list<string>}>
     */
    private const ICONS = [
        'favicon-svg' => ['label' => 'Favicon (SVG)', 'hint' => 'Scalable. What most browsers actually use.', 'types' => ['image/svg+xml']],
        'favicon-16' => ['label' => 'Favicon 16px', 'hint' => 'Packed into /favicon.ico. Keep it to one stripe — two merge at this size.', 'types' => ['image/png']],
        'favicon-32' => ['label' => 'Favicon 32px', 'hint' => 'Packed into /favicon.ico, and the admin panel tab icon.', 'types' => ['image/png']],
        'favicon-48' => ['label' => 'Favicon 48px', 'hint' => 'Windows tiles and some feed readers.', 'types' => ['image/png']],
        'apple-touch' => ['label' => 'Apple touch icon 180px', 'hint' => 'The Add to Home Screen icon on iOS. Ship a full square — iOS applies its own squircle.', 'types' => ['image/png']],
        'icon-192' => ['label' => 'App icon 192px', 'hint' => 'Android home screen, via the manifest.', 'types' => ['image/png']],
        'icon-512' => ['label' => 'App icon 512px', 'hint' => 'Splash screens and install prompts.', 'types' => ['image/png']],
        'icon-maskable' => ['label' => 'Maskable icon 512px', 'hint' => 'Keep all content inside the middle 80% — Android crops this to its own shape.', 'types' => ['image/png']],
        'og-image' => ['label' => 'Share image 1200×630', 'hint' => 'What a pasted link renders as in a group chat.', 'types' => ['image/png', 'image/jpeg']],
        'mark-light' => ['label' => 'Mark — light mode (SVG)', 'hint' => 'Optional. Replaces the built-in pennant. Upload BOTH variants: a custom mark is drawn as an image and cannot recolor itself.', 'types' => ['image/svg+xml']],
        'mark-dark' => ['label' => 'Mark — dark mode (SVG)', 'hint' => 'Optional. The same mark drawn for a dark surface.', 'types' => ['image/svg+xml']],
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->getRecord()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Identity')
                        ->description('Blank means the shipped default. The placeholder shows what that is.')
                        ->schema([
                            TextInput::make('name')
                                ->label('Name')
                                ->placeholder(config('app.name'))
                                ->maxLength(60),
                            TextInput::make('short_name')
                                ->label('Short name')
                                ->helperText('The label under the icon once someone adds this to their home screen. About 12 characters before iOS truncates it.')
                                ->placeholder('Campus FB')
                                ->maxLength(30),
                            TextInput::make('tagline')
                                ->label('Tagline')
                                ->helperText('The meta description, and the subtitle on a shared link.')
                                ->placeholder(Brand::tagline())
                                ->maxLength(200),
                            TextInput::make('wordmark_lead')
                                ->label('Wordmark — lead line')
                                ->placeholder(Brand::WORDMARK['lead'])
                                ->live(onBlur: true)
                                ->maxLength(30),
                            TextInput::make('wordmark_main')
                                ->label('Wordmark — main line')
                                ->placeholder(Brand::WORDMARK['main'])
                                ->live(onBlur: true)
                                ->maxLength(30),
                        ])
                        ->columns(2),

                    Section::make('Colors')
                        ->description('Applied at runtime as CSS custom properties, so a change lands without a rebuild.')
                        ->schema([
                            ColorPicker::make('color_ink')
                                ->label('Ink')
                                ->helperText('The mark and wordmark in light mode.')
                                ->placeholder(Brand::COLORS['ink'])
                                ->live(onBlur: true)
                                ->hex(),
                            ColorPicker::make('color_cream')
                                ->label('Cream')
                                ->helperText('The same, in dark mode.')
                                ->placeholder(Brand::COLORS['cream'])
                                ->live(onBlur: true)
                                ->hex(),
                            ColorPicker::make('color_lager')
                                ->label('Lager')
                                ->helperText("The pennant's stripes, and this panel's own primary color.")
                                ->placeholder(Brand::COLORS['lager'])
                                ->live(onBlur: true)
                                ->hex(),
                        ])
                        ->columns(3),

                    Section::make('Preview')
                        ->description('The lockup as the app will draw it, on both surfaces.')
                        ->schema([
                            Placeholder::make('preview')
                                ->hiddenLabel()
                                ->content(fn (Get $get) => view('filament.brand-preview', [
                                    'ink' => $get('color_ink') ?: Brand::COLORS['ink'],
                                    'cream' => $get('color_cream') ?: Brand::COLORS['cream'],
                                    'lager' => $get('color_lager') ?: Brand::COLORS['lager'],
                                    'lead' => $get('wordmark_lead') ?: Brand::WORDMARK['lead'],
                                    'main' => $get('wordmark_main') ?: Brand::WORDMARK['main'],
                                ])),
                        ]),

                    Section::make('Icons')
                        ->description('Every slot falls back to the shipped file when empty. Uploads land on the public disk — on a deploy target with an ephemeral filesystem, treat these as a way to try a design and commit the winner into public/brand.')
                        ->schema(
                            collect(self::ICONS)
                                ->map(fn (array $icon, string $key): FileUpload => FileUpload::make("assets.{$key}")
                                    ->label($icon['label'])
                                    ->helperText($icon['hint'])
                                    ->disk('public')
                                    ->directory('brand')
                                    ->visibility('public')
                                    ->acceptedFileTypes($icon['types'])
                                    ->maxSize(2048))
                                ->values()
                                ->all()
                        )
                        ->columns(2)
                        ->collapsed(),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save brand')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->record($this->getRecord())
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        /*
         * Empty strings are not overrides. Left as '' they would be stored and
         * would then beat the shipped default — a cleared field has to mean
         * "go back to shipped", or the only way out of a bad value is a
         * database edit.
         */
        $data = collect($data)
            ->map(fn ($value) => $value === '' ? null : $value)
            ->all();

        $data['assets'] = collect($data['assets'] ?? [])->filter()->all() ?: null;

        $this->getRecord()->fill($data)->save();

        Notification::make()
            ->title('Brand saved')
            ->body('The tab icon, the home-screen icon and this panel all read the same values, so they moved together.')
            ->success()
            ->send();
    }

    public function getRecord(): BrandSetting
    {
        return BrandSetting::current();
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewManifest')
                ->label('View manifest')
                ->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->color('gray')
                ->url(route('manifest'), shouldOpenInNewTab: true),

            Action::make('reset')
                ->label('Reset to shipped')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Clears every override. The app goes back to the brand that ships in the repository — nothing is deleted from disk.')
                ->action(function (): void {
                    $record = $this->getRecord();

                    $record->forceFill(array_fill_keys([
                        'name', 'short_name', 'tagline', 'wordmark_lead', 'wordmark_main',
                        'color_ink', 'color_cream', 'color_lager', 'assets',
                    ], null))->save();

                    $this->form->fill($record->attributesToArray());

                    Notification::make()->title('Back to the shipped brand')->success()->send();
                }),
        ];
    }
}
