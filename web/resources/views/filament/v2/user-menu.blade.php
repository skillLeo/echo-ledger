{{--
    The v2.0 account menu (DES-08 + UX-18). Its button is the person's avatar (x-el.avatar: their photo, else their
    initials on their colour, avatar_color; never grey), named "Account, <name>". In the rail ($inRail) it is a rail item:
    a 28 px avatar in a 40 px target with "Account" under it, the tooltip "Account, theme and settings", an 8% tint on
    hover, a 2 px accent ring while the menu is open and an 8 px dot only for an account problem (SettingsContext::
    accountProblem: a payment that failed); it is one of the rail's stops, which the arrows reach (FIX-06), and its menu
    opens upward. Plain PHP writes what differs in the rail, so Livewire adds no block markers inside the tag.
    The menu: a header (a 40 px avatar, the name, the email and the plan with the month's minutes), then Add photo (only
    without one) or Edit profile, Theme, the Zoom row (v21_zoom), Plan & billing, Settings, Keyboard shortcuts, Help,
    the admin panel for staff, and Sign out last, apart from the rest.
--}}
@php
    use App\Filament\Auth\EditProfile;
    use App\Filament\Support\ConsoleV2;
    use App\Filament\Support\SettingsContext;
    use App\Services\Accounts\Avatars;
    use App\Services\Workspaces\PlanLimits;

    $context = SettingsContext::current();
    $user = $context?->user;
    $name = $user !== null ? (filled($user->name) ? $user->name : \Illuminate\Support\Str::before((string) $user->email, '@')) : '';
    $rail = (bool) ($inRail ?? false);
    if ($context !== null) {
        $problem = $context->accountProblem();
        $limits = app(PlanLimits::class);
        $minutes = $limits->limit($context->workspaceId(), 'monthly_minutes', $user->id);
        $planLine = implode(' · ', array_filter([__($context->plan()->name), $minutes !== null && $minutes > 0
            ? __(':used of :limit min used this month', ['used' => number_format((int) round($limits->minutesUsed($context->workspaceId()))), 'limit' => number_format((int) $minutes)])
            : null]));
        $label = $problem !== null ? __('Account, :name, a payment needs your attention', ['name' => $name]) : __('Account, :name', ['name' => $name]);
        $hasPhoto = Avatars::of($user)['avatar_url'] !== null;
        $profileUrl = EditProfile::settingsUrl('profile');
    }
@endphp
@if ($context !== null)
    <x-filament::dropdown :placement="$rail ? 'top-start' : ($placement ?? 'bottom-end')" teleport width="xs" :class="'fi-user-menu v2-um'.($rail ? ' is-rail' : '')">
        <x-slot name="trigger">
            <button type="button" class="fi-user-menu-trigger v2-um-trigger<?php if ($rail): ?> is-rail<?php endif; ?>" aria-label="{{ $label }}"<?php if ($rail): ?> title="{{ __('Account, theme and settings') }}" tabindex="-1"<?php endif; ?>>
<?php if ($rail): ?>
                <span class="v2-um-hit"><x-el.avatar :person="$user" size="rail" :drawn="true" /><?php if ($problem !== null): ?><span class="v2-um-dot" aria-hidden="true"></span><?php endif; ?></span>
                <span class="v2-rail-label" aria-hidden="true">{{ __('Account') }}</span>
<?php else: ?>
                <x-el.avatar :person="$user" :drawn="true" />
