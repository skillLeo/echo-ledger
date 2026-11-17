<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\Notice as Notification;
use App\Rules\CertificatePin;
use App\Services\Accounts\GoogleSignIn;
use App\Services\Assist\RelaySecret;
use App\Services\Assist\RelayToken;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use App\Stt\GroqPacer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

/**
 * Write-only secret entry. Stored encrypted; values are never sent back to the browser — only a masked status.
 *
 * @property-read Schema $form
 */
class ApiCredentials extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Key;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'API Credentials';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return StaffAccess::can('credentials.manage');
    }

    public function mount(): void
    {
        $this->form->fill(); // intentionally empty: secrets are write-only
        // The relay's address and pins are no secret: shown as they are saved.
        $this->data['relay_url'] = app(SettingsRepository::class)->get('relay.url');
        $this->data['relay_pins'] = app(SettingsRepository::class)->relayPins();
    }

    public function form(Schema $schema): Schema
    {
        $vault = app(CredentialVault::class);
        $sections = [
            Section::make('Webhook URLs')->description('Configure these in the provider dashboards.')->schema([
                Text::make('AssemblyAI: set per request automatically (needs a public HTTPS APP_URL).'),
                Text::make('ElevenLabs workspace webhook:'),
                // The long URL wraps anywhere so the page fits a 360 px wide phone screen.
                Text::make(rtrim((string) config('app.url'), '/').'/api/webhooks/stt/elevenlabs')
                    ->fontFamily(FontFamily::Mono)->copyable()
                    ->extraAttributes(['style' => 'overflow-wrap: anywhere; word-break: break-all;']),
            ]),
        ];

        foreach (CredentialVault::FIELDS as $provider => $fields) {
            $inputs = [];
            foreach ($fields as $key => $label) {
                $input = TextInput::make("{$provider}__{$key}")
                    ->label($label)
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    // Password managers fill saved passwords into password boxes despite new-password; these opt out.
                    ->extraInputAttributes(['data-1p-ignore' => 'true', 'data-lpignore' => 'true', 'data-bwignore' => 'true', 'data-form-type' => 'other'])
                    ->maxLength(4096)
                    ->helperText(fn (): string => $vault->describe($provider, $key));
                if (in_array("{$provider}.{$key}", CredentialVault::GENERATABLE, true)) {
                    $input->hintAction(Action::make("generate_{$provider}_{$key}")
                        ->label('Generate')
                        ->icon(Heroicon::OutlinedSparkles)
                        ->requiresConfirmation()
                        ->modalDescription('Creates a new random secret. Webhooks already in flight will fail verification.')
                        ->action(function () use ($provider, $key, $vault): void {
                            if ("{$provider}.{$key}" === RelaySecret::PROVIDER.'.'.RelaySecret::CURRENT) {
                                // The relay needs the value: shown once, here. The old one works 15 more minutes.
                                $secret = app(RelaySecret::class)->rotate(null, (int) auth()->id());
                                Notification::make()->title('New relay secret generated')->persistent()->success()
                                    ->body('Give it to the relay now as relay_secret, and the old one as relay_secret_previous (see the relay\'s DEPLOY.md); it is not shown again. The old one keeps working here for 15 minutes. New secret: '.$secret)
                                    ->send();

                                return;
                            }
                            $vault->put($provider, $key, CredentialVault::generateSecret(), (int) auth()->id());
                            Notification::make()->title('New secret generated and stored')->success()->send();
                        }));
                }
                $inputs[] = $input;
            }
            $section = Section::make(match ($provider) {
                'assemblyai' => 'AssemblyAI (default)',
                'elevenlabs' => 'ElevenLabs',
                'soniox' => 'Soniox (optional)',
                'groq' => 'Groq (Whisper large-v3, for Urdu)',
                'anthropic' => 'Anthropic (Claude, for Show as: Roman Urdu and English)',
                'azure' => 'Azure Speech',
                'whispercpp' => 'whisper.cpp server',
                'openai' => 'OpenAI (optional)',
                'gemini' => 'Google Gemini (optional, an Urdu challenger)',
                'google' => 'Google sign-in (optional)',
                'turnstile' => 'Turnstile (optional, the web sign-up\'s check against robots)',
                'moonshot' => 'Moonshot (Kimi, optional, for Live Assist later)',
                'assist_relay' => 'Live Assist relay',
            })->columns(2)->collapsible()->schema($inputs);
            if ($provider === 'groq') {
                $section->description(fn (): ?string => self::groqUse());
            }
            if ($provider === 'gemini') {
                $section->description('Use a key from a Google Cloud project with billing enabled (the paid tier): on the free tier Google may use the audio and transcripts to improve its products. Each piece of audio is uploaded for one request and deleted right after it.');
            }
            if ($provider === 'turnstile') {
                $section->description('A free Cloudflare Turnstile widget for this console\'s domain (dash.cloudflare.com → Turnstile → Add widget). With both keys saved, the web sign-up asks for the check before it emails a code; without them it is skipped.');
            }
            if ($provider === 'assist_relay') {
                // Since v1.9 (L1): the relay's host, where the apps connect (docs/API_ASSIST.md `relay.url`).
                $section->schema([
                    TextInput::make('relay_url')->label('Relay address')->placeholder('relay.example.com')->maxLength(253)
                        ->helperText(fn (?string $state): string => RelayToken::url($state) !== null
                            ? 'The apps connect to '.RelayToken::url($state).'. Empty: Live Assist cannot start.'
                            : 'The relay\'s host name; the apps connect to wss://<host>/v1/assist. Empty: Live Assist cannot start.'),
                    // Since 1.9.0-g: the relay host's pins, sent to the apps while Live Assist is on for them.
                    TagsInput::make('relay_pins')->label('Relay certificate pins (SPKI SHA-256)')->placeholder('sha256/…')
                        ->nestedRecursiveRules([new CertificatePin])
                        ->helperText('The relay host\'s current and backup key pins. Empty: the apps cannot connect to the relay.'),
                    ...$inputs,
                ]);
                $section->description('Signs the relay tokens and the relay\'s requests to this server. Generate one, or paste one of at least '.RelaySecret::MIN_LENGTH.' characters; the old one keeps working for 15 minutes, so the relay can be given the new one without a gap.');
            }
            if ($provider === 'google') {
                $section->description('An OAuth client ID of type "Web application" from the Google Cloud console (APIs & Services → Credentials). Its authorised redirect URI: '.GoogleSignIn::redirectUri().' — Sign in with Google appears on the sign-in page once both values are saved.');
            }
            $sections[] = $section;
        }

        return $schema->statePath('data')->components($sections);
    }

    /**
     * The Groq row (since v1.5): the limits requests are paced under, the plan's or the ones Groq reported, and what the
     * stored key used this minute, hour and day.
     */
    public static function groqUse(): ?string
    {
        $key = app(CredentialVault::class)->get('groq', 'api_key');
        if ($key === null) {
            return null;
        }
        $pacer = app(GroqPacer::class);
        $id = GroqPacer::keyId($key);
        $limits = $pacer->limits($id);
        $use = $pacer->usage($id);
        $n = fn (int $value): string => number_format($value);
        $line = sprintf(
            'Limits (%s plan%s): %s requests a minute, %s a day; %s audio seconds an hour, %s a day. Used: %s requests this minute, %s audio seconds this hour, %s today (%s requests).',
            GroqPacer::PLAN_LABELS[$limits['plan']], $limits['from_groq'] ? ', requests a day as Groq reports them' : '',
            $n($limits['rpm']), $n($limits['rpd']), $n($limits['ash']), $n($limits['asd']),
            $n($use['minute_requests']), $n($use['hour_seconds']), $n($use['day_seconds']), $n($use['day_requests']),
        );
        $until = $pacer->blockedUntil($id);
        if ($until !== null) {
            $line .= ' Groq asked to wait until '.$until->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('g:i A').'.';
        }

        return $line;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save credentials')->submit('save')->keyBindings(['mod+s'])])]),
        ]);
    }

    /** An API key shorter than this is not one (the shortest real ones are 32); a saved password usually is. */
    public const MIN_API_KEY_LENGTH = 16;

    public function save(CredentialVault $vault): void
    {
        $state = $this->form->getState();
        // The relay's address first: kept as the host only, always wss://<host>/v1/assist for the apps.
        $relay = trim((string) ($state['relay_url'] ?? ''));
        if ($relay !== '' && RelayToken::url($relay) === null) {
            $this->addError('data.relay_url', 'Enter the relay\'s host name, such as relay.example.com.');
            Notification::make()->title('Nothing saved')->body('Check the boxes marked in red.')->danger()->send();

            return;
        }
        app(SettingsRepository::class)->set('relay.url', $relay === '' ? null : (string) parse_url((string) RelayToken::url($relay), PHP_URL_HOST));
        app(SettingsRepository::class)->set('relay.pins', array_values(array_unique(array_filter((array) ($state['relay_pins'] ?? []), CertificatePin::isValid(...)))));
        unset($state['relay_url'], $state['relay_pins']);

        $typed = [];
        foreach ($state as $field => $value) {
            if (! is_string($value) || trim($value) === '' || ! str_contains($field, '__')) {
                continue; // only overwrite what the admin actually typed
            }
            [$provider, $key] = explode('__', $field, 2);
            if (isset(CredentialVault::FIELDS[$provider][$key])) {
                $typed[$field] = trim($value);
            }
        }

        // A browser that fills a saved password into these boxes must not end up in the vault (since v1.6.3): nothing
        // is saved while any box holds the person's own password, the same text as another box, or a too-short key.
        $errors = [];
        $password = auth()->user()?->getAuthPassword();
        $seen = [];
        foreach ($typed as $field => $value) {
            if (is_string($password) && $password !== '' && Hash::check($value, $password)) {
                $errors[$field] = 'This is your sign-in password, probably filled in by the browser. Paste the key from the provider instead.';
            } elseif (isset($seen[$value])) {
                $errors[$field] = $errors[$seen[$value]] = 'Two boxes got the same text, probably a password the browser filled in. Paste each key again.';
            } elseif (str_ends_with($field, '__api_key') && mb_strlen($value) < self::MIN_API_KEY_LENGTH) {
                $errors[$field] = 'Too short to be an API key. Copy the whole key from the provider.';
            } elseif ($field === RelaySecret::PROVIDER.'__'.RelaySecret::CURRENT && mb_strlen($value) < RelaySecret::MIN_LENGTH) {
                $errors[$field] = 'The relay needs at least '.RelaySecret::MIN_LENGTH.' characters. Press Generate for one.';
            }
            $seen[$value] ??= $field;
        }
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError("data.{$field}", $message);
            }
            Notification::make()->title('Nothing saved')->body('Check the boxes marked in red.')->danger()->send();

            return;
        }

        foreach ($typed as $field => $value) {
            [$provider, $key] = explode('__', $field, 2);
            if ($provider === RelaySecret::PROVIDER && $key === RelaySecret::CURRENT) {
                // A pasted relay secret replaces the current one as Generate does: the old one stays 15 minutes.
                app(RelaySecret::class)->rotate($value, (int) auth()->id());

                continue;
            }
            $vault->put($provider, $key, $value, (int) auth()->id());
        }
        $saved = count($typed);
        $this->form->fill();
        $this->data['relay_url'] = app(SettingsRepository::class)->get('relay.url');
        $this->data['relay_pins'] = app(SettingsRepository::class)->relayPins();

        Notification::make()->title($saved > 0 ? "Saved {$saved} credential(s)" : 'Nothing to save')->success()->send();
    }
}
