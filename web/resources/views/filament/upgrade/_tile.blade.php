{{-- One receipt: a thumbnail (a PDF shows its name and size), "Sent {date}" once sent, × before that. --}}
<div class="el-rtile" data-proof="{{ $proof['id'] }}">
    @if ($proof['pdf'])
        <a href="{{ $proof['full'] }}" class="el-rtile-pic" download aria-label="{{ __('Download PDF :name', ['name' => $proof['name'] ?? '']) }}">
            @svg('heroicon-o-document-text', ['aria-hidden' => 'true'])
            <span class="el-rtile-name">{{ $proof['name'] ?? 'Receipt.pdf' }}</span>
            <span>{{ $proof['size'] }}</span>
        </a>
    @else
        <a href="{{ $proof['full'] }}" target="_blank" rel="noopener" class="el-rtile-pic" aria-label="{{ __('Receipt :number, image, open full size', ['number' => (string) $number]) }}">
            <img src="{{ $proof['thumb'] }}" alt="" loading="lazy">
        </a>
    @endif
    @if ($proof['sent'])
        <span class="el-rtile-meta">{{ __('upgrade_context.tile_sent', ['date' => \App\Filament\Support\UpgradeFlow::day($proof['created_at'] ?? null)]) }}</span>
    @elseif ($removable ?? false)
        <button type="button" class="el-rtile-x" wire:click="removeProof('{{ $proof['id'] }}')" aria-label="{{ __('Remove receipt :number', ['number' => (string) $number]) }}">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
    @endif
    @if ($proof['pdf'])<span class="el-rtile-meta">{{ __('Download PDF') }}</span>@endif
</div>
