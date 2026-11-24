{{--
    Settings › Appearance: the theme (this browser), the sidebar, the density, 12- or 24-hour times and the Library as a
    list or a table. Each is saved the moment it is chosen; a check says so beside it.
--}}
<x-el.section>
    <div class="el-set-row is-stacked" id="setting-appearance.theme" tabindex="-1" data-setting="appearance.theme"
        x-data="{ theme: localStorage.getItem('filament:theme:mode') || 'system', saved: false,
            pick(value) { this.theme = value; window.dispatchEvent(new CustomEvent('theme-changed', { detail: value })); this.saved = true; setTimeout(() => this.saved = false, 2000) } }"
        x-on:theme-changed.window="theme = $event.detail">
        <span class="el-set-text"><span class="el-set-title" id="el-pref-theme">{{ __('Theme') }}</span></span>
        <span class="el-set-control" style="align-items:center;gap:8px">
            <span class="el-set-saved" x-show="saved" x-cloak aria-hidden="true">@svg('heroicon-m-check')</span>
            <span class="el-segmented" role="radiogroup" aria-labelledby="el-pref-theme"{!! \App\Filament\Support\ConsoleV2::any() ? ' data-radio-keys' : '' !!}>
                @foreach (['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label)
                    <button type="button" role="radio" x-bind:aria-checked="theme === '{{ $value }}' ? 'true' : 'false'" x-bind:tabindex="theme === '{{ $value }}' ? 0 : -1"
                        x-on:click="pick('{{ $value }}')">{{ __($label) }}</button>
                @endforeach
            </span>
        </span>
    </div>
@includeWhen(! empty($appLanguages), 'filament.settings.pages.app-language')    @foreach ($rows as $key => [$id, $options])
        @php($entry = \App\Services\Accounts\SettingsRegistry::byId()[$id])
        <div class="el-set-row is-stacked" id="setting-{{ $id }}" tabindex="-1" data-setting="{{ $id }}" x-data="{ value: @js($prefs[$key]), saved: false,
                pick(v) { this.value = v; this.saved = false; $wire.setPreference('{{ $key }}', v).then((ok) => { this.saved = ok; setTimeout(() => this.saved = false, 2000) }) } }">
            <span class="el-set-text"><span class="el-set-title" id="el-pref-{{ $key }}">{{ __($entry['title']) }}</span></span>
            <span class="el-set-control" style="align-items:center;gap:8px">
                <span class="el-set-saved" x-show="saved" x-cloak aria-hidden="true">@svg('heroicon-m-check')</span>
                <span class="el-segmented" role="radiogroup" aria-labelledby="el-pref-{{ $key }}"{!! \App\Filament\Support\ConsoleV2::any() ? ' data-radio-keys' : '' !!}>
                    @foreach ($options as $value => $label)
                        <button type="button" role="radio" x-bind:aria-checked="value === '{{ $value }}' ? 'true' : 'false'" x-bind:tabindex="value === '{{ $value }}' ? 0 : -1"
                            x-on:click="pick('{{ $value }}')">{{ __($label) }}</button>
                    @endforeach
                </span>
            </span>
        </div>
    @endforeach
</x-el.section>
@if ($design['can_try'])
    {{-- Since 2.0.0-g: the design switch, from classic. --}}
    <x-el.section :footer="__('See the redesigned screens, here and in the apps. You can switch back to classic at any time.')">
        <button type="button" class="el-set-row" id="setting-appearance.design" wire:click="tryNewDesign" data-design-switch="v2">
            <span class="el-set-text"><span class="el-set-title">{{ __('Try the new design') }}</span></span>
            <span class="el-set-control" aria-hidden="true">@svg('heroicon-o-chevron-right', ['class' => 'el-set-chevron v2-icon-flip'])</span>
        </button>
    </x-el.section>
@elseif ($design['can_switch_back'])
    {{-- Since 2.0.0-a ("Switch back to classic"); 2.0.0-g: the design switch, from the new design. --}}
    <x-el.section :footer="__('Turns the redesigned screens off for you, here and in the apps. You can try them again at any time.')">
        <button type="button" class="el-set-row" id="setting-appearance.design" wire:click="switchBackToClassic" data-design-switch="classic">
            <span class="el-set-text"><span class="el-set-title">{{ __('Switch back to classic') }}</span></span>
            <span class="el-set-control" aria-hidden="true">@svg('heroicon-o-chevron-right', ['class' => 'el-set-chevron v2-icon-flip'])</span>
        </button>
    </x-el.section>
@endif
<p class="el-set-foot">The theme is kept in this browser. The others follow your account.</p>
<div class="el-btn-row"><button type="button" class="el-btn el-btn-text" wire:click="mountAction('resetPage')">Reset to defaults</button></div>
