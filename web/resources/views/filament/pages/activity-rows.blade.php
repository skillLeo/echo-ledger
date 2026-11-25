{{-- Activity rows: tile, title (a dot while unread), time, and the line under it (a missed recording's may wrap: it ends with why).
     UX-18: in the new console an item about a person (a share's sender, a customer for staff) shows their avatar in
     the tile's place; plain PHP picks it, so Livewire adds no block markers and classic's rows stay byte for byte.
     P-043: only an item that opens something is a button. One with nowhere to go is a plain row with no hover, which
     while unread ends with a quiet Mark as read; plain PHP picks its tags, so an item that opens is the same bytes. --}}
<ul class="el-rows" role="list">
    @foreach ($items as $item)
        <li class="el-row-item">
            <?php if ($item['opens'] ?? true): ?><button type="button" class="el-row el-row-btn" wire:click="open('{{ $item['id'] }}')"<?php else: ?><div class="el-row el-row-btn el-row-static"<?php endif; ?> wire:key="activity-{{ $item['id'] }}"{!! \App\Filament\Support\ConsoleV2::any() ? ' data-v2-object="activity" data-v2-object-id="'.e($item['id']).'" data-v2-object-not="'.e($item['not'] ?? '').'"' : '' !!}>
                <?php if (($item['person'] ?? null) !== null): ?><x-el.avatar :person="$item['person']" size="list" /><?php else: ?><span class="el-tile el-tile-{{ $item['tone'] }}">@svg(...\App\Filament\Support\RtlIcons::of($item['icon'], ['aria-hidden' => 'true']))</span>
<?php endif; ?>                <span class="el-row-main">
                    <span class="el-row-top">
                        <span @class(['el-row-title', 'el-unread' => ! $item['read']])>
                            @if (! $item['read'])<span class="el-sr">{{ __('Unread:') }} </span>@endif{{ $item['title'] }}
                        </span>
                        <time class="el-row-time" datetime="{{ $item['at'] }}" title="{{ $item['full_time'] }}">{{ $item['time'] }}</time>
                    </span>
                    <span class="el-row-sub"><span @class(['el-row-meta', 'el-row-meta-wraps' => $item['kind'] === 'missed_recording'])>{{ $item['body'] }}</span></span>
                </span>
<?php if (! ($item['opens'] ?? true) && ! $item['read']): ?>                <button type="button" class="el-text-btn" wire:click="markRead('{{ $item['id'] }}')">{{ __('Mark as read') }}</button>
<?php endif; ?>            </{{ ($item['opens'] ?? true) ? 'button' : 'div' }}>
        </li>
    @endforeach
</ul>
