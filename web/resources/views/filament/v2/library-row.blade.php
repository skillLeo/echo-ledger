{{--
    One v2.0 Library row: the source's own mark with the device it came from, the unread dot and the title, then one status (only
    what needs you), the second line (the source, the device, the project or caller) and its labels' colour dots, then
    "Audio · N days left" and a snippet of what was said; the flag when it is important; the star pins it to the top;
    at the end the time above the length. From 1024 px a plain click opens the recording beside the list, as in the
    classic Library (Ctrl, Cmd, Shift and middle clicks open its page). data-important feeds the selection's Mark /
    Unmark important; with data-pinned, data-opened, data-state, data-project and data-labels it is what the row's right-click menu
    shows (UX-22, filament.v2.context-menu). Since 2.0.0-p a click on the source's mark or a label's dot filters the Library by it (another
    click takes it away), and the select box has its own place before the mark, shown on the row's hover.
--}}
@php($row = \App\Filament\Support\V2RecordingRow::for($getRecord()))
<div class="v2-lib-row" data-recording="{{ $row['id'] }}" data-important="{{ $row['important'] ? '1' : '0' }}" data-pinned="{{ $row['pinned'] ? '1' : '0' }}" data-opened="{{ $row['unread'] ? '0' : '1' }}"
    data-state="{{ $row['state'] }}" data-project="{{ $row['project'] ?? '' }}" data-labels="{{ json_encode($row['label_keys'], JSON_UNESCAPED_UNICODE) }}"
    x-on:click="if (window.matchMedia('(min-width: 1024px)').matches && ! ($event.ctrlKey || $event.metaKey || $event.shiftKey || $event.button !== 0) && typeof $wire.selectRecording === 'function') { $event.preventDefault(); $wire.selectRecording('{{ $row['id'] }}') }">
    {{-- The app's own mark and the device badge (EL-Design's source-logo); a click shows only that source's recordings. --}}
    <button type="button" class="v2-row-tile" title="{{ __('Show only :source recordings', ['source' => $row['quick_source_words']]) }}" style="background-color: transparent"
        x-on:click.prevent.stop="$wire.quickFilter('source', @js($row['quick_source']))" data-quick-source="{{ $row['quick_source'] }}">
        @include('filament.v2.components.source-logo', ['app' => $row['app'], 'device' => $row['device_kind'], 'label' => $row['source_words'], 'fallback' => $row['icon']])
    </button>
    <span class="v2-row-main">
        <span class="v2-lib-line">
            @if ($row['unread'])
                <span class="v2-unread-dot" role="img" aria-label="{{ __('Not opened yet') }}" title="{{ __('Not opened yet') }}" data-unread></span>
            @endif
            <span class="v2-row-title" dir="auto">{{ $row['title'] }}</span>
        </span>
        <span class="v2-lib-line">
            @if ($row['status'] !== null)
                <span @class(['v2-status', 'is-'.$row['status']['tone']])>
                    <x-el.v2.icon :name="$row['status']['icon']" />{{ $row['status']['label'] }}
                </span>
            @elseif ($row['converting'] !== null)
                @if ($row['converting']['state'] === 'failed')
                    <span class="v2-status is-danger" data-convert-failed><x-el.v2.icon name="alert" />{{ $row['converting']['label'] }}</span>
                    @unless (\App\Services\Workspaces\ConsoleAccess::readOnly())
                        <button type="button" class="v2-row-retry" x-on:click.stop="$wire.retryViews('{{ $row['id'] }}')" data-retry-views="{{ $row['id'] }}">{{ __('Try again') }}</button>
                    @endunless
                @else
                    <span class="v2-status is-neutral" data-converting><span class="v2-spinner" aria-hidden="true"></span>{{ $row['converting']['label'] }}</span>
                @endif
            @endif
            <span class="v2-row-meta"><bdi>{{ $row['line'] }}</bdi></span>
            @foreach ($row['labels'] as $label)
                {{-- The dot as it was (named for screen readers); the button around it shows only that label's recordings. --}}
                <button type="button" class="v2-label-quick" aria-label="{{ __('Show only the :label label', ['label' => $label['name']]) }}" title="{{ __('Show only the :label label', ['label' => $label['name']]) }}"
                    x-on:click.prevent.stop="$wire.quickFilter('labels', @js(mb_strtolower($label['name'])))" data-quick-label="{{ mb_strtolower($label['name']) }}"><span class="v2-label-dot {{ $label['class'] }}" role="img" aria-label="{{ __('Label: :name', ['name' => $label['name']]) }}" title="{{ $label['name'] }}"
                    data-label-dot="{{ $label['name'] }}" @if ($label['own'] !== null) style="--label-own: {{ $label['own'] }}" @endif></span></button>
            @endforeach
        </span>
        {{-- A line of its own, so on a phone the days stay whole beside the status; the snippet follows and ends in "…". --}}
        @if ($row['left'] !== null || $row['snippet'] !== null)
            <span class="v2-lib-line">
                @if ($row['left'] !== null)
                    <span @class(['v2-status', $row['left']['soon'] ? 'is-warning' : 'is-neutral']) data-days-left><x-el.v2.icon name="clock" />{{ $row['left']['text'] }}</span>
                @endif
                @if ($row['snippet'] !== null)
                    <span class="v2-row-meta" dir="auto" data-snippet>{{ $row['snippet'] }}</span>
                @endif
            </span>
        @endif
    </span>
    @if ($row['important'])
        <span class="v2-status is-warning" role="img" aria-label="{{ __('Important') }}" title="{{ __('Important') }}" data-important-flag>
            <x-el.v2.icon name="flag" :filled="true" />
        </span>
    @endif
    @unless (\App\Services\Workspaces\ConsoleAccess::readOnly())
        <button type="button" @class(['v2-row-pin', 'is-on' => $row['pinned']]) x-on:click.stop="$wire.togglePin('{{ $row['id'] }}')"
            aria-pressed="{{ $row['pinned'] ? 'true' : 'false' }}" aria-label="{{ $row['pinned'] ? __('Unpin') : __('Pin to the top') }}"
            title="{{ $row['pinned'] ? __('Unpin') : __('Pin to the top') }}" data-pin="{{ $row['id'] }}">
            <x-el.v2.icon name="star" :filled="$row['pinned']" />
        </button>
    @endunless
    <span class="v2-row-value">
        <time datetime="{{ $row['iso'] }}" title="{{ $row['full_time'] }}">{{ $row['time'] }}</time>
        <span class="v2-row-length">{{ $row['length'] }}</span>
    </span>
</div>
