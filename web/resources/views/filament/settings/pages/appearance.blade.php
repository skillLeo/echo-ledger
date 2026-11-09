{{--
    Settings › Appearance: the theme (this browser), the sidebar, the density, 12- or 24-hour times and the Library as a
    list or a table. Each is saved the moment it is chosen; a check says so beside it.
--}}
<x-el.section>
    <div class="el-set-row is-stacked" id="setting-appearance.theme" tabindex="-1" data-setting="appearance.theme"
        x-data="{ theme: localStorage.getItem('filament:theme:mode') || 'system', saved: false,
            pick(value) { this.theme = value; window.dispatchEvent(new CustomEvent('theme-changed', { detail: value })); this.saved = true; setTimeout(() => this.saved = false, 2000) } }"
        x-on:theme-changed.window="theme = $event.detail">
        <span class="el-set-text"><span class="el-set-title" id="el-pref-theme">Theme</span></span>
        <span class="el-set-control" style="align-items:center;gap:8px">
            <span class="el-set-saved" x-show="saved" x-cloak aria-hidden="true">@svg('heroicon-m-check')</span>
            <span class="el-segmented" role="radiogroup" aria-labelledby="el-pref-theme">
                @foreach (['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label)
                    <button type="button" role="radio" x-bind:aria-checked="theme === '{{ $value }}' ? 'true' : 'false'" x-bind:tabindex="theme === '{{ $value }}' ? 0 : -1"
                        x-on:click="pick('{{ $value }}')">{{ $label }}</button>
                @endforeach
            </span>
        </span>
    </div>
    @foreach ($rows as $key => [$id, $options])
        @php($entry = \App\Services\Accounts\SettingsRegistry::byId()[$id])
        <div class="el-set-row is-stacked" id="setting-{{ $id }}" tabindex="-1" data-setting="{{ $id }}" x-data="{ value: @js($prefs[$key]), saved: false,
                pick(v) { this.value = v; this.saved = false; $wire.setPreference('{{ $key }}', v).then((ok) => { this.saved = ok; setTimeout(() => this.saved = false, 2000) }) } }">
            <span class="el-set-text"><span class="el-set-title" id="el-pref-{{ $key }}">{{ $entry['title'] }}</span></span>
            <span class="el-set-control" style="align-items:center;gap:8px">
                <span class="el-set-saved" x-show="saved" x-cloak aria-hidden="true">@svg('heroicon-m-check')</span>
                <span class="el-segmented" role="radiogroup" aria-labelledby="el-pref-{{ $key }}">
                    @foreach ($options as $value => $label)
                        <button type="button" role="radio" x-bind:aria-checked="value === '{{ $value }}' ? 'true' : 'false'" x-bind:tabindex="value === '{{ $value }}' ? 0 : -1"
                            x-on:click="pick('{{ $value }}')">{{ $label }}</button>
                    @endforeach
                </span>
            </span>
        </div>
    @endforeach
</x-el.section>
<p class="el-set-foot">The theme is kept in this browser. The others follow your account.</p>
<div class="el-btn-row"><button type="button" class="el-btn el-btn-text" wire:click="mountAction('resetPage')">Reset to defaults</button></div>
