{{--
    The v2.0 avatar menu: who you are (opens Settings), Upgrade or Plan & usage, Settings, the theme, the admin panel
    for staff, Help, and Sign out last, apart from the rest. Since BE-06 its avatar is the person's photo, else their
    initials in their colour (avatar_color, the tokens' --el-role-avatar-0…7), as in the apps; since DES-08 drawn by the
    one avatar component (x-el.avatar). In the rail ($inRail) it is one of the rail's stops, which the arrows reach
    (FIX-06); plain PHP writes its tabindex, so Livewire adds no block markers inside the tag.
--}}
@php
    use App\Filament\Auth\EditProfile;
    use App\Filament\Support\SettingsContext;
    use App\Filament\Support\UpgradeFlow;

    $context = SettingsContext::current();
    $user = $context?->user;
    $name = $user !== null ? (filled($user->name) ? $user->name : \Illuminate\Support\Str::before((string) $user->email, '@')) : '';
    $upgrade = $context !== null && $context->flag('can_upgrade') && ! $context->flag('member');
@endphp
@if ($context !== null)
    <x-filament::dropdown :placement="$placement ?? 'bottom-end'" teleport width="xs" class="fi-user-menu v2-um">
        <x-slot name="trigger">
            <button type="button" class="fi-user-menu-trigger v2-um-trigger" aria-label="{{ __('Open the menu for :name', ['name' => $name]) }}"<?php if ($inRail ?? false): ?> tabindex="-1"<?php endif; ?>>
                <x-el.avatar :person="$user" />
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            <a href="{{ EditProfile::settingsUrl() }}" class="v2-um-head">
                <x-el.avatar :person="$user" size="large" />
                <span class="v2-um-text">
                    <span class="v2-um-name">{{ $name }}</span>
                    <span class="v2-um-email" dir="ltr">{{ $user->email }}</span>
                </span>
                <span class="v2-chip">{{ $context->plan()->name }}</span>
            </a>
        </x-filament::dropdown.list>
        <x-filament::dropdown.list>
            @if ($upgrade)
                <x-filament::dropdown.list.item tag="a" :href="UpgradeFlow::chooseUrl($context->higherPlans()[0]->key ?? null)">{{ __('Upgrade') }}</x-filament::dropdown.list.item>
            @else
                <x-filament::dropdown.list.item tag="a" :href="EditProfile::settingsUrl('plan')">{{ __('Plan and usage') }}</x-filament::dropdown.list.item>
            @endif
            <x-filament::dropdown.list.item tag="a" :href="EditProfile::settingsUrl()">{{ __('Settings') }}</x-filament::dropdown.list.item>
            <div class="v2-um-theme" x-data="{ theme: localStorage.getItem('filament:theme:mode') || 'system' }" x-on:theme-changed.window="theme = $event.detail">
                <span id="v2-um-theme">{{ __('Theme') }}</span>
                <span class="v2-segmented" role="radiogroup" aria-labelledby="v2-um-theme">
                    @foreach (['system' => __('System'), 'light' => __('Light'), 'dark' => __('Dark')] as $value => $label)
                        <button type="button" role="radio" x-bind:aria-checked="theme === '{{ $value }}' ? 'true' : 'false'"
                            x-on:click="theme = '{{ $value }}'; window.dispatchEvent(new CustomEvent('theme-changed', { detail: '{{ $value }}' }))">{{ $label }}</button>
                    @endforeach
                </span>
            </div>
            <x-filament::dropdown.list.item tag="button" x-on:click="$dispatch('el-help')">{{ __('Help and shortcuts') }}</x-filament::dropdown.list.item>
            @if (\App\Services\Staff\StaffAccess::isStaff())
                <x-filament::dropdown.list.item tag="a" :href="\Filament\Facades\Filament::getPanel('admin')->getUrl() ?? '/admin'">{{ __('Admin panel') }}</x-filament::dropdown.list.item>
            @endif
        </x-filament::dropdown.list>
        <x-filament::dropdown.list>
            <form action="{{ filament()->getLogoutUrl() }}" method="post">
                @csrf
                <x-filament::dropdown.list.item tag="button" type="submit">{{ __('Sign out') }}</x-filament::dropdown.list.item>
            </form>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
