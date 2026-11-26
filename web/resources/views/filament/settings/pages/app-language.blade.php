    {{-- Since v2.0 (ui_v2.shell): the console's own language; transcripts keep theirs (Settings › Language). --}}
    <div class="el-set-row is-stacked" id="setting-appearance.app_language" tabindex="-1" data-setting="appearance.app_language" x-data="{ value: @js($prefs['app_language']), saved: false,
            pick(v) { this.value = v; this.saved = false; $wire.setPreference('app_language', v).then((ok) => { this.saved = ok }) } }">
        <span class="el-set-text">
            <span class="el-set-title" id="el-pref-app_language">{{ __('Language') }}</span>
            <span class="el-set-sub">{{ __('The console\'s words and direction. Transcripts keep their own language.') }}</span>
        </span>
        <span class="el-set-control" style="align-items:center;gap:8px">
            <span class="el-set-saved" x-show="saved" x-cloak aria-hidden="true">@svg('heroicon-m-check')</span>
            <span class="el-segmented" role="radiogroup" aria-labelledby="el-pref-app_language"{!! \App\Filament\Support\ConsoleV2::any() ? ' data-radio-keys' : '' !!}>
                @foreach ($appLanguages as $value => $label)
                    <button type="button" role="radio" lang="{{ $value }}" x-bind:aria-checked="value === '{{ $value }}' ? 'true' : 'false'" x-bind:tabindex="value === '{{ $value }}' ? 0 : -1"
                        x-on:click="pick('{{ $value }}')">{{ $label }}</button>
                @endforeach
            </span>
        </span>
    </div>