<?php endif; ?>
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            {{-- Who it is: the menu's own header, not an item (Edit profile and Settings are). --}}
            <div class="v2-um-head" data-account-head>
                <x-el.avatar :person="$user" size="large" />
                <span class="v2-um-text">
                    {{-- Each in its own direction, every line at the menu's start (as x-el.person). --}}
                    <span class="v2-um-name"><bdi>{{ $name }}</bdi></span>
                    <span class="v2-um-email"><bdi dir="ltr">{{ $user->email }}</bdi></span>
                    <span class="v2-um-plan">{{ $planLine }}</span>
                </span>
            </div>
        </x-filament::dropdown.list>
        <x-filament::dropdown.list>
            {{-- Settings › Profile; without a photo, with its photo window open (Filament's ?action=). --}}
<?php if (! $hasPhoto): ?>
            <x-filament::dropdown.list.item tag="a" :href="$profileUrl.'?action=changePhoto'" data-account-item="photo">{{ __('Add photo') }}</x-filament::dropdown.list.item>
<?php else: ?>
            <x-filament::dropdown.list.item tag="a" :href="$profileUrl" data-account-item="profile">{{ __('Edit profile') }}</x-filament::dropdown.list.item>
<?php endif; ?>
            <div class="v2-um-theme" x-data="{ theme: localStorage.getItem('filament:theme:mode') || 'system' }" x-on:theme-changed.window="theme = $event.detail">
                <span id="v2-um-theme">{{ __('Theme') }}</span>
                <span class="v2-segmented" role="radiogroup" aria-labelledby="v2-um-theme">
                    @foreach (['system' => __('System'), 'light' => __('Light'), 'dark' => __('Dark')] as $value => $themeLabel)
                        <button type="button" role="radio" x-bind:aria-checked="theme === '{{ $value }}' ? 'true' : 'false'"
                            x-on:click="theme = '{{ $value }}'; window.dispatchEvent(new CustomEvent('theme-changed', { detail: '{{ $value }}' }))">{{ $themeLabel }}</button>
                    @endforeach
                </span>
            </div>
<?php if (ConsoleV2::zoom()): ?>
            {{-- UX-07: "Zoom [−] 100% [+]", as Chrome's menu has it, next to Theme (filament.v2.zoom-script). --}}
            <div class="v2-um-zoom" data-account-item="zoom" x-data="{ level: window.ElZoom ? window.ElZoom.now() : 100, steps: window.ElZoom ? window.ElZoom.steps : [100] }"
                x-on:el-zoom.window="level = $event.detail">
                <span id="v2-um-zoom">{{ __('Zoom') }}</span>
                <span class="v2-um-stepper" role="group" aria-labelledby="v2-um-zoom">
                    <button type="button" class="v2-um-step" aria-label="{{ __('Zoom out') }}" x-bind:disabled="level <= steps[0]" x-on:click="window.ElZoom?.by(-1)">−</button>
                    <output class="v2-um-level" aria-live="polite" x-text="level + '%'">100%</output>
                    <button type="button" class="v2-um-step" aria-label="{{ __('Zoom in') }}" x-bind:disabled="level >= steps[steps.length - 1]" x-on:click="window.ElZoom?.by(1)">+</button>
                </span>
            </div>
<?php endif; ?>
        </x-filament::dropdown.list>
        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item tag="a" :href="EditProfile::settingsUrl('plan')" data-account-item="plan">{{ __('Plan & billing') }}</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item tag="a" :href="EditProfile::settingsUrl()" data-account-item="settings">{{ __('Settings') }}</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item tag="button" x-on:click="$dispatch('el-help')" data-account-item="shortcuts">{{ __('Keyboard shortcuts') }} <kbd class="v2-um-key" aria-hidden="true">?</kbd></x-filament::dropdown.list.item>
<?php if (\App\Filament\Pages\Help::canAccess()): ?>
            <x-filament::dropdown.list.item tag="a" :href="\App\Filament\Pages\Help::getUrl()" data-account-item="help">{{ __('Help') }}</x-filament::dropdown.list.item>
<?php endif; ?>
            @if (\App\Services\Staff\StaffAccess::isStaff())
                <x-filament::dropdown.list.item tag="a" :href="\Filament\Facades\Filament::getPanel('admin')->getUrl() ?? '/admin'">{{ __('Admin panel') }}</x-filament::dropdown.list.item>
            @endif
        </x-filament::dropdown.list>
        <x-filament::dropdown.list>
            <form action="{{ filament()->getLogoutUrl() }}" method="post">
                @csrf
                <x-filament::dropdown.list.item tag="button" type="submit" data-account-item="sign-out">{{ __('Sign out') }}</x-filament::dropdown.list.item>
            </form>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
