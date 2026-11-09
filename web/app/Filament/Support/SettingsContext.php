<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\WorkspaceRole;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\GetTheApps;
use App\Filament\Pages\Security;
use App\Filament\Pages\WorkspaceSettings;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountSessions;
use App\Services\Accounts\EmailPreferences;
use App\Services\Accounts\GoogleSignIn;
use App\Services\Accounts\SettingsIndex;
use App\Services\Accounts\SettingsRegistry;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\Features;
use App\Services\Entitlements\TranscriptionKeys;
use App\Services\WebSessions;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use App\Stt\LanguageChoice;
use App\Support\Formats;

/**
 * Settings for one person (since v1.7): which registry entries they have (the plan, the role, two-step sign-in, a
 * key, a request), the value each row shows at its right, and where each entry opens. Read once per request.
 */
final class SettingsContext
{
    /** @var array<string, bool> */
    private array $flags;

    private ?Plan $plan = null;

    private ?UpgradeRequest $request = null;

    private bool $requestRead = false;

    public function __construct(public readonly User $user)
    {
        $workspace = $this->workspaceId();
        $limits = app(PlanLimits::class);
        $role = app(Workspaces::class)->roleOf($user, $workspace);
        $platform = $workspace > 0 && $limits->allows($workspace, 'platform_transcription', $user->id);
        $key = app(TranscriptionKeys::class)->describe($user)['set'];
        $buyer = $role === null || $role->manages();
        $higher = $this->higherPlans() !== [];
        $seats = $workspace > 0 ? $limits->limit($workspace, 'seats', $user->id) : 1;
        $team = $workspace > 0 && ($limits->seatsUsed($workspace) > 1 || $user->workspaces()->count() > 1);

        $this->flags = [
            'free_or_key' => ! $platform || $key,
            'key' => $key,
            'buyer' => $buyer,
            'can_upgrade' => $buyer && $higher && ! $this->hasOpenRequest(),
            'seats' => $seats === null || $seats > 1,
            'team_member' => $team,
            'two_step' => $user->hasTwoFactor(),
            'full_console' => ConsoleAccess::full(),
            'google' => app(GoogleSignIn::class)->configured() || $user->google_id !== null,
            'workspace_admin' => $role?->manages() === true && WorkspaceSettings::canAccess() && ($team || ($seats === null || $seats > 1)),
            'deleting' => $user->deletes_at !== null,
            'browsers' => WebSessions::available(),
            'member' => $role !== null && ! $role->manages(),
        ];
    }

