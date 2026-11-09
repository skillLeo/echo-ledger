{{--
    One Settings row (since v1.7): an optional icon (root rows only), the title, an optional second line (entity rows),
    the value or a neutral tag at the right, and a chevron or an external-link glyph. A link (href), a button (click: a
    Livewire call) or plain text. `setting` is the registry id: the row is `#setting-{id}`, where search lands.
--}}
@props([
    'setting' => null,
    'title',
    'value' => null,
    'sub' => null,
    'href' => null,
    'click' => null,
    'icon' => null,
    'chevron' => false,
    'external' => false,
    'danger' => false,
    'tag' => null,
    'label' => null,
])
@php
    $tagName = $href !== null ? 'a' : ($click !== null ? 'button' : 'div');
    $classes = ['el-set-row', 'has-icon' => $icon !== null, 'is-danger' => $danger];
@endphp
<{{ $tagName }}
    @if ($setting) id="setting-{{ $setting }}" tabindex="-1" data-setting="{{ $setting }}" @endif
    @if ($href !== null) href="{{ $href }}" @if ($external) target="_blank" rel="noopener" @endif @endif
    @if ($click !== null) type="button" wire:click="{{ $click }}" @endif
    @if ($label) aria-label="{{ $label }}" @endif
    {{ $attributes->class($classes) }}
>
    @if ($icon)
        @svg($icon, ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
    @endif
    <span class="el-set-text">
        <span class="el-set-title">{{ $title }}</span>
        @if (filled($sub))
            <span class="el-set-sub">{{ $sub }}</span>
        @endif
    </span>
    {{ $slot }}
    @if (filled($tag))
        <span class="el-tag">{{ $tag }}</span>
    @elseif (filled($value))
        <span class="el-set-value">{{ $value }}</span>
    @endif
    @if ($external)
        @svg('heroicon-o-arrow-top-right-on-square', ['class' => 'el-set-chevron', 'aria-hidden' => 'true'])
    @elseif ($chevron)
        @svg('heroicon-o-chevron-right', ['class' => 'el-set-chevron', 'aria-hidden' => 'true'])
    @endif
</{{ $tagName }}>
