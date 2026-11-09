{{--
    The avatar menu of the app (since v1.7): who you are (opens Settings), then Upgrade (or Plan & usage), Settings,
    the theme as one System / Light / Dark control, the admin panel for staff, and Sign out.
--}}
@php
    use App\Filament\Auth\EditProfile;
    use App\Filament\Support\InitialsAvatarProvider;
    use App\Filament\Support\SettingsContext;
    use App\Filament\Support\UpgradeFlow;

    $context = SettingsContext::current();
    $user = $context?->user;
    $name = $user !== null ? (filled($user->name) ? $user->name : \Illuminate\Support\Str::before((string) $user->email, '@')) : '';
    $upgrade = $context !== null && $context->flag('can_upgrade') && ! $context->flag('member');
@endphp
@if ($context !== null)
    <x-filament::dropdown placement="bottom-end" teleport width="xs" class="fi-user-menu el-user-menu">
        <x-slot name="trigger">
            <button type="button" class="fi-user-menu-trigger" aria-label="Open the menu for {{ $name }}">
                <x-filament-panels::avatar.user :user="$user" loading="lazy" />
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            <a href="{{ EditProfile::settingsUrl() }}" class="el-um-head" aria-label="{{ $name }}, {{ $user->email }}, {{ $context->plan()->name }} plan. Opens Settings.">
                <img src="{{ InitialsAvatarProvider::dataUri($name) }}" alt="" aria-hidden="true">
                <span class="el-pcard-text">
                    <span class="el-pcard-name">{{ $name }}</span>
                    <span class="el-pcard-email">{{ $user->email }}</span>
                    <span class="el-pcard-meta" style="margin-top:4px"><span class="el-tag">{{ $context->plan()->name }}</span></span>
                </span>
            </a>
        </x-filament::dropdown.list>
        <x-filament::dropdown.list>
            @if ($upgrade)
                <x-filament::dropdown.list.item tag="a" :href="UpgradeFlow::chooseUrl($context->higherPlans()[0]->key ?? null)" icon="heroicon-o-credit-card">Upgrade</x-filament::dropdown.list.item>
            @else
                <x-filament::dropdown.list.item tag="a" :href="EditProfile::settingsUrl('plan')" icon="heroicon-o-credit-card">Plan &amp; usage</x-filament::dropdown.list.item>
            @endif
            <x-filament::dropdown.list.item tag="a" :href="EditProfile::settingsUrl()" icon="heroicon-o-cog-6-tooth">Settings</x-filament::dropdown.list.item>
            <div class="el-um-theme" x-data="{ theme: localStorage.getItem('filament:theme:mode') || 'system' }" x-on:theme-changed.window="theme = $event.detail">
                <span id="el-um-theme">Appearance</span>
                <span class="el-segmented" role="radiogroup" aria-labelledby="el-um-theme">
                    @foreach (['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label)
                        <button type="button" role="radio" x-bind:aria-checked="theme === '{{ $value }}' ? 'true' : 'false'"
                            x-on:click="theme = '{{ $value }}'; window.dispatchEvent(new CustomEvent('theme-changed', { detail: '{{ $value }}' }))">{{ $label }}</button>
                    @endforeach
                </span>
            </div>
            @if (\App\Services\Staff\StaffAccess::isStaff())
                <x-filament::dropdown.list.item tag="a" :href="\Filament\Facades\Filament::getPanel('admin')->getUrl() ?? '/admin'" icon="heroicon-o-shield-check">Admin panel</x-filament::dropdown.list.item>
            @endif
        </x-filament::dropdown.list>
        <x-filament::dropdown.list>
            <form action="{{ filament()->getLogoutUrl() }}" method="post">
                @csrf
                <x-filament::dropdown.list.item tag="button" type="submit" icon="heroicon-o-arrow-left-start-on-rectangle">Sign out</x-filament::dropdown.list.item>
            </form>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
