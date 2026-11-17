{{--
    Moments (v2.0): the screens of the recording, as the desktop app's viewer. Each one with its moment, its
    description, the name the meeting folder gives its file, and what was said around it; Play from here goes to that
    moment. A screen opens large, with previous and next (also the arrow keys) and Escape to close.
--}}
@php
    $record = $getRecord();
    $moments = \App\Filament\Support\V2RecordingMoments::for($record);
    $play = \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('view', ['record' => $record]);
@endphp
@if ($moments === [])
    <div class="v2-empty">
        <x-el.v2.icon name="captions" class="v2-empty-icon" />
        <p class="v2-empty-title">{{ __('No moments in this recording') }}</p>
        <p class="v2-empty-text">{{ __('Screens the computer app keeps during a meeting show here, with what was said around them.') }}</p>
    </div>
@else
    <div class="v2-moments" x-data="{ open: null, items: @js(array_map(fn (array $m): array => ['label' => $m['label'].' · '.$m['at'], 'image' => $m['image'], 'name' => $m['file_name'], 'description' => $m['description']], $moments)),
            step(by) { if (this.open === null) return; this.open = Math.min(this.items.length - 1, Math.max(0, this.open + by)) } }"
        x-on:keydown.escape.window="open = null" x-on:keydown.arrow-right.window="open !== null && step(document.documentElement.dir === 'rtl' ? -1 : 1)"
        x-on:keydown.arrow-left.window="open !== null && step(document.documentElement.dir === 'rtl' ? 1 : -1)">
        <ul class="v2-moment-list">
            @foreach ($moments as $i => $m)
                <li class="v2-moment" data-screen="{{ $m['id'] }}">
                    <button type="button" class="v2-moment-thumb" x-on:click="open = {{ $i }}" aria-label="{{ __('Open :label', ['label' => $m['label']]) }}">
                        <img src="{{ $m['thumb'] }}" alt="" loading="lazy">
                    </button>
                    <div class="v2-moment-main">
                        <p class="v2-moment-head">
                            <span class="v2-row-title">{{ $m['label'] }}</span>
                            <span class="v2-mono">{{ $m['at'] }}</span>
                        </p>
                        @if ($m['description'] !== null)
                            <p class="v2-moment-about" dir="auto">{{ $m['description'] }}</p>
                        @endif
                        @if ($m['said'] !== null)
                            <div class="v2-moment-said">
                                <p class="v2-group-title">{{ __('Said around it') }}</p>
                                <p dir="auto">{{ $m['said'] }}</p>
                            </div>
                        @endif
                        <p class="v2-moment-foot">
                            <a class="v2-link" href="{{ $play }}?t={{ $m['at_ms'] }}"><x-el.v2.icon name="play" />{{ __('Play from here') }}</a>
                            <a class="v2-moment-file v2-mono" href="{{ $m['image'] }}" download="{{ $m['file_name'] }}" title="{{ __('Download') }}">{{ $m['file_name'] }}</a>
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>

        <template x-if="open !== null">
            <div class="v2-viewer" role="dialog" aria-modal="true" x-bind:aria-label="items[open].label" x-on:click.self="open = null">
                <div class="v2-viewer-box">
                    <div class="v2-viewer-head">
                        <p class="v2-row-title" x-text="items[open].label"></p>
                        <span class="v2-row-meta" aria-live="polite" x-text="@js(__(':n of :count', ['n' => '__N__', 'count' => count($moments)])).replace('__N__', open + 1)"></span>
                        <button type="button" class="v2-icon-btn" x-on:click="open = null" aria-label="{{ __('Close') }}"><x-el.v2.icon name="close" /></button>
                    </div>
                    <div class="v2-viewer-body">
                        <button type="button" class="v2-icon-btn" x-on:click="step(-1)" x-bind:disabled="open === 0" aria-label="{{ __('Previous screen') }}"><x-el.v2.icon name="chevron-start" /></button>
                        <img x-bind:src="items[open].image" x-bind:alt="items[open].description || items[open].label">
                        <button type="button" class="v2-icon-btn" x-on:click="step(1)" x-bind:disabled="open === items.length - 1" aria-label="{{ __('Next screen') }}"><x-el.v2.icon name="chevron-end" /></button>
                    </div>
                    <p class="v2-row-meta" dir="auto" x-show="items[open].description" x-text="items[open].description"></p>
                </div>
            </div>
        </template>
    </div>
@endif
