{{-- The one badge of a plan-locked feature (since v1.6): "PRO ✦" in the plan's colour with a small lock. --}}
@props(['badge', 'color' => '#64748b', 'locked' => true])
<span {{ $attributes->merge(['class' => 'el-plan-badge']) }}
      style="display:inline-flex;align-items:center;gap:.25rem;padding:.0625rem .4375rem;border-radius:999px;font-size:.6875rem;font-weight:700;letter-spacing:.04em;line-height:1.25rem;white-space:nowrap;color:{{ $color }};background:color-mix(in srgb, {{ $color }} 12%, transparent);border:1px solid color-mix(in srgb, {{ $color }} 35%, transparent)">
    @if ($locked)
        <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" style="width:.75rem;height:.75rem"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/></svg>
    @endif
    {{ $badge }} ✦
</span>
