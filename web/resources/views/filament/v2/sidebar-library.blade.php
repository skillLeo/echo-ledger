{{--
    The Library's part of the v2.0 sidebar (docs/PLAN_LIBRARY_FILTERS.md section 1, contract 2.0.0-p): LIBRARY (All,
    Needs you, Unread, Important, Audio only, then Shared, which opens to Shared with me, My links and
    Request links), MY VIEWS (the person's saved views and + New view; each one's ⋯ renames, moves or deletes it, and
    a drag reorders them) and LABELS (the person's lists with their colours, + New label). Counts are quiet; only Needs
    you and Unread are strong. The first nine places answer ⌘1…⌘9 (Alt+1…9 where the browser keeps ⌘ for its tabs).
--}}
@php
    $nav = \App\Filament\Support\LibraryFilterBar::sidebar($this->here ?? null);
    $readOnly = \App\Services\Workspaces\ConsoleAccess::readOnly();
    $sharedOpen = collect($nav['shared'])->contains('active', true);
@endphp
@if ($nav['places'] !== [])
    <div class="v2-sb-lib" data-sidebar-library>
        @if ($inPanel ?? false)
            {{-- In the Library's panel (docs/PLAN_NAV_3_LEVELS.md section 3) the places are its Views. --}}
            <p class="v2-panel-group-title" id="v2-panel-views">{{ __('Views') }}</p>
        @endif
        <ul class="v2-sb-sub" aria-label="{{ ($inPanel ?? false) ? __('Views') : __('Library') }}">
            @foreach ($nav['places'] as $place)
                <li>
                    <a href="{{ $place['url'] }}" @class(['v2-sb-subitem', 'is-on' => $place['active']]) @if ($place['active']) aria-current="page" @endif
                        data-place="{{ $place['key'] }}" data-shortcut="{{ $place['shortcut'] }}">
                        <x-el.v2.icon :name="$place['icon']" />
                        <span class="v2-sb-label">{{ $place['label'] }}</span>
                        @if ($place['count'] > 0)
                            <span @class(['v2-sb-subcount', 'is-strong' => $place['strong']])>{{ \Illuminate\Support\Number::format($place['count']) }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
            @if ($nav['shared'] !== [])
                {{-- Shared opens to Shared with me, My links and Request links (open until folded). --}}
                <li>
                    {{-- Folded until one of its pages is open, so the groups below stay in view. --}}
                    <details class="v2-sb-fold" @if ($sharedOpen) open @endif>
                        <summary class="v2-sb-subitem" data-place="shared-group">
                            <x-el.v2.icon name="users" />
                            <span class="v2-sb-label">{{ __('Shared') }}</span>
                            <x-el.v2.icon name="chevron-down" class="v2-sb-caret" />
                        </summary>
                        <ul class="v2-sb-sub is-nested" id="v2-sb-shared">
                            @foreach ($nav['shared'] as $item)
                                <li>
                                    <a href="{{ $item['url'] }}" @class(['v2-sb-subitem', 'is-on' => $item['active']]) @if ($item['active']) aria-current="page" @endif data-place="{{ $item['key'] }}">
                                        <span class="v2-sb-label">{{ $item['label'] }}</span>
                                        @if ($item['count'] > 0)
                                            <span class="v2-sb-subcount">{{ \Illuminate\Support\Number::format($item['count']) }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </li>
            @endif
        </ul>

        {{-- MY VIEWS and LABELS fold (native disclosures, open until folded); + sits beside each heading. --}}
        <div class="v2-sb-group">
            <details class="v2-sb-fold" open data-views-fold>
                <summary class="v2-sb-group-title">{{ __('My views') }}</summary>
                <ul class="v2-sb-sub" id="v2-sb-views" aria-label="{{ __('My views') }}"
                    x-data="{ dragging: null, drop(target) { if (! this.dragging || this.dragging === target) return; const ids = Array.from($el.querySelectorAll('[data-view-id]')).map((e) => e.dataset.viewId);
                        const from = ids.indexOf(this.dragging); ids.splice(from, 1); ids.splice(ids.indexOf(target) + (from <= ids.indexOf(target) ? 1 : 0), 0, this.dragging); $wire.orderViews(ids); this.dragging = null } }">
                @forelse ($nav['views'] as $i => $view)
                    {{-- UX-22 3b: its right-click menu, its ⋯ and Shift+F10 open the same menu (filament.v2.context-menu-script). --}}
                    <li class="v2-sb-view" data-view-id="{{ $view['id'] }}" draggable="{{ $readOnly ? 'false' : 'true' }}" x-on:dragstart="dragging = @js($view['id'])" x-on:dragover.prevent x-on:drop.prevent="drop(@js($view['id']))"
                        data-v2-object="view" data-v2-object-id="{{ $view['id'] }}" data-v2-object-not="{{ trim(($i === 0 ? 'view.moveUp' : '').' '.($i === count($nav['views']) - 1 ? 'view.moveDown' : '')) }}">
                        <a href="{{ $view['url'] }}" @class(['v2-sb-subitem', 'is-on' => $view['active']]) @if ($view['active']) aria-current="page" @endif
                            data-view="{{ $view['id'] }}" data-shortcut="{{ $view['shortcut'] ?? '' }}">
                            <x-el.v2.icon name="tune" />
                            <span class="v2-sb-label" dir="auto"><bdi>{{ $view['label'] }}</bdi></span>
                            @if ($view['count'] > 0)
                                <span class="v2-sb-subcount">{{ \Illuminate\Support\Number::format($view['count']) }}</span>
                            @endif
                        </a>
                        {{-- A read-only console changes no view, so the ⋯ would open an empty menu. --}}
                        @unless ($readOnly)
                            <button type="button" class="v2-icon-btn v2-sb-more" aria-label="{{ __('More for :name', ['name' => $view['label']]) }}" aria-haspopup="menu" aria-expanded="false"
                                data-view-menu="{{ $view['id'] }}" x-on:click="window.elV2ContextMenu && window.elV2ContextMenu.openObjectFromButton($el, $event)">
                                <x-el.v2.icon name="dots" />
                            </button>
                        @endunless
                    </li>
                @empty
                    <li class="v2-sb-empty">{{ __('Save filters as a view to keep them here.') }}</li>
                @endforelse
                </ul>
            </details>
            <a href="{{ \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('index', ['compose' => 'view']) }}" class="v2-icon-btn v2-sb-add"
                x-on:click="if (document.querySelector('.v2-library')) { $event.preventDefault(); window.dispatchEvent(new CustomEvent('el-new-view')) }"
                aria-label="{{ __('New view') }}" title="{{ __('New view') }}" data-new-view>
                <x-el.v2.icon name="plus" />
            </a>
        </div>

        <div class="v2-sb-group">
            <details class="v2-sb-fold" open data-labels-fold>
                <summary class="v2-sb-group-title">{{ __('Labels') }}</summary>
                <ul class="v2-sb-sub" id="v2-sb-labels" aria-label="{{ __('Labels') }}">
                @forelse ($nav['labels'] as $label)
                    {{-- Its colour's name (one of the eight, else none), for Colour › to tick the one it has. --}}
                    <li class="v2-sb-view" data-v2-object="label" data-v2-object-id="{{ $label['key'] }}" data-v2-object-colour="{{ $label['colour'] ?? '' }}">
                        <a href="{{ $label['url'] }}" @class(['v2-sb-subitem', 'is-on' => $label['active']]) @if ($label['active']) aria-current="page" @endif data-label="{{ $label['key'] }}">
                            <span class="v2-label-dot {{ $label['dot'] }}" aria-hidden="true" @if ($label['own'] !== null) style="--label-own: {{ $label['own'] }}" @endif></span>
                            <span class="v2-sb-label" dir="auto"><bdi>{{ $label['label'] }}</bdi></span>
                            @if ($label['count'] > 0)
                                <span class="v2-sb-subcount">{{ \Illuminate\Support\Number::format($label['count']) }}</span>
                            @endif
                        </a>
                        @unless ($readOnly)
                            <button type="button" class="v2-icon-btn v2-sb-more" aria-label="{{ __('More for :name', ['name' => $label['label']]) }}" aria-haspopup="menu" aria-expanded="false"
                                data-label-menu="{{ $label['key'] }}" x-on:click="window.elV2ContextMenu && window.elV2ContextMenu.openObjectFromButton($el, $event)">
                                <x-el.v2.icon name="dots" />
                            </button>
                        @endunless
                    </li>
                @empty
                    <li class="v2-sb-empty">{{ __('No labels yet.') }}</li>
                @endforelse
                </ul>
            </details>
            @unless ($readOnly)
                <button type="button" class="v2-icon-btn v2-sb-add" wire:click="mountAction('newLabel')" aria-label="{{ __('New label') }}" title="{{ __('New label') }}" data-new-label>
                    <x-el.v2.icon name="plus" />
                </button>
            @endunless
        </div>
    </div>
@endif
