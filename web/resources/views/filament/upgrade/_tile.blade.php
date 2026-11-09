{{-- One receipt: a thumbnail (a PDF shows its name and size), "Sent {date}" once sent, × before that. --}}
<div class="el-tile" data-proof="{{ $proof['id'] }}">
    @if ($proof['pdf'])
        <a href="{{ $proof['full'] }}" class="el-tile-pic" download aria-label="Download PDF {{ $proof['name'] ?? '' }}">
            @svg('heroicon-o-document-text', ['aria-hidden' => 'true'])
            <span class="el-tile-name">{{ $proof['name'] ?? 'Receipt.pdf' }}</span>
            <span>{{ $proof['size'] }}</span>
        </a>
    @else
        <a href="{{ $proof['full'] }}" target="_blank" rel="noopener" class="el-tile-pic" aria-label="Receipt {{ $number }}, image, open full size">
            <img src="{{ $proof['thumb'] }}" alt="" loading="lazy">
        </a>
    @endif
    @if ($proof['sent'])
        <span class="el-tile-meta">Sent {{ \App\Support\Formats::shortDate($proof['created_at'] ?? null) }}</span>
    @elseif ($removable ?? false)
        <button type="button" class="el-tile-x" wire:click="removeProof('{{ $proof['id'] }}')" aria-label="Remove receipt {{ $number }}">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
    @endif
    @if ($proof['pdf'])<span class="el-tile-meta">Download PDF</span>@endif
</div>
