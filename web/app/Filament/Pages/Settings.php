<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\User;
use App\Services\SettingsRepository;
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
        ]);
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
                TextInput::make('elevenlabs_base_url')->label('API base URL')->url()->required(),
                Select::make('elevenlabs_model_id')->label('Batch model')->options(['scribe_v2' => 'Scribe v2', 'scribe_v2_medical' => 'Scribe v2 Medical'])->required(),
            ]),
            Section::make('Azure Speech')->collapsible()->collapsed()->columns(2)->schema([
                TextInput::make('azure_region')->label('Region')->placeholder('centralindia'),
                TextInput::make('azure_endpoint')->label('Resource endpoint')->url()->placeholder('https://<resource>.cognitiveservices.azure.com'),
                Select::make('azure_model')->label('Model')->options([
                    'MAI-Transcribe-2' => 'MAI-Transcribe-2 (per-channel WAV/FLAC)',
                    'standard' => 'Standard fast transcription (stereo, Opus OK)',
                ])->required(),
            ]),
            Section::make('whisper.cpp (self-hosted)')->collapsible()->collapsed()->columns(3)->schema([
                TextInput::make('whisper_base_url')->label('whisper-server URL (HTTPS, behind auth proxy)')->url()->columnSpan(2),
                TextInput::make('whisper_chunk_seconds')->label('Slice length (s)')->numeric()->minValue(60)->maxValue(1800),
                Toggle::make('whisper_vad')->label('Server-side VAD'),
            ]),
            Section::make('Device security')->collapsible()->collapsed()->schema([
                TagsInput::make('app_pins')->label('Server certificate pins (SPKI SHA-256)')
                    ->placeholder('sha256/base64…')
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
        ]);

        Notification::make()->title('Settings saved')->success()->send();
    }
}
