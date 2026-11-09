{{-- Settings › Storage: how much of the plan's storage is used, how long recordings are kept, the bin. --}}
<x-el.section footer="Deleted recordings stay in Recently deleted for {{ $bin }}, then they are erased.">
    <div @class(['el-meter', 'el-set-group', 'is-warning' => ($percent ?? 0) >= 80 && ($percent ?? 0) < 100, 'is-danger' => ($percent ?? 0) >= 100])
        id="setting-storage.used" tabindex="-1" data-setting="storage.used" role="group" aria-label="Your storage, {{ $value }} used">
        <div class="el-meter-top"><span class="el-set-title">Your storage</span><span class="el-set-value" style="max-width:60%">{{ $value }}</span></div>
        @if ($percent !== null)
            <div class="el-meter-bar" aria-hidden="true"><div class="el-meter-fill" style="width: {{ $percent }}%"></div></div>
        @endif
    </div>
    <x-el.row setting="storage.retention" title="Recordings kept" :value="$retention" />
    <x-el.row setting="storage.bin" title="Bin" :value="$bin" :href="$binUrl" :chevron="$binUrl !== null" />
</x-el.section>
