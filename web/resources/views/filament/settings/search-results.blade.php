{{--
    Settings search (since v1.7): each setting with the start of the matched words in bold, where it is ("Settings ›
    Appearance › Theme") and its value; then questions from Help. ↑ ↓ move, Enter opens, Esc clears. Titles and paths
    read in the console's language.
--}}
@php
    use App\Services\Accounts\SettingsIndex;

    $count = count($results['settings']) + count($results['help']);
@endphp
<h1 class="el-set-h1">{{ __('Results for “:query”', ['query' => $query]) }}</h1>
<p class="sr-only" role="status" aria-live="polite">{{ trans_choice('1 result|:count results', $count, ['count' => $count]) }}</p>
<div id="el-set-results" data-settings-results>
    @if ($results['settings'] !== [])
        <x-el.section :title="__('Settings')">
            @foreach ($results['settings'] as $hit)
                @php($entry = $hit['entry'])
                @php($value = $context->tag($entry) === null ? $context->value($entry) : null)
                <a href="{{ $context->url($entry) }}" class="el-set-row has-icon el-result" data-result="{{ $entry['id'] }}"
                    aria-label="{{ __(':setting, in :place', ['setting' => SettingsIndex::title($entry), 'place' => SettingsIndex::breadcrumb($entry)]) }}{{ $value ? ', '.$value : '' }}">
                    @svg($this->resultIcon($entry), ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
                    <span class="el-set-text">
                        <span class="el-set-title">{!! SettingsIndex::marked(SettingsIndex::title($entry), $query) !!}</span>
                        <span class="el-set-sub">{{ SettingsIndex::breadcrumb($entry) }}</span>
                    </span>
                    @if ($context->tag($entry))
                        <span class="el-tag">{{ $context->tag($entry) }}</span>
                    @elseif (in_array($entry['id'], ['appearance', 'appearance.theme'], true))
                        <span class="el-set-value" x-data x-text="({ light: @js(__('Light')), dark: @js(__('Dark')) })[localStorage.getItem('filament:theme:mode')] ?? @js(__('System'))">{{ __('System') }}</span>
                    @elseif ($value)
                        <span class="el-set-value">{{ $value }}</span>
                    @endif
                </a>
            @endforeach
        </x-el.section>
    @endif

    @if ($results['help'] !== [])
        <x-el.section :title="__('Help')">
            @foreach ($results['help'] as $hit)
                @php($entry = $hit['entry'])
                @php($answer = __((string) $entry['answer']))
                @php($stop = str_contains($answer, '. ') ? '.' : (str_contains($answer, '۔ ') ? '۔' : null))
                <a href="{{ $context->url($entry) }}" class="el-set-row has-icon el-result" data-result="{{ $entry['id'] }}">
                    @svg('heroicon-o-question-mark-circle', ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
                    <span class="el-set-text">
                        <span class="el-set-title">{!! SettingsIndex::marked(SettingsIndex::title($entry), $query) !!}</span>
                        <span class="el-set-sub">{{ $stop === null ? $answer : \Illuminate\Support\Str::before($answer, $stop.' ').$stop }}</span>
                    </span>
                </a>
            @endforeach
        </x-el.section>
    @endif

    @if ($count === 0)
        <div class="el-set-box el-set-empty" data-settings-empty>
            <p class="el-set-empty-title">{{ __('No settings match “:query”', ['query' => $query]) }}</p>
            <p class="el-set-sub" style="margin-top:4px">{{ __('Check the spelling or try another word.') }}</p>
        </div>
        @if ($results['closest'] !== [])
            <x-el.section :title="__('Closest matches')">
                @foreach ($results['closest'] as $entry)
                    <a href="{{ $context->url($entry) }}" class="el-set-row has-icon el-result" data-result="{{ $entry['id'] }}">
                        @svg($this->resultIcon($entry), ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
                        <span class="el-set-text">
                            <span class="el-set-title">{{ SettingsIndex::title($entry) }}</span>
                            <span class="el-set-sub">{{ SettingsIndex::breadcrumb($entry) }}</span>
                        </span>
                    </a>
                @endforeach
            </x-el.section>
        @endif
        <x-el.section>
            <x-el.row :title="__('Search help')" icon="heroicon-o-question-mark-circle" :href="\App\Filament\Auth\EditProfile::settingsUrl('help')" chevron />
        </x-el.section>
    @endif
</div>
