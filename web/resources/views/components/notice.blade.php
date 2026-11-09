{{--
    An inline banner about something still true (since v1.6.1, docs/DESIGN_SYSTEM.md "Notices"): its link (class
    el-stretch) covers it, so a click anywhere opens where it is about; × "Dismiss" and a swipe left or right close it.
    It has no timer. Closed, it stays closed until [state] changes or the next visit; with remember="text" (the
    announcement) for good, per [text].
--}}
@props(['id', 'state' => '', 'href' => null, 'remember' => 'visit', 'text' => null, 'role' => 'status'])
<div x-data="(window.elBanner || (() => ({ open: true, dismiss() { this.open = false } })))(@js(['id' => (string) $id, 'state' => (string) $state, 'remember' => $remember, 'text' => $text]))"
    x-cloak x-show="open"
    {{ $attributes->class(['el-notice', 'el-has-target' => filled($href)]) }}
    data-notice="{{ $id }}" data-remember="{{ $remember }}" @if (filled($href)) data-el-target="{{ $href }}" @endif role="{{ $role }}">
    {{ $slot }}
    <button type="button" class="el-notice-close" x-on:click.stop="dismiss()" aria-label="Dismiss" title="Dismiss">
        @svg('heroicon-m-x-mark', ['aria-hidden' => 'true'])
    </button>
</div>
