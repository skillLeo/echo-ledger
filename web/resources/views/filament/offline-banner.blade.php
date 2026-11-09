{{--
    C6: a quiet notice while the browser is offline. Since v1.6.1 it follows the notice rule: no timer (it is still
    true), × and a swipe close it until the connection comes back and goes again.
--}}
<div wire:offline class="fixed inset-x-0 bottom-4 z-50 mx-auto w-fit">
    <div x-data="{ open: true, unhook: null, init() { this.unhook = window.elSwipe?.(this.$el, { start() {}, end() {}, leave: () => (this.open = false) }) }, destroy() { this.unhook?.() } }"
        x-show="open" x-on:online.window="open = true; $el.style.transform = ''; $el.style.opacity = ''"
        class="el-notice el-notice-box flex items-center gap-2 rounded-xl bg-gray-950 px-4 py-2 text-sm text-white shadow-lg dark:bg-white dark:text-gray-950" style="margin: 0" role="status" data-notice="offline">
        <span class="el-notice-text">You're offline. Changes are saved once the connection is back.</span>
        <button type="button" class="el-notice-close" x-on:click="open = false" aria-label="Dismiss" title="Dismiss">@svg('heroicon-m-x-mark', ['aria-hidden' => 'true'])</button>
    </div>
</div>
