{{--
    The Library's filter panel (docs/PLAN_LIBRARY_FILTERS.md section 1, contract 2.0.0-p "Library filters"): left of
    the list, closed until [Filters] or F opens it, Esc closes it, and the browser remembers open or closed; under the
    panel breakpoint it is a drawer over the list. Each section has its options with live counts (each section counts
    with the others' filters, not its own), is / is not, its own Clear and, for long lists, a search box. A change is
    sent 150 ms after the last click; Save as view keeps the result in My views.
--}}
@php
    $sections = $this->filterPanel();
    // With the three levels the panel also says how many recordings the list holds: beside the list (from the wide
    // width) the toolbar has no room for it, and the table's own count folds away with its narrower box.
    $total = \App\Filament\Support\ConsoleV2::frame() ? (int) (collect($this->libraryTabs())->firstWhere('active', true)['count'] ?? 0) : null;
    $totalHtml = $total === null ? '' : ' <span class="v2-lib-panel-total" data-filters-total>'
        .e($total === 1 ? __(':n recording', ['n' => 1]) : __(':n recordings', ['n' => \Illuminate\Support\Number::format($total)])).'</span>';
@endphp
<div class="v2-lib-scrim" x-show="drawer && filtersOpen" x-cloak x-on:click="closeFilters()" aria-hidden="true"></div>
{{-- Beside the list it is a region of the page; as a drawer it is a modal dialog that keeps the focus inside until closed. --}}
<aside class="v2-lib-panel" id="v2-lib-filters" x-show="filtersOpen" x-cloak aria-label="{{ __('Filters') }}"
    x-bind:role="drawer ? 'dialog' : null" x-bind:aria-modal="drawer ? 'true' : null" x-trap.noscroll="drawer && filtersOpen"
    x-on:keydown.escape.stop="closeFilters()" data-filter-panel
    x-data="{ queued: null, send() { clearTimeout(this.queued); this.queued = setTimeout(() => { const filters = this.read(); if (filters !== null) $wire.applyFilters(filters) }, 150) },
        read() {
            const out = {};
            let waiting = false;
            $el.querySelectorAll('[data-section]').forEach((s) => {
                const name = s.dataset.section;
                const op = s.querySelector('[data-op]:checked')?.value || 'is';
                const values = Array.from(s.querySelectorAll('[data-value]:checked')).map((b) => b.value);
                const from = s.querySelector('[data-from]')?.value || null;
                const to = s.querySelector('[data-to]')?.value || null;
                // Custom dates wait for a day: until one is set nothing is sent and the list stays as it is.
                if (name === 'date' && values[0] === 'custom' && ! from && ! to) waiting = true;
                if (values.length) out[name] = { op, values, ...(name === 'date' && values[0] === 'custom' ? { from, to } : {}) };
            });
            return waiting ? null : out;
        } }"
    x-on:change="if ($event.target.closest('[data-section]') && ! $event.target.matches('[data-find]')) send()">
    <header class="v2-lib-panel-head">
        <h2 class="v2-lib-panel-title">{{ __('Filters') }}{!! $totalHtml !!}</h2>
        <button type="button" class="v2-icon-btn" x-on:click="closeFilters()" aria-label="{{ __('Close filters') }}" title="{{ __('Close filters') }} (Esc)">
            <x-el.v2.icon name="close" />
        </button>
    </header>
    <div class="v2-lib-panel-body">
        @foreach ($sections as $section)
            <section class="v2-lib-sec" data-section="{{ $section['key'] }}" aria-labelledby="v2-lib-sec-{{ $section['key'] }}"
                x-data="{ find: '', custom: @js(($section['values'][0] ?? null) === 'custom') }">
                <div class="v2-lib-sec-head">
                    <h3 class="v2-lib-sec-title" id="v2-lib-sec-{{ $section['key'] }}">{{ $section['label'] }}</h3>
                    @if ($section['on'])
                        <button type="button" class="v2-link v2-lib-sec-clear" wire:click="removeFilter('{{ $section['key'] }}')" data-clear-section="{{ $section['key'] }}">{{ __('Clear') }}</button>
                    @endif
                </div>
                @if ($section['key'] !== 'date')
                    <div class="v2-seg v2-lib-op" role="radiogroup" aria-label="{{ __(':section filter', ['section' => $section['label']]) }}">
                        @foreach (['is' => __('Is'), 'is_not' => __('Is not')] as $op => $words)
                            <label @class(['v2-seg-item', 'is-on' => $section['op'] === $op]) for="lf-{{ $section['key'] }}-op-{{ $op }}">
                                <input type="radio" class="v2-sr" id="lf-{{ $section['key'] }}-op-{{ $op }}" name="op-{{ $section['key'] }}" value="{{ $op }}" data-op @checked($section['op'] === $op)>
                                {{ $words }}
                            </label>
                        @endforeach
                    </div>
                @endif
                @if (count($section['options']) > 8)
                    <input type="search" class="v2-lib-find" x-model="find" data-find placeholder="{{ __('Find :section', ['section' => mb_strtolower($section['label'])]) }}"
                        aria-label="{{ __('Find :section', ['section' => mb_strtolower($section['label'])]) }}">
                @endif
                <ul class="v2-lib-opts" role="list">
                    @foreach ($section['options'] as $option)
                        @php($inputId = 'lf-'.$section['key'].'-'.substr(md5($option['value']), 0, 10))
                        {{-- Every option shows until the find box narrows them. --}}
                        <li x-bind:hidden="find !== '' && ! @js(mb_strtolower($option['label'])).includes(find.toLowerCase())" @class(['is-child' => $option['child'] ?? false])>
                            <label class="v2-lib-opt" for="{{ $inputId }}">
                                <input type="{{ $section['key'] === 'date' ? 'radio' : 'checkbox' }}" id="{{ $inputId }}" name="{{ $section['key'] }}" value="{{ $option['value'] }}" data-value
                                    @checked($option['on']) @if ($section['key'] === 'date') x-on:change="custom = $event.target.value === 'custom'" @endif>
                                @if (($option['color'] ?? null) !== null || $section['key'] === 'labels')
                                    <span class="v2-label-dot {{ $option['dot'] ?? '' }}" aria-hidden="true" @if (($option['own'] ?? null) !== null) style="--label-own: {{ $option['own'] }}" @endif></span>
                                @endif
                                <span class="v2-lib-opt-label" dir="auto"><bdi>{{ $option['label'] }}</bdi>
                                    {{-- In progress's parts, from the glossary's states (2.0.0-r): "2 transcribing · 1 waiting to upload". --}}
                                    @if (($option['parts'] ?? null) !== null)
                                        <span class="v2-lib-opt-parts" data-parts><bdi>{{ $option['parts'] }}</bdi></span>
                                    @endif
                                </span>
                                @if ($option['count'] !== null)
                                    <span class="v2-lib-opt-count">{{ \Illuminate\Support\Number::format($option['count']) }}</span>
                                @endif
                            </label>
                        </li>
                    @endforeach
                </ul>
                @if ($section['key'] === 'date')
                    <div class="v2-lib-range" x-show="custom" @if (($section['values'][0] ?? null) !== 'custom') x-cloak @endif data-range>
                        <label for="lf-date-from">{{ __('From (date)') }} <input type="date" id="lf-date-from" data-from value="{{ $section['from'] ?? '' }}"></label>
                        <label for="lf-date-to">{{ __('To (date)') }} <input type="date" id="lf-date-to" data-to value="{{ $section['to'] ?? '' }}"></label>
                    </div>
                @endif
            </section>
        @endforeach
    </div>
    {{-- Any role keeps views of their own: a view changes nothing of the workspace. --}}
    <footer class="v2-lib-panel-foot">
        <button type="button" class="v2-chip" wire:click="mountAction('saveView')" data-save-view>
            <x-el.v2.icon name="plus" />{{ __('Save as view') }}
        </button>
    </footer>
</aside>
