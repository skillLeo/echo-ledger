<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Filament\Resources\Devices\DeviceResource;
use App\Mail\TestMail;
use App\Models\User;
use App\Rules\CertificatePin;
use App\Rules\ProviderEndpoint;
use App\Services\Accounts\SignUp;
use App\Services\AuditLog;
use App\Services\CredentialVault;
use App\Services\EngineWait;
use App\Services\MailSettings;
use App\Services\RetentionPolicy;
use App\Services\SessionMarkers;
use App\Services\SettingsRepository;
use App\Services\SettingsSearch;
use App\Services\SettingsTransfer;
use App\Services\TextReplacements;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\PlanLimits;
use App\Stt\Drivers\GeminiDriver;
use App\Stt\GroqPacer;
use App\Stt\KeytermPolicy;
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use App\Stt\ProviderEndpoints;
use App\Support\UnsureMarkers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
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

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Cog6Tooth;

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

    /** Languages Live Translate hears and writes (the phone offers the same list). */
    private const LIVE_TRANSLATE_LANGUAGES = [
        'en' => 'English', 'ur' => 'Urdu', 'ar' => 'Arabic', 'hi' => 'Hindi', 'fa' => 'Persian', 'tr' => 'Turkish',
        'ru' => 'Russian', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'pt' => 'Portuguese', 'id' => 'Indonesian',
    ];

    private const LIVE_TRANSLATE_SPEEDS = ['fastest' => 'Fastest', 'balanced' => 'Balanced', 'accurate' => 'Accurate'];

    /** The Claude models offered for Show as. */
    private const CLAUDE_MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5 (best)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (cheaper)',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 (cheapest)',
    ];

    public function mount(SettingsRepository $settings): void
    {
        $this->form->fill([...self::formState($settings->all()), ...self::planState()]);
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
            ...self::routingFormState($all['routing.final'] ?? null),
            'urdu_wait_hours' => EngineWait::clampHours($all['routing.urdu_wait_hours'] ?? null),
            'captions_channels' => $all['captions.channels'],
            'default_language' => LanguageChoice::normalize($all['default_language']) ?? '',
            'languages_auto_more' => array_values(array_intersect(array_keys(LanguageChoice::MORE), (array) ($all['languages.auto_more'] ?? []))),
            'languages_auto_fallback' => LanguageChoice::fallback(),
            'double_check' => (bool) ($all['accuracy.double_check'] ?? false),
            'double_check_clients' => (bool) ($all['accuracy.double_check_clients'] ?? true),
            'client_ai_monthly_usd' => (float) ($all['client_ai.monthly_usd'] ?? 5.0),
            'claude_model' => (string) ($all['claude.model'] ?? 'claude-sonnet-5-5'),
            'screens_describe' => (bool) ($all['screens.describe'] ?? true),
            'live_translate_game_target' => (string) ($all['live_translate.game_target'] ?? 'en'),
            'live_translate_game_spoken' => array_values(array_intersect(array_keys(self::LIVE_TRANSLATE_LANGUAGES), (array) ($all['live_translate.game_spoken'] ?? ['ar', 'en']))),
            'live_translate_game_speed' => (string) ($all['live_translate.game_speed'] ?? 'fastest'),
            'live_translate_monthly_usd' => $all['live_translate.monthly_usd'] ?? 10.0,
            'live_translate_watch_target' => (string) ($all['live_translate.watch_target'] ?? 'ur'),
            'live_translate_watch_speed' => (string) ($all['live_translate.watch_speed'] ?? 'balanced'),
            'views_default_ur' => (string) ($all['views.default_ur'] ?? 'roman'),
            'views_default_en' => (string) ($all['views.default_en'] ?? 'original'),
            'views_default_ar' => (string) ($all['views.default_ar'] ?? 'original'),
            'unsure_style' => (string) ($all['unsure.style'] ?? 'brackets'),
            'unsure_threshold' => (float) ($all['unsure.threshold'] ?? 0.5),
            'unsure_note' => (bool) ($all['unsure.note'] ?? true),
            'vocabulary_keyterms' => KeytermPolicy::sanitize((array) ($all['vocabulary.keyterms'] ?? [])),
            'vocabulary_replacements' => TextReplacements::rules($all['vocabulary.replacements'] ?? []),
            'display_timezone' => is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC',
            'signup_mode' => isset(SignUp::MODES[$all['signup.mode'] ?? '']) ? $all['signup.mode'] : SignUp::NOBODY,
            'console_members' => (bool) ($all['console.members'] ?? true),
            'mail_host' => $all['mail.host'] ?? null,
            'mail_port' => (int) ($all['mail.port'] ?? 587) ?: 587,
            'mail_encryption' => isset(MailSettings::ENCRYPTIONS[$all['mail.encryption'] ?? '']) ? $all['mail.encryption'] : 'tls',
            'mail_username' => $all['mail.username'] ?? null,
            'mail_from_address' => $all['mail.from_address'] ?? null,
            'mail_from_name' => $all['mail.from_name'] ?? null,
            'marker_tags' => SessionMarkers::presets($all['markers.tags'] ?? SessionMarkers::DEFAULT_TAGS),
            'assemblyai_region' => $all['assemblyai.region'],
            'assemblyai_streaming_model' => $all['assemblyai.streaming_model'],
            'assemblyai_async_models' => $all['assemblyai.async_models'],
            'groq_plan' => isset(GroqPacer::PLANS[$all['groq.plan'] ?? '']) ? $all['groq.plan'] : GroqPacer::DEFAULT_PLAN,
            'gemini_model' => GeminiDriver::modelOr($all['gemini.model'] ?? null),
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
        $providers = ['assemblyai' => 'AssemblyAI', 'elevenlabs' => 'ElevenLabs', 'azure_mai' => 'Azure MAI', 'soniox' => 'Soniox', 'groq' => 'Groq Whisper', 'gemini' => 'Gemini'];
        $useCases = ['live_captions' => 'Live captions', 'dictation' => 'Dictation', 'final_transcript' => 'Final transcript', 'voice_note' => 'Voice notes', 'game_translate' => 'Game translate', 'watch_translate' => 'Watch translate'];
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
                        Select::make('default_language')->label('Default language')->options(LanguageChoice::selectOptions())->selectablePlaceholder(false)
                            ->helperText('Auto picks English, Urdu or Arabic. Urdu and Arabic always allow English words inside. A project or recording can choose its own.'),
                        CheckboxList::make('languages_auto_more')->label('Auto may also pick')->options(LanguageChoice::MORE)->columns(4)
                            ->helperText('English, Urdu and Arabic are always detected. Tick a language only if your calls use it.'),
                        Select::make('languages_auto_fallback')->label('When Auto hears another language, use')
                            ->options(array_diff_key(LanguageChoice::OFFERED, ['' => true]))->selectablePlaceholder(false)
                            ->helperText('The recording is transcribed again in this language (Hindi is always read as Urdu).'),
                        Select::make('display_timezone')->label('Time zone')
                            ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()->required()
                            ->helperText('Times in this console are shown in this zone, in 12-hour AM/PM format. Recordings are stored in UTC.'),
                    ]),
                    $this->section('vocabulary')
                        ->description('Words the engines should know, and corrections made to every new final transcript. Each project can add its own on its page.')
                        ->schema([
                            TagsInput::make('vocabulary_keyterms')->label('Keyterms for every recording')
                                ->placeholder('Add a name, product or term')
                                ->helperText('Up to 200 terms of at most 50 characters and 5 words. The project’s own keyterms come first; each engine takes as many as it accepts.')
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
                    $this->section('routing')
                        ->description('Which engine writes each language, in order: the first one with a key on API Credentials is used, the next ones are the fallback. Other covers the More languages; Auto is for recordings whose language is not chosen. Run “Compare engines” on a recording to choose.')
                        ->collapsible()->columns(self::ROUTE_SLOTS)->schema([
                            ...self::routingFields(),
                            TextInput::make('urdu_wait_hours')->label('Urdu waits for its best engine up to (hours)')->numeric()
                                ->minValue(0)->maxValue(EngineWait::MAX_HOURS)->step(0.5)->required()->columnSpan(2)
                                ->helperText('When the first engine of the Urdu or Auto list is busy (rate limited) or down, the recording waits for it and it is tried again (after 2, 4, 8, 16, then every 30 minutes) instead of going to a weaker engine. After this long the next engine writes it, marked "Backup engine". 0 hands it on at once, like the other languages. Default 6.'),
                            Toggle::make('double_check')->label('Double-check unsure lines with a second engine')->columnSpanFull()
                                ->helperText('Whisper on Groq (or ElevenLabs Scribe when Whisper wrote the transcript) listens again to up to 12 lines the first engine was not sure of. When they disagree, both are shown; the transcript is never changed. Each line is billed (Groq: at least 10 seconds).'),
                            Toggle::make('double_check_clients')->label('Always double-check recordings filed under a project')->columnSpan(2)
                                ->helperText('On by default: calls in a project are the ones you rely on, so their unsure lines get a second listen even with the switch above off.'),
                            TextInput::make('client_ai_monthly_usd')->label('AI budget for projects (US$ a month)')->numeric()->minValue(0)->maxValue(1000)->step(0.5)->required()->columnSpan(2)
                                ->helperText('Call notes, briefs, file reading and Ask stop for the month when this is used up. Default $5.'),
                            Select::make('claude_model')->label('Show as (Roman Urdu, اردو and English views)')->options(self::CLAUDE_MODELS)->required()->columnSpan(2)
                                ->helperText('The Claude model that writes the views (key on API Credentials → Anthropic). An hour of Urdu call costs about US$0.50 with Sonnet, about US$1 with Opus.'),
                            Select::make('views_default_ur')->label('Open Urdu calls as')->required()
                                ->options(['original' => 'Original (as transcribed)', 'roman' => 'Roman Urdu', 'urdu' => 'اردو', 'english' => 'English'])
                                ->helperText('Roman Urdu is made as soon as the transcript is ready; the original is always one tap away.'),
                            Select::make('views_default_en')->label('Open English calls as')->required()
                                ->options(['original' => 'Original (as transcribed)', 'urdu' => 'اردو (Urdu translation)']),
                            Select::make('views_default_ar')->label('Open Arabic calls as')->required()
                                ->options(['original' => 'Original (as transcribed)', 'english' => 'English', 'urdu' => 'اردو']),
                            Select::make('unsure_style')->label('Unsure words in copies and exports')->required()
                                ->options(['brackets' => '[?word]', 'suffix' => 'word(?)', 'off' => 'Off (plain text)'])
                                ->helperText('On screen they stay underlined. Applies to Copy, Copy for AI, exports and share links, in every view.'),
                            TextInput::make('unsure_threshold')->label('Unsure below confidence')->numeric()->minValue(0.05)->maxValue(0.95)->step(0.05)->required()
                                ->helperText('A word the engine scored lower than this is marked (0.5 by default).'),
                            Toggle::make('unsure_note')->label('Add the note line to copies and exports')
                                ->helperText('"Note: [?word] = the transcription is unsure about this word; [unclear] = inaudible audio." Copy for AI always adds it.'),
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
                    // Since v1.5: what the desktop keeps of a shared screen during a meeting.
                    $this->section('screens')
                        ->description('The desktop keeps what was on the shared screen at each moment of a meeting, encrypted with the recording and erased with it. A project can turn them off on its Settings.')
                        ->schema([
                            Toggle::make('screens_describe')->label('Describe screens')
                                ->helperText('Claude Haiku reads the text on each screen and writes one line about it (key on API Credentials → Anthropic), so screens are found by search and placed in Copy for AI and the meeting pack. Billed to AI usage: well under a tenth of a US cent a screen. Off: screens are kept without text.'),
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
                    $this->section('groq')->collapsible()->collapsed()->columns(2)->schema([
                        Select::make('groq_plan')->label('Groq plan')->options(GroqPacer::PLAN_LABELS)->required()->selectablePlaceholder(false)
                            ->helperText('Requests to Groq are paced under the plan\'s limits (Free: 20 requests a minute, 2 hours of audio an hour, 8 a day). The limits Groq reports in its answers always win. API Credentials → Groq shows this hour\'s and today\'s use.'),
                    ]),
                    $this->section('gemini')->collapsible()->collapsed()->columns(2)->schema([
                        TextInput::make('gemini_model')->label('Gemini model')->required()->maxLength(80)
                            ->regex(GeminiDriver::MODEL_PATTERN)->datalist(GeminiDriver::SUGGESTED_MODELS)
                            ->helperText('Writes final transcripts word for word (Urdu in Urdu letters, English in English) when it is on an engine list or chosen in Compare engines. Key on API Credentials → Google Gemini.'),
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
                    $this->section('signup')->description('New accounts get their own personal workspace and must confirm their email before they can sign in. People you add on the Workspace page always can.')->schema([
                        Select::make('signup_mode')->label('Who can sign up')->options(SignUp::SETTING_LABELS)->selectablePlaceholder(false)->required()
                            // Since v1.5 (Phase 1C): what On and Off mean for family and friends, in plain words.
                            ->helperText(SignUp::SETTING_HELP),
                        Toggle::make('console_members')->label('Members can use the console')
                            ->helperText('On: members and viewers open their own library, Shared with me, their profile and devices here, to read. Off: the console shows them the apps instead. Owners and admins always have it.'),
                    ]),
                    $this->section('pins')->schema([
                        TagsInput::make('app_pins')->label('Server certificate pins (SPKI SHA-256)')
                            ->placeholder('sha256/base64…')
                            ->nestedRecursiveRule(new CertificatePin)
                            ->helperText('Current + backup key pins sent to devices at sign-in. Leave empty only for local development.'),
                    ]),
                ]),
                'devices' => $this->tab('devices', [
                    $this->section('devices')->description('Sign in a phone or computer, see when it was last seen and revoke it.')->schema([
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
                            ->helperText('Older recordings go to the 48-hour undo bin, then are erased. Kept recordings and projects with their own setting are exempt.'),
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
                    $this->section('plan')
                        ->description(fn (): string => self::planDescription())
                        ->columns(3)->schema([
                            TextInput::make('plan_monthly_minutes')->label('Minutes per month')
                                ->integer()->minValue(1)->maxValue(10_000_000)->nullable()
                                ->helperText('Billable minutes of this workspace. Streaming tokens and new transcripts stop at the limit.'),
                            TextInput::make('plan_storage_gb')->label('Storage (GB)')
                                ->integer()->minValue(1)->maxValue(1_000_000)->nullable()
                                ->helperText('Encrypted recordings, the undo bin included. New uploads stop at the limit.'),
                            TextInput::make('plan_max_devices')->label('Signed-in devices')
                                ->integer()->minValue(1)->maxValue(10_000)->nullable()
                                ->helperText('New sign-ins stop at the limit.'),
                        ]),
                ]),
                // Since v1.3 (Phase B): the phone's Game and Watch translate panels start from these; the phone
                // streams to Soniox itself with a 10-minute key, so no audio reaches this server.
                'game' => $this->tab('game', [
                    $this->section('game')->columns(2)->schema([
                        Select::make('live_translate_game_target')->label('Translate game voice chat to')
                            ->options(self::LIVE_TRANSLATE_LANGUAGES)->required(),
                        CheckboxList::make('live_translate_game_spoken')->label('Teammates speak')
                            ->options(self::LIVE_TRANSLATE_LANGUAGES)->columns(3)
                            ->helperText('None ticked: any language.'),
                        Select::make('live_translate_game_speed')->label('Speed')->options(self::LIVE_TRANSLATE_SPEEDS)->required()
                            ->helperText('Fastest shows words soonest; Accurate waits a little longer for whole sentences.'),
                        TextInput::make('live_translate_monthly_usd')->label('Monthly Live Translate budget (US$)')->numeric()->minValue(0)->step(1)
                            ->helperText('Game and Watch translate together, about $0.18 an hour. The phone warns at 80 % and pauses at 100 %. Empty: no budget.'),
                    ]),
                ]),
                'watch' => $this->tab('watch', [
                    $this->section('watch')->columns(2)->schema([
                        Select::make('live_translate_watch_target')->label('Subtitles in')
                            ->options(self::LIVE_TRANSLATE_LANGUAGES)->required(),
                        Select::make('live_translate_watch_speed')->label('Speed')->options(self::LIVE_TRANSLATE_SPEEDS)->required(),
                    ]),
                ]),
                // Since v1.5: the SMTP server of every email (sign-up, password resets, invitations, sign-in alerts).
                'email' => $this->tab('email', [
                    $this->section('email')->columns(2)
                        ->description('Every email goes through this server: confirmations, password resets, invitations, sign-in alerts, exports. Empty server: the mailer of the server\'s .env file. The DNS records that keep these emails out of spam are in docs/EMAIL_DNS.md.')
                        ->schema([
                            TextInput::make('mail_host')->label('SMTP server')->placeholder('smtp.example.com')->maxLength(255)
                                ->regex('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/')->validationMessages(['regex' => 'Enter a host name such as smtp.example.com.']),
                            TextInput::make('mail_port')->label('Port')->integer()->minValue(1)->maxValue(65535)->required(),
                            Select::make('mail_encryption')->label('Encryption')->options(MailSettings::ENCRYPTIONS)->selectablePlaceholder(false)->required(),
                            TextInput::make('mail_username')->label('User')->maxLength(255)->autocomplete('off'),
                            TextInput::make('mail_password')->label('Password')->password()->maxLength(500)->autocomplete('new-password')
                                ->placeholder('Leave empty to keep it')
                                ->helperText(fn (): string => 'Stored encrypted like the API credentials, never shown again. Now: '.app(CredentialVault::class)->describe(...MailSettings::VAULT).'.'),
                            Toggle::make('mail_password_forget')->label('Remove the stored password')->default(false)
                                ->visible(fn (): bool => app(CredentialVault::class)->has(...MailSettings::VAULT)),
                            TextInput::make('mail_from_address')->label('From address')->email()->maxLength(255)->placeholder('echoledger@example.com')
                                ->helperText('Use an address of a domain whose DNS records allow this server (SPF, DKIM, DMARC).'),
                            TextInput::make('mail_from_name')->label('From name')->maxLength(100)->placeholder('EchoLedger'),
                            Actions::make([
                                Action::make('sendTestEmail')->label('Send a test email')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
                                    ->modalHeading('Send a test email')
                                    ->modalDescription('Sent at once with the values on this page, saved or not.')
                                    ->modalSubmitActionLabel('Send')
                                    ->schema([
                                        TextInput::make('to')->label('Send to')->email()->required()->maxLength(255)
                                            ->default(fn (): ?string => auth()->user()?->email),
                                    ])
                                    ->action(fn (array $data) => $this->sendTestEmail((string) ($data['to'] ?? ''))),
                            ])->key('smtp-actions')->columnSpanFull(),
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

    /**
     * Settings → Email → "Send a test email": sent at once through the SMTP values on the page (saved or not), so the
     * answer says whether they work. The SMTP server's own error is shown, without the password; 5 a minute.
     */
    public function sendTestEmail(string $to): void
    {
        if (RateLimiter::tooManyAttempts('test-email:'.auth()->id(), 5)) {
            Notification::make()->title('Too many test emails')->body('Wait a minute, then try again.')->danger()->send();

            return;
        }
        RateLimiter::hit('test-email:'.auth()->id(), 60);
        $state = $this->form->getRawState();
        $values = [
            'mail.host' => $state['mail_host'] ?? null,
            'mail.port' => $state['mail_port'] ?? null,
            'mail.encryption' => $state['mail_encryption'] ?? null,
            'mail.username' => $state['mail_username'] ?? null,
            'mail.from_address' => $state['mail_from_address'] ?? null,
            'mail.from_name' => $state['mail_from_name'] ?? null,
        ];
        $host = trim((string) $values['mail.host']);
        if ($host === '' || preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $host) !== 1 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            Notification::make()->title('Enter the SMTP server and an address first')->danger()->send();

            return;
        }
        $password = match (true) {
            filled($state['mail_password'] ?? null) => (string) $state['mail_password'],
            (bool) ($state['mail_password_forget'] ?? false) => null,
            default => app(CredentialVault::class)->get(...MailSettings::VAULT),
        };
        config(['mail.mailers.'.MailSettings::TEST_MAILER => MailSettings::mailerConfig($values, $password)]);
        $from = MailSettings::from($values, app(MailSettings::class)->envFrom());
        $mail = new TestMail($host);
        if (filled($from['address'])) {
            $mail->from((string) $from['address'], filled($from['name']) ? (string) $from['name'] : null);
        }
        try {
            Mail::mailer(MailSettings::TEST_MAILER)->to($to)->send($mail);
            app(AuditLog::class)->record('test_email_sent', (int) auth()->id(), null, "Test email sent through {$host}", ['host' => $host]);
            Notification::make()->title('Test email sent')
                ->body("Sent to {$to} through {$host}. If it does not arrive, look in the spam folder and check the DNS records in docs/EMAIL_DNS.md.")
                ->success()->send();
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (filled($password)) {
                $message = str_replace([$password, base64_encode($password)], '••••', $message);
            }
            Notification::make()->title('The test email was not sent')->body(Str::limit($message, 300, '…'))->danger()->persistent()->send();
        } finally {
            Mail::purge(MailSettings::TEST_MAILER);
            config(['mail.mailers.'.MailSettings::TEST_MAILER => null]);
        }
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
        $this->form->fill([...$state, ...self::planState(), 'settings_search' => null]);
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

    /** The languages that have an engine list, and their form field prefix. */
    private const ROUTED = ['en' => 'en', 'ur' => 'ur', 'ar' => 'ar', LanguageRouter::OTHER => 'other', LanguageRouter::AUTO => 'auto'];

    /** Engines per language in the form: room for every cloud engine (five since Gemini, v1.5). */
    private const ROUTE_SLOTS = 5;

    /** @return list<Select> the engines of each language, in order */
    private static function routingFields(): array
    {
        $engines = collect(SttProviderKey::cases())->reject(fn (SttProviderKey $k): bool => $k === SttProviderKey::ParakeetLocal)
            ->mapWithKeys(fn (SttProviderKey $k): array => [$k->value => $k->getLabel()])->all();
        $fields = [];
        foreach (self::ROUTED as $language => $slug) {
            foreach ([1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'] as $n => $order) {
                $field = Select::make("route_{$slug}_{$n}")->label(LanguageRouter::routeLabel($language).' · '.$order)->options($engines)->placeholder('—');
                $fields[] = $n === 1 ? $field->required() : $field;
            }
        }

        return $fields;
    }

    /** @return array<string, string|null> */
    private static function routingFormState(mixed $stored): array
    {
        $routes = LanguageRouter::merge($stored, LanguageRouter::DEFAULT_FINAL_ROUTES);
        $state = [];
        foreach (self::ROUTED as $language => $slug) {
            foreach (range(1, self::ROUTE_SLOTS) as $n) {
                $state["route_{$slug}_{$n}"] = $routes[$language][$n - 1] ?? null;
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, list<string>>
     */
    private static function routingFromState(array $state): array
    {
        $routes = [];
        foreach (self::ROUTED as $language => $slug) {
            $list = [];
            foreach (range(1, self::ROUTE_SLOTS) as $n) {
                $value = $state["route_{$slug}_{$n}"] ?? null;
                if (is_string($value) && SttProviderKey::tryFrom($value) !== null && ! in_array($value, $list, true)) {
                    $list[] = $value;
                }
            }
            $routes[$language] = $list !== [] ? $list : LanguageRouter::defaultsFor($language);
        }

        return $routes;
    }

    public function save(SettingsRepository $settings): void
    {
        $this->persist($settings);

        Notification::make()->title('Settings saved')->success()->send();
    }

    private function persist(SettingsRepository $settings): void
    {
        $state = $this->form->getState();
        $before = $settings->all();
        $settings->setMany([
            'retention_days' => (int) $state['retention_days'],
            'provider.live_captions' => $state['provider_live_captions'],
            'provider.dictation' => $state['provider_dictation'],
            'provider.final_transcript' => $state['provider_final_transcript'],
            'provider.voice_note' => $state['provider_voice_note'],
            'captions.channels' => $state['captions_channels'],
            'default_language' => LanguageChoice::normalize($state['default_language'] ?? null),
            'languages.auto_more' => array_values(array_intersect(array_keys(LanguageChoice::MORE), (array) ($state['languages_auto_more'] ?? []))),
            'languages.auto_fallback' => LanguageChoice::normalize($state['languages_auto_fallback'] ?? null) ?? 'ur',
            'accuracy.double_check' => (bool) ($state['double_check'] ?? false),
            'accuracy.double_check_clients' => (bool) ($state['double_check_clients'] ?? true),
            'client_ai.monthly_usd' => max(0.0, min(1000.0, (float) ($state['client_ai_monthly_usd'] ?? 5.0))),
            'claude.model' => in_array($state['claude_model'] ?? null, array_keys(self::CLAUDE_MODELS), true) ? $state['claude_model'] : 'claude-sonnet-5-5',
            'screens.describe' => (bool) ($state['screens_describe'] ?? true),
            'views.default_ur' => in_array($state['views_default_ur'] ?? null, ['original', 'roman', 'urdu', 'english'], true) ? $state['views_default_ur'] : 'roman',
            'views.default_en' => in_array($state['views_default_en'] ?? null, ['original', 'urdu'], true) ? $state['views_default_en'] : 'original',
            'views.default_ar' => in_array($state['views_default_ar'] ?? null, ['original', 'english', 'urdu'], true) ? $state['views_default_ar'] : 'original',
            'unsure.style' => in_array($state['unsure_style'] ?? null, UnsureMarkers::STYLES, true) ? $state['unsure_style'] : 'brackets',
            'unsure.threshold' => is_numeric($state['unsure_threshold'] ?? null) ? max(0.05, min(0.95, (float) $state['unsure_threshold'])) : 0.5,
            'unsure.note' => (bool) ($state['unsure_note'] ?? true),
            'vocabulary.keyterms' => KeytermPolicy::sanitize((array) ($state['vocabulary_keyterms'] ?? [])),
            'vocabulary.replacements' => TextReplacements::rules(array_values((array) ($state['vocabulary_replacements'] ?? []))),
            'display.timezone' => in_array($state['display_timezone'] ?? null, timezone_identifiers_list(), true) ? $state['display_timezone'] : 'UTC',
            'signup.mode' => isset(SignUp::MODES[$state['signup_mode'] ?? '']) ? $state['signup_mode'] : SignUp::NOBODY,
            'console.members' => (bool) ($state['console_members'] ?? true),
            'mail.host' => filled($state['mail_host'] ?? null) ? mb_strtolower(trim((string) $state['mail_host'])) : null,
            'mail.port' => (int) ($state['mail_port'] ?? 587) ?: 587,
            'mail.encryption' => isset(MailSettings::ENCRYPTIONS[$state['mail_encryption'] ?? '']) ? $state['mail_encryption'] : 'tls',
            'mail.username' => filled($state['mail_username'] ?? null) ? trim((string) $state['mail_username']) : null,
            'mail.from_address' => filled($state['mail_from_address'] ?? null) ? trim((string) $state['mail_from_address']) : null,
            'mail.from_name' => filled($state['mail_from_name'] ?? null) ? trim((string) $state['mail_from_name']) : null,
            'assemblyai.region' => $state['assemblyai_region'],
            'assemblyai.streaming_model' => $state['assemblyai_streaming_model'],
            'assemblyai.async_models' => array_values(array_filter((array) ($state['assemblyai_async_models'] ?? []))) ?: ['universal-3-5-pro', 'universal-2'],
            'groq.plan' => isset(GroqPacer::PLANS[$state['groq_plan'] ?? '']) ? $state['groq_plan'] : GroqPacer::DEFAULT_PLAN,
            'gemini.model' => GeminiDriver::modelOr($state['gemini_model'] ?? null),
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
            'live_translate.game_target' => array_key_exists($state['live_translate_game_target'] ?? '', self::LIVE_TRANSLATE_LANGUAGES) ? $state['live_translate_game_target'] : 'en',
            'live_translate.game_spoken' => array_values(array_intersect(array_keys(self::LIVE_TRANSLATE_LANGUAGES), (array) ($state['live_translate_game_spoken'] ?? []))),
            'live_translate.game_speed' => array_key_exists($state['live_translate_game_speed'] ?? '', self::LIVE_TRANSLATE_SPEEDS) ? $state['live_translate_game_speed'] : 'fastest',
            'live_translate.monthly_usd' => filled($state['live_translate_monthly_usd'] ?? null) ? max(0.0, round((float) $state['live_translate_monthly_usd'], 2)) : null,
            'live_translate.watch_target' => array_key_exists($state['live_translate_watch_target'] ?? '', self::LIVE_TRANSLATE_LANGUAGES) ? $state['live_translate_watch_target'] : 'ur',
            'live_translate.watch_speed' => array_key_exists($state['live_translate_watch_speed'] ?? '', self::LIVE_TRANSLATE_SPEEDS) ? $state['live_translate_watch_speed'] : 'balanced',
            'routing.final' => self::routingFromState($state),
            'routing.urdu_wait_hours' => EngineWait::clampHours($state['urdu_wait_hours'] ?? null),
        ]);
        // The SMTP password goes to the credential vault (its change is in the audit log there); empty keeps it.
        $vault = app(CredentialVault::class);
        [$provider, $key] = MailSettings::VAULT;
        if (filled($state['mail_password'] ?? null)) {
            $vault->put($provider, $key, (string) $state['mail_password'], (int) auth()->id());
        } elseif (($state['mail_password_forget'] ?? false) && $vault->has($provider, $key)) {
            $vault->forget($provider, $key);
        }
        $this->data['mail_password'] = null;
        $this->data['mail_password_forget'] = false;
        app(MailSettings::class)->apply();
        // Plan limits live on the plan of the current workspace, not in settings (never exported).
        $limit = fn (string $key): ?int => filled($state[$key] ?? null) ? (int) $state[$key] : null;
        $gb = $limit('plan_storage_gb');
        app(PlanLimits::class)->plan((int) CurrentWorkspace::id())->update([
            'monthly_minutes' => $limit('plan_monthly_minutes'),
            'storage_bytes' => $gb === null ? null : $gb * 1024 ** 3,
            'max_devices' => $limit('plan_max_devices'),
        ]);
        // Since v1.5 the audit log names what changed (the keys only: values stay in the settings).
        $after = $settings->all();
        // A list saved in another order (ticked languages) is the same setting.
        $same = function (mixed $value): string {
            if (is_array($value) && array_is_list($value) && array_filter($value, 'is_array') === []) {
                sort($value);
            }

            return (string) json_encode($value);
        };
        $changed = array_values(array_filter(array_keys($after), fn (string $key): bool => $same($before[$key] ?? null) !== $same($after[$key])));
        if ($changed !== []) {
            app(AuditLog::class)->record('settings_changed', (int) auth()->id(), null, Str::limit('Settings changed: '.implode(', ', $changed), 250, '…'), ['keys' => $changed]);
        }
    }

    /** @return array{plan_monthly_minutes: int|null, plan_storage_gb: int|null, plan_max_devices: int|null} */
    private static function planState(): array
    {
        $plan = app(PlanLimits::class)->plan((int) CurrentWorkspace::id());

        return [
            'plan_monthly_minutes' => $plan->monthly_minutes,
            'plan_storage_gb' => $plan->storage_bytes === null ? null : (int) round($plan->storage_bytes / 1024 ** 3),
            'plan_max_devices' => $plan->max_devices,
        ];
    }

    private static function planDescription(): string
    {
        $plan = app(PlanLimits::class)->plan((int) CurrentWorkspace::id());
        $count = $plan->workspaces()->count();

        return "The \"{$plan->name}\" plan of this workspace".($count > 1 ? " (shared by {$count} workspaces)" : '').'. Leave a limit empty for none. No billing: this only caps use. Every plan, its features and which workspace is on it: System → Plans.';
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
