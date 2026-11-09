{{--
    Account → Preferences (since v1.6, P4.5): each choice is saved the moment it is made, and "Saved · just now" says
    so (then "Saved · 2 min ago"). The theme is in the menu under your initials.
--}}
<div class="el-prefs">
    <div class="el-prefs-saved" aria-live="polite" wire:key="prefs-saved-{{ $savedAt ?? 'never' }}"
        x-data="{ at: @js($savedAt), now: Date.now(), init() { setInterval(() => this.now = Date.now(), 30000) },
            get line() { if (! this.at) return ''; const m = Math.floor((this.now - Date.parse(this.at)) / 60000); return 'Saved · ' + (m < 1 ? 'just now' : (m === 1 ? '1 min ago' : m + ' min ago')); } }">
        <span x-show="at" x-cloak data-saved>@svg('heroicon-m-check', ['aria-hidden' => 'true']) <span x-text="line"></span></span>
    </div>

    @foreach ($choices as $key => [$label, $options, $help])
        <section class="el-pref" data-pref="{{ $key }}">
            <div class="el-pref-text">
                <h3 class="el-pref-label" id="pref-{{ $key }}">{{ $label }}</h3>
                <p class="el-muted">{{ $help }}</p>
            </div>
            <div class="el-seg" role="group" aria-labelledby="pref-{{ $key }}">
                @foreach ($options as $value => $text)
                    <button type="button" aria-pressed="{{ $prefs[$key] === (string) $value ? 'true' : 'false' }}"
                        wire:click="setPreference('{{ $key }}', '{{ $value }}')" data-value="{{ $value }}">{{ $text }}</button>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="el-pref" data-pref="language">
        <div class="el-pref-text">
            <h3 class="el-pref-label" id="pref-language">Language of your uploads</h3>
            <p class="el-muted">What a file you upload is transcribed as, unless you choose another for it. Automatic hears it.</p>
        </div>
        <x-filament::input.wrapper class="el-pref-select">
            <x-filament::input.select aria-labelledby="pref-language" x-data x-on:change="$wire.setPreference('language', $event.target.value)">
                <option value="" @selected($prefs['language'] === '')>Automatic</option>
                @foreach ($languages as $group => $choice)
                    @if (is_array($choice))
                        <optgroup label="{{ $group }}">
                            @foreach ($choice as $code => $name)
                                <option value="{{ $code }}" @selected($prefs['language'] === (string) $code)>{{ $name }}</option>
                            @endforeach
                        </optgroup>
                    @elseif ((string) $group !== '' && (string) $group !== 'auto')
                        <option value="{{ $group }}" @selected($prefs['language'] === (string) $group)>{{ $choice }}</option>
                    @endif
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </section>

    <section class="el-pref" data-pref="shortcuts">
        <div class="el-pref-text">
            <h3 class="el-pref-label">Shortcuts</h3>
            <p class="el-muted">Ctrl K (⌘K on a Mac) searches and runs commands, / searches the list, [ folds the sidebar, J and K move through the Library, Enter opens, X selects, Esc closes, ? lists them all.</p>
        </div>
        <button type="button" class="el-pill" x-data x-on:click="$dispatch('el-help')">@svg('heroicon-o-question-mark-circle', ['aria-hidden' => 'true']) Show all</button>
    </section>
</div>
