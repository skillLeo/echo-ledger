<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\CredentialVault;
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

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'API Credentials';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_super_admin;
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
            $sections[] = Section::make(match ($provider) {
                'assemblyai' => 'AssemblyAI (default)',
                'elevenlabs' => 'ElevenLabs',
                'azure' => 'Azure Speech',
                'whispercpp' => 'whisper.cpp server',
                'openai' => 'OpenAI (optional)',
                'groq' => 'Groq (optional)',
            })->columns(2)->collapsible()->schema($inputs);
        }

        return $schema->statePath('data')->components($sections);
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
