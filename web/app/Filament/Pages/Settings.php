<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\User;
use App\Rules\CertificatePin;
use App\Rules\ProviderEndpoint;
use App\Services\SettingsRepository;
use App\Stt\ProviderEndpoints;
use BackedEnum;
use Filament\Actions\Action;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Non-secret settings: retention, provider per use-case, provider options, pinning set for devices.
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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_super_admin;
    }

    public function mount(SettingsRepository $settings): void
    {
        $all = $settings->all();
        $this->form->fill([
            'retention_days' => $all['retention_days'],
            'provider_live_captions' => $all['provider.live_captions'],
            'provider_dictation' => $all['provider.dictation'],
            'provider_final_transcript' => $all['provider.final_transcript'],
            'provider_voice_note' => $all['provider.voice_note'],
            'captions_channels' => $all['captions.channels'],
            'default_language' => $all['default_language'],
            'display_timezone' => $settings->displayTimezone(),
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
        ]);
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
            $value = $stored[$provider][$useCase] ?? null;
            $state[$field] = (is_int($value) || is_float($value)) && $value >= 0 && $value <= 100
                ? $value
                : SettingsRepository::DEFAULTS['budget.prices'][$provider][$useCase];
        }

        return $state;
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
            Section::make('Retention & privacy')->columns(2)->schema([
                TextInput::make('retention_days')->label('Delete recordings & transcripts after (days)')
                    ->numeric()->minValue(1)->maxValue(365)->required()
                    ->helperText('Runs daily via model:prune. Default 30.'),
                TextInput::make('default_language')->label('Default language')->maxLength(16)
                    ->helperText('Empty = auto-detect (recommended for mixed-language calls).'),
                Select::make('display_timezone')->label('Time zone')
                    ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->searchable()->required()
                    ->helperText('Times in this console are shown in this zone, in 12-hour AM/PM format. Recordings are stored in UTC.'),
            ]),
            Section::make('Transcription engine per use-case')->description('Accuracy first. Keys live on API Credentials.')->columns(2)->schema([
                Select::make('provider_live_captions')->label(SttUseCase::LiveCaptions->getLabel())->options($streaming)->required(),
                Select::make('provider_dictation')->label(SttUseCase::Dictation->getLabel())->options($streaming)->required(),
                Select::make('provider_final_transcript')->label(SttUseCase::FinalTranscript->getLabel())->options($final)->required(),
                Select::make('provider_voice_note')->label(SttUseCase::VoiceNote->getLabel())->options($final)->required(),
                Select::make('captions_channels')->label('Live caption channels')->options([
                    'both' => 'Both (Me + Client)',
                    'client_only' => 'Client only (half the streaming cost)',
                    'off' => 'Off',
                ])->required(),
            ]),
            Section::make('Monthly limit')
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
            Section::make('AssemblyAI')->collapsible()->columns(2)->schema([
                Select::make('assemblyai_region')->label('Data region')->options(['us' => 'US (default)', 'eu' => 'EU'])->required(),
                Select::make('assemblyai_streaming_model')->label('Streaming model')->options([
                    'universal-3-6-pro' => 'Universal-3.6 Pro (most accurate)',
                    'universal-streaming-english' => 'Universal-Streaming English (budget)',
                    'universal-streaming-multilingual' => 'Universal-Streaming Multilingual (budget)',
                ])->required(),
                TagsInput::make('assemblyai_async_models')->label('Final transcript models (priority order)')->columnSpanFull(),
            ]),
            Section::make('ElevenLabs')->collapsible()->collapsed()->columns(2)->schema([
                TextInput::make('elevenlabs_base_url')->label('API base URL')->url()->required()->rule(new ProviderEndpoint('elevenlabs')),
                Select::make('elevenlabs_model_id')->label('Batch model')->options(['scribe_v2' => 'Scribe v2', 'scribe_v2_medical' => 'Scribe v2 Medical'])->required(),
            ]),
            Section::make('Azure Speech')->collapsible()->collapsed()->columns(2)->schema([
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
            Section::make('whisper.cpp (self-hosted)')->collapsible()->collapsed()->columns(3)->schema([
                TextInput::make('whisper_base_url')->label('whisper-server URL (HTTPS, behind auth proxy)')->url()
                    ->rule(new ProviderEndpoint('whisper'))->columnSpan(2),
                TextInput::make('whisper_chunk_seconds')->label('Slice length (s)')->numeric()->minValue(60)->maxValue(1800),
                Toggle::make('whisper_vad')->label('Server-side VAD'),
            ]),
            Section::make('Device security')->collapsible()->collapsed()->schema([
                TagsInput::make('app_pins')->label('Server certificate pins (SPKI SHA-256)')
                    ->placeholder('sha256/base64…')
                    ->nestedRecursiveRule(new CertificatePin)
                    ->helperText('Current + backup key pins sent to devices at pairing. Leave empty only for local development.'),
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

    public function save(SettingsRepository $settings): void
    {
        $state = $this->form->getState();
        $settings->setMany([
            'retention_days' => (int) $state['retention_days'],
            'provider.live_captions' => $state['provider_live_captions'],
            'provider.dictation' => $state['provider_dictation'],
            'provider.final_transcript' => $state['provider_final_transcript'],
            'provider.voice_note' => $state['provider_voice_note'],
            'captions.channels' => $state['captions_channels'],
            'default_language' => filled($state['default_language'] ?? null) ? trim((string) $state['default_language']) : null,
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
        ]);

        Notification::make()->title('Settings saved')->success()->send();
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
