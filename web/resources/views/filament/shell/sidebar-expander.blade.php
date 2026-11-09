{{-- The arrow next to Library, Projects and Shared: shows what is inside; the person's choice is kept. --}}
<button type="button" class="el-sb-expand" x-show="! rail()" x-bind:class="{ 'el-open': isOpen(@js($group)) }"
    x-bind:aria-expanded="isOpen(@js($group)) ? 'true' : 'false'" x-on:click="toggleGroup(@js($group))"
    x-bind:aria-label="(isOpen(@js($group)) ? 'Hide ' : 'Show ') + @js($label)" x-bind:title="(isOpen(@js($group)) ? 'Hide ' : 'Show ') + @js($label)">
    @svg('heroicon-m-chevron-right', ['aria-hidden' => 'true'])
</button>
