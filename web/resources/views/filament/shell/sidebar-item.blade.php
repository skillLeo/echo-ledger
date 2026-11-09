{{-- One sidebar item: outlined at rest, solid where you are; its label hides on the rail (where its title shows). --}}
<a href="{{ $url }}" @class(['el-sb-item', 'el-on' => $active]) @if ($active) aria-current="page" @endif
    x-bind:title="rail() ? @js($label) : @js($title ?? null)">
    @if (isset($heroicon))
        <x-filament::icon :icon="$heroicon" class="el-sb-icon" />
    @else
        @svg(($active ? 'heroicon-s-' : 'heroicon-o-').$icon, ['class' => 'el-sb-icon', 'aria-hidden' => 'true'])
    @endif
    <span class="el-sb-label">{{ $label }}</span>
    @if (filled($badge ?? null))
        <span class="el-sb-badge"><span class="el-sr">, needs you: </span>{{ $badge }}</span>
    @endif
</a>
