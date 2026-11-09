{{-- One usage meter: title and "230 of 600 min", a 4 px bar (amber near the limit, red at it), and its line. --}}
<div @class(['el-meter', 'el-set-group', 'is-warning' => $meter['tone'] === 'warning', 'is-danger' => $meter['tone'] === 'danger'])
    @if ($withId ?? true) id="setting-{{ $meter['id'] }}" tabindex="-1" data-setting="{{ $meter['id'] }}" @endif
    role="group" aria-label="{{ $meter['label'] }}">
    <div class="el-meter-top">
        <span class="el-set-title">{{ $meter['title'] }}</span>
        <span class="el-set-value" style="max-width:60%">{{ $meter['value'] }}</span>
    </div>
    @if ($meter['percent'] !== null)
        <div class="el-meter-bar" aria-hidden="true"><div class="el-meter-fill" style="width: {{ $meter['percent'] }}%"></div></div>
    @endif
    @if ($meter['line'] || $meter['link'])
        <p class="el-meter-line">{{ $meter['line'] }}@if ($meter['link']) <a class="el-set-link" href="{{ $meter['link']['url'] }}">{{ $meter['link']['label'] }}</a>@endif</p>
    @endif
</div>
