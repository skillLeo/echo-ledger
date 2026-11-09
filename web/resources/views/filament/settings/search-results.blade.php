{{--
    Settings search (since v1.7): each setting with the start of the matched words in bold, where it is ("Settings ›
    Appearance › Theme") and its value; then questions from Help. ↑ ↓ move, Enter opens, Esc clears.
--}}
@php
    use App\Services\Accounts\SettingsIndex;

    $count = count($results['settings']) + count($results['help']);
@endphp
<h1 class="el-set-h1">Results for “{{ $query }}”</h1>
<p class="sr-only" role="status" aria-live="polite">{{ $count === 1 ? '1 result' : $count.' results' }}</p>
<div id="el-set-results" data-settings-results>
    @if ($results['settings'] !== [])
        <x-el.section title="Settings">
            @foreach ($results['settings'] as $hit)
                @php($entry = $hit['entry'])
                @php($value = $context->tag($entry) === null ? $context->value($entry) : null)
                <a href="{{ $context->url($entry) }}" class="el-set-row has-icon el-result" data-result="{{ $entry['id'] }}"
                    aria-label="{{ $entry['title'] }}, in {{ SettingsIndex::breadcrumb($entry) }}{{ $value ? ', '.$value : '' }}">
                    @svg($this->resultIcon($entry), ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
                    <span class="el-set-text">
                        <span class="el-set-title">{!! SettingsIndex::marked((string) $entry['title'], $query) !!}</span>
                        <span class="el-set-sub">{{ SettingsIndex::breadcrumb($entry) }}</span>
                    </span>
                    @if ($context->tag($entry))
                        <span class="el-tag">{{ $context->tag($entry) }}</span>
                    @elseif (in_array($entry['id'], ['appearance', 'appearance.theme'], true))
                        <span class="el-set-value" x-data x-text="({ light: 'Light', dark: 'Dark' })[localStorage.getItem('filament:theme:mode')] ?? 'System'">System</span>
                    @elseif ($value)
                        <span class="el-set-value">{{ $value }}</span>
                    @endif
                </a>
            @endforeach
        </x-el.section>
    @endif

    @if ($results['help'] !== [])
        <x-el.section title="Help">
            @foreach ($results['help'] as $hit)
                @php($entry = $hit['entry'])
                <a href="{{ $context->url($entry) }}" class="el-set-row has-icon el-result" data-result="{{ $entry['id'] }}">
                    @svg('heroicon-o-question-mark-circle', ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
                    <span class="el-set-text">
                        <span class="el-set-title">{!! SettingsIndex::marked((string) $entry['title'], $query) !!}</span>
                        <span class="el-set-sub">{{ \Illuminate\Support\Str::before((string) $entry['answer'], '. ').(str_contains((string) $entry['answer'], '. ') ? '.' : '') }}</span>
                    </span>
                </a>
            @endforeach
        </x-el.section>
    @endif

    @if ($count === 0)
        <div class="el-set-box el-set-empty" data-settings-empty>
            <p class="el-set-empty-title">No settings match “{{ $query }}”</p>
            <p class="el-set-sub" style="margin-top:4px">Check the spelling or try another word.</p>
        </div>
        @if ($results['closest'] !== [])
            <x-el.section title="Closest matches">
                @foreach ($results['closest'] as $entry)
                    <a href="{{ $context->url($entry) }}" class="el-set-row has-icon el-result" data-result="{{ $entry['id'] }}">
                        @svg($this->resultIcon($entry), ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
                        <span class="el-set-text">
                            <span class="el-set-title">{{ $entry['title'] }}</span>
                            <span class="el-set-sub">{{ SettingsIndex::breadcrumb($entry) }}</span>
                        </span>
                    </a>
                @endforeach
            </x-el.section>
        @endif
        <x-el.section>
            <x-el.row title="Search help" icon="heroicon-o-question-mark-circle" :href="\App\Filament\Auth\EditProfile::settingsUrl('help')" chevron />
        </x-el.section>
    @endif
</div>
