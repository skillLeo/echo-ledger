{{-- Settings › Language: the language new recordings are transcribed in. --}}
<x-el.section footer="The language your recordings are transcribed in. Roman Urdu and English views are chosen on each recording.">
    <div class="el-set-row is-stacked" id="setting-transcription.language" tabindex="-1" data-setting="transcription.language"
        x-data="{ saved: false }">
        <label class="el-set-text" for="el-language"><span class="el-set-title">Language</span></label>
        <span class="el-set-control" style="align-items:center;gap:8px">
            <span class="el-set-saved" x-show="saved" x-cloak aria-hidden="true">@svg('heroicon-m-check')</span>
            <select id="el-language" class="el-select" x-on:change="saved = false; $wire.setPreference('language', $event.target.value).then((ok) => { saved = ok; setTimeout(() => saved = false, 2000) })">
                @foreach ($languages as $code => $name)
                    <option value="{{ $code }}" @selected($prefs['language'] === (string) $code)>{{ $name }}</option>
                @endforeach
                <optgroup label="More languages">
                    @foreach ($more as $code => $name)
                        <option value="{{ $code }}" @selected($prefs['language'] === (string) $code)>{{ $name }}</option>
                    @endforeach
                </optgroup>
            </select>
        </span>
    </div>
</x-el.section>
<div class="el-btn-row"><button type="button" class="el-btn el-btn-text" wire:click="mountAction('resetPage')">Reset to defaults</button></div>
