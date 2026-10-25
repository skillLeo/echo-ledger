<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Filament\Resources\Devices\DeviceResource;
use App\Models\User;
use App\Rules\CertificatePin;
use App\Rules\ProviderEndpoint;
use App\Services\RetentionPolicy;
use App\Services\SessionMarkers;
use App\Services\SettingsRepository;
use App\Services\SettingsSearch;
use App\Services\SettingsTransfer;
use App\Services\TextReplacements;
use App\Stt\KeytermPolicy;
use App\Stt\LanguageChoice;
use App\Stt\ProviderEndpoints;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Non-secret settings, grouped like the desktop app and the phone (plan B19): General, Recording, Captions, Dictation,
 * Privacy & security, Devices, Storage and About. A search box finds a setting by name or keyword; the settings can be
 * exported to, and imported from, a JSON file (never the API credentials).
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** The open group (a key of SettingsSearch::GROUPS); a search jumps to the first group with a match. */
    public string $activeTab = 'general';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_super_admin;
    }

    public function mount(SettingsRepository $settings): void
    {
        $this->form->fill(self::formState($settings->all()));
    }

    /**
     * The form values for a set of settings (the stored ones, or the stored ones with an import on top).
     *
     * @param  array<string, mixed>  $all
     * @return array<string, mixed>
     */
    public static function formState(array $all): array
    {
        $zone = $all['display.timezone'] ?? 'UTC';

        return [
            'retention_days' => $all['retention_days'],
            'retention_preset' => in_array((int) $all['retention_days'], RetentionPolicy::PRESETS, true) ? (string) $all['retention_days'] : 'custom',
            'provider_live_captions' => $all['provider.live_captions'],
            'provider_dictation' => $all['provider.dictation'],
            'provider_final_transcript' => $all['provider.final_transcript'],
            'provider_voice_note' => $all['provider.voice_note'],
            'captions_channels' => $all['captions.channels'],
            'default_language' => LanguageChoice::normalize($all['default_language']) ?? '',
            'vocabulary_keyterms' => KeytermPolicy::sanitize((array) ($all['vocabulary.keyterms'] ?? [])),
            'vocabulary_replacements' => TextReplacements::rules($all['vocabulary.replacements'] ?? []),
            'display_timezone' => is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC',
            'marker_tags' => SessionMarkers::presets($all['markers.tags'] ?? SessionMarkers::DEFAULT_TAGS),
            'assemblyai_region' => $all['assemblyai.region'],
            'assemblyai_streaming_model' => $all['assemblyai.streaming_model'],
            'assemblyai_async_models' => $all['assemblyai.async_models'],
            'elevenlabs_base_url' => $all['elevenlabs.base_url'],
            'elevenlabs_model_id' => $all['elevenlabs.model_id'],
            'azure_region' => $all['azure.region'],
            'azure_endpoint' => $all['azure.endpoint'],
            'azure_model' => $all['azure.model'],
            'whisper_base_url' => $all['whisper.base_url'],
            'whisper_chunk_seconds' => $all['whisper.chunk_seconds'],
            'whisper_vad' => (bool) $all['whisper.vad'],
            'app_pins' => $all['app.pins'],
            ...self::budgetFormState($all),
        ];
    }

    /**
     * One price input per (provider, use case) pair of the default table: field name => [provider, use case, label].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function priceFields(): array
    {
        $providers = ['assemblyai' => 'AssemblyAI', 'elevenlabs' => 'ElevenLabs', 'azure_mai' => 'Azure MAI'];
        $useCases = ['live_captions' => 'Live captions', 'dictation' => 'Dictation', 'final_transcript' => 'Final transcript', 'voice_note' => 'Voice notes'];
        $fields = [];
        foreach (SettingsRepository::DEFAULTS['budget.prices'] as $provider => $pairs) {
            foreach (array_keys($pairs) as $useCase) {
                $fields["price__{$provider}__{$useCase}"] = [$provider, $useCase, $providers[$provider].' · '.$useCases[$useCase]];
            }
        }

        return $fields;
    }

    /**
     * Form state for the monthly limit section; stored values that are not usable fall back to the defaults.
     *
     * @param  array<string, mixed>  $all
     * @return array<string, mixed>
     */
    private static function budgetFormState(array $all): array
    {
        $minutes = $all['budget.monthly_minutes'] ?? null;
        $usd = $all['budget.monthly_usd'] ?? null;
        $warn = $all['budget.warn_percent'] ?? null;
        $state = [
            'monthly_minutes' => is_int($minutes) && $minutes >= 1 ? $minutes : null,
            'monthly_usd' => (is_int($usd) || is_float($usd)) && $usd >= 0.01 ? $usd : null,
            'warn_percent' => is_int($warn) && $warn >= 1 && $warn <= 100 ? $warn : 80,
        ];
        $stored = is_array($all['budget.prices'] ?? null) ? $all['budget.prices'] : [];
        foreach (self::priceFields() as $field => [$provider, $useCase]) {
            $value = is_array($stored[$provider] ?? null) ? ($stored[$provider][$useCase] ?? null) : null;
            $state[$field] = (is_int($value) || is_float($value)) && $value >= 0 && $value <= 100
                ? $value
                : SettingsRepository::DEFAULTS['budget.prices'][$provider][$useCase];
        }

        return $state;
    }

    /** A settings section that hides itself when the search box has words that it does not match. */
    private function section(string $id): Section
    {
        return Section::make(SettingsSearch::SECTIONS[$id][1])
            ->extraAttributes(
                fn (Get $get): array => SettingsSearch::matches($get('settings_search'), $id) ? [] : ['hidden' => true, 'style' => 'display: none'],
                merge: true,
            );
    }

    /** @param  list<Section>  $sections */
    private function tab(string $group, array $sections): Tab
    {
        return Tab::make(SettingsSearch::GROUPS[$group])
            ->badge(fn (Get $get): ?int => SettingsSearch::count($get('settings_search'), $group) ?: null)
            ->schema($sections);
    }

    public function form(Schema $schema): Schema
    {
        $streaming = [
            SttProviderKey::AssemblyAi->value => SttProviderKey::AssemblyAi->getLabel(),
            SttProviderKey::ElevenLabs->value => SttProviderKey::ElevenLabs->getLabel(),
            SttProviderKey::AzureMai->value => SttProviderKey::AzureMai->getLabel(),
        ];
        $final = SttProviderKey::class;

        return $schema->statePath('data')->components([
            TextInput::make('settings_search')->hiddenLabel()->placeholder('Search settings')
                ->prefixIcon(Heroicon::OutlinedMagnifyingGlass)->extraInputAttributes(['aria-label' => 'Search settings'])
                ->live(debounce: 250)->dehydrated(false)
                ->afterStateUpdated(function (?string $state): void {
                    $group = SettingsSearch::firstGroup($state);
                    if ($group !== null) {
                        $this->activeTab = $group;
                    }
                }),
            Tabs::make('Settings')->livewireProperty('activeTab')->tabs([
                'general' => $this->tab('general', [
                    $this->section('language')->columns(2)->schema([
                        Select::make('default_language')->label('Default language')->options(LanguageChoice::OFFERED)->selectablePlaceholder(false)
                            ->helperText('Automatic suits most calls. “English + Urdu” is for calls that switch between the two. A client or recording can choose its own.'),
                        Select::make('display_timezone')->label('Time zone')
                            ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()->required()
                            ->helperText('Times in this console are shown in this zone, in 12-hour AM/PM format. Recordings are stored in UTC.'),
                    ]),
                    $this->section('vocabulary')
                        ->description('Words the engines should know, and corrections made to every new final transcript. Each client can add its own on its page.')
                        ->schema([
                            TagsInput::make('vocabulary_keyterms')->label('Keyterms for every recording')
                                ->placeholder('Add a name, product or term')
                                ->helperText('Up to 200 terms of at most 50 characters and 5 words. The client’s own keyterms come first; each engine takes as many as it accepts.')
                                ->nestedRecursiveRules(['string', 'max:50', fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (($message = KeytermPolicy::validate((string) $value)) !== null) {
                                        $fail($message);
                                    }
                                }])
                                ->rules(['array', 'max:200']),
                            Repeater::make('vocabulary_replacements')->label('Always write')
                                ->schema([
                                    TextInput::make('from')->label('When the transcript says')->required()->maxLength(TextReplacements::MAX_CHARS),
                                    TextInput::make('to')->label('Write instead')->maxLength(TextReplacements::MAX_CHARS),
                                    Toggle::make('whole_word')->label('Whole words only')->default(true)->inline(false),
                                    Toggle::make('match_case')->label('Match case')->inline(false),
                                ])
                                ->columns(4)->defaultItems(0)->maxItems(TextReplacements::MAX_RULES)->addActionLabel('Add a correction')
                                ->helperText('Applied to every new final transcript; the original stays in the transcript history and can be restored.'),
                        ]),
                ]),
                'recording' => $this->tab('recording', [
                    $this->section('engine')->description('Accuracy first. Keys live on API Credentials.')->columns(2)->schema([
                        Select::make('provider_final_transcript')->label(SttUseCase::FinalTranscript->getLabel())->options($final)->required(),
                        Select::make('provider_voice_note')->label(SttUseCase::VoiceNote->getLabel())->options($final)->required(),
                    ]),
                    $this->section('bookmarks')
                        ->description('One-tap tags offered while recording a call (on the desktop pill and the phone notification).')
                        ->schema([
                            TagsInput::make('marker_tags')->label('Bookmark tags')
                                ->placeholder('Add a tag')
                                ->helperText('Up to 12 tags of at most 40 characters. Leave empty for the defaults.')
                                ->nestedRecursiveRules(['string', 'max:40'])
                                ->rules(['array', 'max:12']),
                        ]),
                    $this->section('assemblyai')->collapsible()->columns(2)->schema([
                        Select::make('assemblyai_region')->label('Data region')->options(['us' => 'US (default)', 'eu' => 'EU'])->required(),
                        Select::make('assemblyai_streaming_model')->label('Streaming model')->options([
                            'universal-3-6-pro' => 'Universal-3.6 Pro (most accurate)',
                            'universal-streaming-english' => 'Universal-Streaming English (budget)',
                            'universal-streaming-multilingual' => 'Universal-Streaming Multilingual (budget)',
                        ])->required(),
                        TagsInput::make('assemblyai_async_models')->label('Final transcript models (priority order)')->columnSpanFull(),
                    ]),
                    $this->section('elevenlabs')->collapsible()->collapsed()->columns(2)->schema([
                        TextInput::make('elevenlabs_base_url')->label('API base URL')->url()->required()->rule(new ProviderEndpoint('elevenlabs')),
                        Select::make('elevenlabs_model_id')->label('Batch model')->options(['scribe_v2' => 'Scribe v2', 'scribe_v2_medical' => 'Scribe v2 Medical'])->required(),
                    ]),
                    $this->section('azure')->collapsible()->collapsed()->columns(2)->schema([
                        TextInput::make('azure_region')->label('Region')->placeholder('centralindia')
                            ->regex(ProviderEndpoints::AZURE_REGION_PATTERN)
                            ->validationMessages(['regex' => ProviderEndpoints::AZURE_REGION_MESSAGE]),
                        TextInput::make('azure_endpoint')->label('Resource endpoint')->url()->rule(new ProviderEndpoint('azure'))
                            ->placeholder('https://<resource>.cognitiveservices.azure.com'),
                        Select::make('azure_model')->label('Model')->options([
                            'MAI-Transcribe-2' => 'MAI-Transcribe-2 (per-channel WAV/FLAC)',
                            'standard' => 'Standard fast transcription (stereo, Opus OK)',
                        ])->required(),
                    ]),
                    $this->section('whisper')->collapsible()->collapsed()->columns(3)->schema([
                        TextInput::make('whisper_base_url')->label('whisper-server URL (HTTPS, behind auth proxy)')->url()
                            ->rule(new ProviderEndpoint('whisper'))->columnSpan(2),
                        TextInput::make('whisper_chunk_seconds')->label('Slice length (s)')->numeric()->minValue(60)->maxValue(1800),
                        Toggle::make('whisper_vad')->label('Server-side VAD'),
                    ]),
                ]),
                'captions' => $this->tab('captions', [
                    $this->section('captions')->columns(2)->schema([
                        Select::make('provider_live_captions')->label(SttUseCase::LiveCaptions->getLabel())->options($streaming)->required(),
                        Select::make('captions_channels')->label('Live caption channels')->options([
                            'both' => 'Both (Me + Client)',
                            'client_only' => 'Client only (half the streaming cost)',
                            'off' => 'Off',
                        ])->required(),
                    ]),
                ]),
                'dictation' => $this->tab('dictation', [
                    $this->section('dictation')->schema([
                        Select::make('provider_dictation')->label(SttUseCase::Dictation->getLabel())->options($streaming)->required(),
                    ]),
                ]),
                'privacy' => $this->tab('privacy', [
                    $this->section('access')->description('Two-factor sign-in, passkeys, signed-in browsers and the audit log.')->schema([
                        Actions::make([Action::make('openSecurity')->label('Open Security')->color('gray')->url(fn (): string => Security::getUrl())]),
                    ]),
                    $this->section('pins')->schema([
                        TagsInput::make('app_pins')->label('Server certificate pins (SPKI SHA-256)')
                            ->placeholder('sha256/base64…')
                            ->nestedRecursiveRule(new CertificatePin)
                            ->helperText('Current + backup key pins sent to devices at pairing. Leave empty only for local development.'),
                    ]),
                ]),
                'devices' => $this->tab('devices', [
                    $this->section('devices')->description('Pair a phone or computer, see when it was last seen and revoke it.')->schema([
                        Actions::make([Action::make('openDevices')->label('Open Devices')->color('gray')->url(fn (): string => DeviceResource::getUrl())]),
                    ]),
                ]),
                'storage' => $this->tab('storage', [
                    $this->section('retention')->columns(2)->schema([
                        Select::make('retention_preset')->label('Delete recordings & transcripts after')->live()->dehydrated(false)->selectablePlaceholder(false)
                            ->options(['7' => '7 days', '14' => '14 days', '30' => '30 days', 'custom' => 'Custom'])
                            ->afterStateUpdated(fn (?string $state, Set $set) => is_numeric($state) ? $set('retention_days', (int) $state) : null),
                        TextInput::make('retention_days')->label('Days')
                            ->numeric()->minValue(1)->maxValue(365)->required()
                            ->helperText('Older recordings go to the 48-hour undo bin, then are erased. Kept recordings and clients with their own setting are exempt.'),
                    ]),
                    $this->section('limit')
                        ->description('For the whole server, per calendar month in the time zone above. At the limit, devices show "Monthly transcription limit reached" and new transcripts wait. Leave a limit empty for none.')
                        ->columns(3)->schema([
                            TextInput::make('monthly_minutes')->label('Monthly limit (minutes)')
                                ->integer()->minValue(1)->nullable()
                                ->helperText('Billable provider minutes: audio minutes × channels.'),
                            TextInput::make('monthly_usd')->label('Monthly limit (US$)')
                                ->numeric()->minValue(0.01)->step(0.01)->nullable(),
                            TextInput::make('warn_percent')->label('Warn at (%)')
                                ->integer()->minValue(1)->maxValue(100)->default(80)->required(),
                            Section::make('Prices (US$ per audio hour per channel)')
                                ->description('Defaults follow the providers\' list prices. Edit them when you change model or plan.')
                                ->collapsible()->collapsed()->columns(2)->columnSpanFull()
                                ->schema(array_map(
                                    fn (string $field, array $pair): TextInput => TextInput::make($field)->label($pair[2])
                                        ->numeric()->minValue(0)->maxValue(100)->step(0.0001)->required(),
                                    array_keys(self::priceFields()),
                                    self::priceFields(),
                                )),
                        ]),
                ]),
                'about' => $this->tab('about', [
                    $this->section('about')
                        ->description('Export these settings to a file, or load them from one with Import settings at the top of the page. The file never holds API keys, webhook secrets or passwords.')
                        ->schema([]),
                ]),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save settings')->submit('save')->keyBindings(['mod+s'])])]),
        ]);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportSettings')->label('Export settings')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn (): StreamedResponse => $this->exportSettings()),
            Action::make('importSettings')->label('Import settings')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->modalHeading('Import settings')
                ->modalDescription('The values are checked like a normal save. If any is not allowed, nothing changes.')
                ->modalSubmitActionLabel('Import')
                ->schema([
                    FileUpload::make('file')->label('Settings file')->required()->storeFiles(false)
                        ->acceptedFileTypes(['application/json', 'text/json', 'text/plain'])->maxSize(256),
                ])
                ->action(fn (array $data) => $this->importSettings($data['file'] ?? null)),
        ];
    }

    public function exportSettings(): StreamedResponse
    {
        $document = app(SettingsTransfer::class)->export();

        return response()->streamDownload(
            function () use ($document): void {
                echo json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            },
            'EchoLedger console settings '.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /** Applies a settings file: its values are put in the form and saved only if the whole form is valid. */
    public function importSettings(mixed $file): void
    {
        $settings = app(SettingsRepository::class);
        try {
            $path = $file instanceof TemporaryUploadedFile ? $file->getRealPath() : false;
            $json = is_string($path) ? file_get_contents($path) : false;
            if ($json === false) {
                throw new InvalidArgumentException('The file could not be read.');
            }
            $incoming = app(SettingsTransfer::class)->read($json);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $previous = $this->form->getRawState();
        $state = self::formState(array_merge($settings->all(), $incoming));
        // A stored time zone that is no longer valid shows as UTC; a file that names one is refused instead.
        if (array_key_exists('display.timezone', $incoming)) {
            $state['display_timezone'] = $incoming['display.timezone'];
        }
        $this->form->fill([...$state, 'settings_search' => null]);
        try {
            $this->persist($settings);
        } catch (ValidationException) {
            $this->form->fill($previous);
            $this->resetErrorBag();
            Notification::make()->title('Nothing was imported')->body('The file has values this server does not accept.')->danger()->send();

            return;
        }

        Notification::make()->title('Settings imported')->success()->send();
    }

    public function save(SettingsRepository $settings): void
    {
        $this->persist($settings);

        Notification::make()->title('Settings saved')->success()->send();
    }

    private function persist(SettingsRepository $settings): void
    {
        $state = $this->form->getState();
        $settings->setMany([
            'retention_days' => (int) $state['retention_days'],
            'provider.live_captions' => $state['provider_live_captions'],
            'provider.dictation' => $state['provider_dictation'],
            'provider.final_transcript' => $state['provider_final_transcript'],
            'provider.voice_note' => $state['provider_voice_note'],
            'captions.channels' => $state['captions_channels'],
            'default_language' => LanguageChoice::normalize($state['default_language'] ?? null),
            'vocabulary.keyterms' => KeytermPolicy::sanitize((array) ($state['vocabulary_keyterms'] ?? [])),
            'vocabulary.replacements' => TextReplacements::rules(array_values((array) ($state['vocabulary_replacements'] ?? []))),
            'display.timezone' => in_array($state['display_timezone'] ?? null, timezone_identifiers_list(), true) ? $state['display_timezone'] : 'UTC',
            'assemblyai.region' => $state['assemblyai_region'],
            'assemblyai.streaming_model' => $state['assemblyai_streaming_model'],
            'assemblyai.async_models' => array_values(array_filter((array) ($state['assemblyai_async_models'] ?? []))) ?: ['universal-3-5-pro', 'universal-2'],
            'elevenlabs.base_url' => rtrim((string) $state['elevenlabs_base_url'], '/'),
            'elevenlabs.model_id' => $state['elevenlabs_model_id'],
            'azure.region' => $state['azure_region'] ?: null,
            'azure.endpoint' => filled($state['azure_endpoint'] ?? null) ? rtrim((string) $state['azure_endpoint'], '/') : null,
            'azure.model' => $state['azure_model'],
            'whisper.base_url' => filled($state['whisper_base_url'] ?? null) ? rtrim((string) $state['whisper_base_url'], '/') : null,
            'whisper.chunk_seconds' => (int) ($state['whisper_chunk_seconds'] ?: 600),
            'whisper.vad' => (bool) ($state['whisper_vad'] ?? true),
            'app.pins' => array_values(array_filter((array) ($state['app_pins'] ?? []))),
            'budget.monthly_minutes' => filled($state['monthly_minutes'] ?? null) ? (int) $state['monthly_minutes'] : null,
            'budget.monthly_usd' => filled($state['monthly_usd'] ?? null) ? round((float) $state['monthly_usd'], 2) : null,
            'budget.warn_percent' => filled($state['warn_percent'] ?? null) ? (int) $state['warn_percent'] : 80,
            'budget.prices' => self::pricesFromState($state),
            'markers.tags' => SessionMarkers::presets($state['marker_tags'] ?? []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, array<string, float>>
     */
    private static function pricesFromState(array $state): array
    {
        $prices = [];
        foreach (self::priceFields() as $field => [$provider, $useCase]) {
            $prices[$provider][$useCase] = round((float) ($state[$field] ?? 0), 4);
        }

        return $prices;
    }
}
