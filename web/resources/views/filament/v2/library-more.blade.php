{{--
    The new Library's Load more (U9b, WEB-LIB-033): under the list while more recordings wait, the next chunk when it is
    pressed, or when it scrolls into view (the list keeps growing as it is read). The count above it stays the whole
    Library's ("Showing 1 to 50 of 2,000").
--}}
@php
    $records = $this->getTableRecords();
    $total = $records instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $records->total() : $records->count();
    $shown = $records->count();
    $next = min(\App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions::V2_CHUNK, max(0, $total - $shown));
@endphp
@if ($next > 0)
    <div class="v2-lib-more" data-load-more data-shown="{{ $shown }}" data-total="{{ $total }}"
        x-data="{ busy: false }"
        x-init="new IntersectionObserver((seen) => { if (seen.some((e) => e.isIntersecting) && ! busy) { busy = true; $wire.v2LoadMore().then(() => { busy = false }) } }, { rootMargin: '0% 0% 50% 0%' }).observe($el)">
        <button type="button" class="v2-btn" wire:click="v2LoadMore" wire:loading.attr="disabled" wire:target="v2LoadMore">
            {{ trans_choice('Show :count more|Show :count more', $next, ['count' => \Illuminate\Support\Number::format($next)]) }}
        </button>
    </div>
@endif
