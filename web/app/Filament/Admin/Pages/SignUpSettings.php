<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Plan;
use App\Services\Accounts\EmailCodes;
use App\Services\Accounts\EmailGate;
use App\Services\Accounts\SignUp;
use App\Services\Accounts\Turnstile;
use App\Services\AuditLog;
use App\Services\Entitlements\Entitlements;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin panel → Sign-up (since v1.6, P2.7): who may make an account (on, off, invite only), the allowed email domains,
 * new accounts a day from one network, the anti-bot check, the plan new accounts get and the payment instructions
 * people read after "Request upgrade". The same values Settings → Privacy & security shows.
 *
 * @property-read Schema $form
 */
class SignUpSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::UserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Sign-up';

    protected static ?string $slug = 'sign-up';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return StaffAccess::can('settings.manage');
    }

    public function mount(SettingsRepository $settings, SignUp $signUp): void
    {
        $this->form->fill([
            'mode' => $signUp->mode(),
            'allowed_domains' => EmailGate::cleanDomains((array) ($settings->get('signup.allowed_domains') ?? [])),
            'per_ip_day' => app(EmailCodes::class)->signUpsPerIpDay(),
            'default_plan' => Plan::defaultPlan()->id,
            'payment_instructions' => $settings->get('upgrade.payment_instructions'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Who can sign up')->schema([
                Select::make('mode')->label('Sign-up')->options(SignUp::SETTING_LABELS)->selectablePlaceholder(false)->required()->helperText(SignUp::SETTING_HELP),
                TagsInput::make('allowed_domains')->label('Allowed email domains')->placeholder('company.com')
                    ->helperText('Empty: any address (throwaway ones never). Otherwise only addresses at these domains may sign up.'),
                TextInput::make('per_ip_day')->label('New accounts a day from one network')->integer()->minValue(1)->maxValue(1000)->required(),
                Text::make(fn (): string => app(Turnstile::class)->configured()
                    ? 'The anti-bot check (Cloudflare Turnstile) is on for the web sign-up page.'
                    : 'The anti-bot check (Cloudflare Turnstile) is off: add its keys under API Credentials → Turnstile to turn it on.'),
            ]),
            Section::make('New accounts')->schema([
                Select::make('default_plan')->label('Plan new accounts get')->required()->selectablePlaceholder(false)
                    ->options(fn (): array => Plan::query()->whereNull('archived_at')->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all())
                    ->helperText('Also the plan a workspace goes back to when its plan ends.'),
                Textarea::make('payment_instructions')->label('Payment instructions')->rows(5)->maxLength(2000)
                    ->placeholder('Bank: … · Account name: … · Account number: … · Reference: your email')
                    ->helperText('Shown after someone presses "Request upgrade", with "We\'ll activate it once confirmed".'),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')])]),
        ]);
    }

    public function save(SettingsRepository $settings): void
    {
        abort_unless(StaffAccess::can('settings.manage'), 403);
        $data = $this->form->getState();
        $mode = isset(SignUp::MODES[$data['mode'] ?? '']) ? (string) $data['mode'] : SignUp::NOBODY;
        $text = trim((string) ($data['payment_instructions'] ?? ''));
        $settings->setMany([
            'signup.mode' => $mode,
            'signup.allowed_domains' => EmailGate::cleanDomains((array) ($data['allowed_domains'] ?? [])),
            'signup.per_ip_day' => max(1, min(1000, (int) ($data['per_ip_day'] ?? EmailCodes::DEFAULT_SIGN_UPS_PER_IP_DAY))),
            'upgrade.payment_instructions' => $text !== '' ? $text : null,
        ]);
        $plan = Plan::query()->whereNull('archived_at')->find((int) ($data['default_plan'] ?? 0));
        if ($plan instanceof Plan && ! $plan->is_default) {
            Plan::query()->where('is_default', true)->update(['is_default' => false]);
            $plan->forceFill(['is_default' => true])->save();
            app(Entitlements::class)->forget();
        }
        app(AuditLog::class)->record('settings_changed', (int) auth()->id(), null, 'Sign-up settings changed');
        Notification::make()->title('Saved')->success()->send();
    }
}