    public static function current(): ?self
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }
        $cached = request()->attributes->get('el.settings.context');
        if ($cached instanceof self && $cached->user->is($user)) {
            return $cached;
        }
        $context = new self($user);
        request()->attributes->set('el.settings.context', $context);

        return $context;
    }

    /** Forgets the person's context read for this request (after a change it depends on). */
    public static function forget(): void
    {
        request()->attributes->remove('el.settings.context');
    }

    public function flag(string $name): bool
    {
        return $this->flags[$name] ?? false;
    }

    public function workspaceId(): int
    {
        return (int) (CurrentWorkspace::id() ?? $this->user->current_workspace_id ?? 0);
    }

    public function plan(): Plan
    {
        return $this->plan ??= app(Entitlements::class)->plan($this->workspaceId());
    }

    /** @return list<Plan> the plans on offer ranked above the current one, cheapest first */
    public function higherPlans(): array
    {
        $current = $this->plan();

        return array_values(array_filter(app(Entitlements::class)->offered(), fn (Plan $p): bool => ! $p->is($current)
            && ($p->sort_order > $current->sort_order || ($p->sort_order === $current->sort_order && (float) $p->price_usd > (float) $current->price_usd))
            && $p->key !== Plan::FREE));
    }

    /** The person's newest upgrade request, open or decided. */
    public function latestRequest(): ?UpgradeRequest
    {
        if (! $this->requestRead) {
            $this->request = UpgradeRequest::query()->where('user_id', $this->user->id)->orderByDesc('created_at')->orderByDesc('id')->first();
            $this->requestRead = true;
        }

        return $this->request;
    }

    public function openRequest(): ?UpgradeRequest
    {
        $request = $this->latestRequest();

        return $request !== null && $request->status === UpgradeRequest::PENDING ? $request : null;
    }

    public function hasOpenRequest(): bool
    {
        return $this->openRequest() !== null;
    }

    /**
     * Whether the entry's requirements hold for this person.
     *
     * @param  array<string, mixed>  $entry
     */
    public function available(array $entry): bool
    {
        foreach ((array) ($entry['requires'] ?? []) as $flag => $wanted) {
            if ($this->flag((string) $flag) !== (bool) $wanted) {
                return false;
            }
        }
        $page = $entry['page'] ?? null;
        if (is_string($page) && $entry['id'] !== (SettingsRegistry::PAGES[$page] ?? null)) {
            $root = SettingsRegistry::byId()[SettingsRegistry::PAGES[$page] ?? ''] ?? null;
            if ($root !== null && ! $this->available($root)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> the registry entries this person has, in order, with the dynamic ones */
    public function entries(): array
    {
        $out = [];
        foreach (SettingsRegistry::entries() as $entry) {
            if ($this->available($entry)) {
                $out[] = $entry;
            }
        }
        foreach ($this->dynamicEntries() as $entry) {
            $out[] = $entry;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the person's devices and the features of the plans, as entries */
    private function dynamicEntries(): array
    {
        $base = ['section' => null, 'description' => '', 'options' => [], 'aliases' => [], 'ru' => [], 'ur' => [], 'requires' => [], 'resettable' => false, 'default' => null];
        $out = [];
        foreach (app(AccountSessions::class)->devices($this->user) as $device) {
            $out[] = [...$base, 'id' => 'devices.item.'.$device->id, 'title' => $device->readableName(), 'page' => 'devices', 'kind' => 'action',
                'keywords' => ['rename', 'remove', 'sign out device'], 'ru' => ['naam badlo']];
        }
        $features = app(Entitlements::class)->features($this->workspaceId(), $this->user->id);
        foreach (Features::ALL as $key => $feature) {
            $out[] = [...$base, 'id' => 'plan.feature.'.$key, 'title' => $feature['name'], 'page' => 'plan', 'kind' => 'info',
                'description' => $feature['description'], 'keywords' => [], 'locked' => ! ($features[$key] ?? false), 'feature' => $key];
        }

        return $out;
    }

    public function index(): SettingsIndex
    {
        return new SettingsIndex($this->entries());
    }

    /**
     * The address an entry opens: its page, scrolled to it and highlighted.
     *
     * @param  array<string, mixed>  $entry
     */
    public function url(array $entry): string
    {
        $id = (string) $entry['id'];
        $open = $this->openRequest();
        if (str_starts_with($id, 'help.article.')) {
            $target = SettingsRegistry::byId()[(string) ($entry['target'] ?? '')] ?? null;

            return $target !== null && $this->available($target) ? $this->url($target) : EditProfile::settingsUrl('help', $id);
        }

        return match (true) {
            $id === 'workspace' => WorkspaceSettings::getUrl(panel: 'app'),
            $id === 'help.apps' => GetTheApps::getUrl(panel: 'app'),
            $id === 'security.activity' => Security::getUrl(panel: 'app'),
            $id === 'signout' => EditProfile::settingsUrl(null, 'signout'),
            in_array($id, ['plan.request', 'plan.history'], true) && $open !== null => UpgradeFlow::statusUrl($open),
            $id === 'plan.payment_details' && $open !== null => UpgradeFlow::payUrl($open),
            $id === 'plan.receipt' && $open !== null => UpgradeFlow::receiptUrl($open),
            in_array($id, ['plan.request', 'plan.payment_details', 'plan.receipt'], true) => EditProfile::settingsUrl('plan', $this->flag('can_upgrade') ? 'plan.upgrade' : 'plan.current'),
            SettingsIndex::isRoot($entry) && is_string($entry['page'] ?? null) => EditProfile::settingsUrl((string) $entry['page']),
            default => EditProfile::settingsUrl(is_string($entry['page'] ?? null) ? (string) $entry['page'] : null, (string) ($entry['anchor'] ?? $id)),
        };
    }

    /**
     * The value a row shows at its right ("Free", "2 signed in"), or null.
     *
     * @param  array<string, mixed>  $entry
     */
    public function value(array $entry): ?string
    {
        $workspace = $this->workspaceId();
        $limits = app(PlanLimits::class);

        return match ((string) $entry['id']) {
            'transcription.language' => $this->languageName(Shell::preferences($this->user)['language']),
            'key' => $this->flag('key') ? 'Added' : 'Not added',
            'notifications' => $this->emailsOn().' of '.count(EmailPreferences::CATEGORIES).' emails on',
            'storage' => Formats::bytes($limits->storageUsed($workspace)).($this->storageLimit() !== null ? ' of '.Formats::bytes((int) $this->storageLimit()) : ''),
            'plan', 'plan.current' => $this->plan()->name,
            'security' => $this->flag('two_step') ? 'Two-step on' : 'Two-step off',
            'security.two_step' => $this->flag('two_step') ? 'On' : 'Off',
            'devices' => ($n = $limits->devicesUsed($workspace, $this->user->id)).' signed in',
            'workspace' => ($n = $limits->seatsUsed($workspace)) === 1 ? '1 person' : $n.' people',
            'appearance.sidebar' => Shell::STYLES[Shell::preferences($this->user)['style']],
            'appearance.density' => Shell::DENSITIES[Shell::preferences($this->user)['density']],
            'appearance.clock' => Shell::preferences($this->user)['clock'] === '24h' ? '24-hour' : '12-hour',
            'appearance.library_view' => Shell::LAYOUTS[Shell::preferences($this->user)['layout']],
            default => null,
        };
    }

    /**
     * The tag a locked row shows ("Pro"), or null.
     *
     * @param  array<string, mixed>  $entry
     */
    public function tag(array $entry): ?string
    {
        if (($entry['locked'] ?? false) !== true || ! is_string($entry['feature'] ?? null)) {
            return null;
        }

        return app(Entitlements::class)->lowestPlan((string) $entry['feature'])?->name;
    }

    public function languageName(string $code): string
    {
        if ($code === '') {
            return 'Automatic';
        }
        $names = ['en' => 'English', 'ur' => 'Urdu', 'ar' => 'Arabic', ...LanguageChoice::MORE];

        return $names[$code] ?? $code;
    }

    public function storageLimit(): ?int
    {
        $limit = app(PlanLimits::class)->limit($this->workspaceId(), 'storage_bytes', $this->user->id);

        return $limit === null ? null : (int) $limit;
    }

    public function emailsOn(): int
    {
        return count(array_filter(EmailPreferences::rows($this->user), fn (array $row): bool => $row['on']));
    }

    public function role(): ?WorkspaceRole
    {
        return app(Workspaces::class)->roleOf($this->user, $this->workspaceId());
    }

    public function workspace(): ?Workspace
    {
        return Workspace::query()->find($this->workspaceId());
    }
}
