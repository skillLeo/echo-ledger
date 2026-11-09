{{--
    The profile card (since v1.7), at the top of Settings: who you are and your plan. The body opens Plan & usage;
    Upgrade is its sibling (filled on Free, outlined on Pro, none on the top plan, none while a request is open or for
    a member of someone else's workspace).
--}}
@php
    /** @var \App\Filament\Support\SettingsContext $context */
    use App\Filament\Auth\EditProfile;
    use App\Filament\Support\InitialsAvatarProvider;
    use App\Filament\Support\UpgradeFlow;
    use App\Services\Workspaces\PlanLimits;
    use App\Support\Formats;

    $user = $context->user;
    $plan = $context->plan();
    $name = filled($user->name) ? $user->name : \Illuminate\Support\Str::before((string) $user->email, '@');
    $workspace = $context->workspace();
    $limits = app(PlanLimits::class);
    $minutes = $limits->limit($context->workspaceId(), 'monthly_minutes', $user->id);
    $expires = $workspace?->plan_expires_at;
    $isTop = $context->higherPlans() === [];
    $line = match (true) {
        $minutes !== null && $minutes > 0 && ! $isTop => number_format((int) round($limits->minutesUsed($context->workspaceId()))).' of '.number_format((int) $minutes).' min this month',
        $plan->key !== 'free' && $expires !== null => 'Paid until '.Formats::shortDate($expires, year: true),
        default => null,
    };
    $member = $context->flag('member');
    $owner = $member && $workspace !== null ? \App\Models\User::query()->find(\App\Services\Entitlements\Entitlements::ownerOf($workspace->id)) : null;
    $teamLine = match (true) {
        $owner !== null => "{$workspace->name} is on {$plan->name}. Managed by {$owner->name}.",
        $context->flag('team_member') && $workspace !== null => $workspace->name.($context->role() ? ', '.$context->role()->label() : ''),
        default => null,
    };
    $button = match (true) {
        $member || $context->hasOpenRequest() || ! $context->flag('buyer') || $isTop => null,
        $plan->key === 'free' || ($plan->limit('monthly_minutes') ?? 1) === 0 => 'primary',
        default => 'outline',
    };
    $planUrl = EditProfile::settingsUrl('plan');
    $label = "{$name}, {$user->email}, {$plan->name} plan".($expires !== null && $plan->key !== 'free' ? ', paid until '.Formats::longDate($expires) : '').'. Opens plan and usage.';
@endphp
<div class="el-pcard" data-profile-card>
    <a href="{{ $planUrl }}" class="el-pcard-body" aria-label="{{ $label }}">
        <img class="el-pcard-avatar" src="{{ InitialsAvatarProvider::dataUri($name) }}" alt="" aria-hidden="true">
        <span class="el-pcard-text">
            <span class="el-pcard-name">{{ $name }}</span>
            <span class="el-pcard-email">{{ $user->email }}</span>
            <span class="el-pcard-meta">
                <span class="el-tag" data-plan-chip>{{ $plan->name }}</span>
                @if ($line)<span>{{ $line }}</span>@endif
            </span>
            @if ($teamLine)<span class="el-pcard-meta" style="margin-top:2px">{{ $teamLine }}</span>@endif
        </span>
    </a>
    @if ($button !== null)
        <a href="{{ UpgradeFlow::chooseUrl($context->higherPlans()[0]->key ?? null) }}" class="el-btn {{ $button === 'primary' ? 'el-btn-primary' : 'el-btn-outline' }}" data-upgrade="{{ $button }}">Upgrade</a>
    @endif
</div>
