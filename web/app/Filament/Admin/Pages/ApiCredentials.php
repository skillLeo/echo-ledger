<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\Accounts\GoogleSignIn;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use App\Stt\GroqPacer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
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
                    ->maxLength(4096)
                    ->helperText(fn (): string => $vault->describe($provider, $key));
                if (in_array("{$provider}.{$key}", CredentialVault::GENERATABLE, true)) {
                    $input->hintAction(Action::make("generate_{$provider}_{$key}")
                        ->label('Generate')
                        ->icon(Heroicon::OutlinedSparkles)
                        ->requiresConfirmation()
                        ->modalDescription('Creates a new random secret. Webhooks already in flight will fail verification.')
                        ->action(function () use ($provider, $key, $vault): void {
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

    public function save(CredentialVault $vault): void
    {
        $saved = 0;
        foreach ($this->form->getState() as $field => $value) {
            if (! is_string($value) || trim($value) === '' || ! str_contains($field, '__')) {
                continue; // only overwrite what the admin actually typed
            }
            [$provider, $key] = explode('__', $field, 2);
            if (! isset(CredentialVault::FIELDS[$provider][$key])) {
                continue;
            }
            $vault->put($provider, $key, trim($value), (int) auth()->id());
            $saved++;
        }
        $this->form->fill();

        Notification::make()->title($saved > 0 ? "Saved {$saved} credential(s)" : 'Nothing to save')->success()->send();
    }
}
