{{-- Admin panel → Promo codes → Uses (since v1.6.2): who used the code, when, and what became of their request. --}}
<div style="display:grid;gap:.5rem" data-promo-uses>
    @forelse ($uses as $use)
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:.5rem;padding:.5rem 0;border-bottom:1px solid color-mix(in srgb, currentColor 10%, transparent)">
            <div style="min-width:0;overflow-wrap:anywhere">
                <div style="font-weight:600">{{ $use->user?->name ?? 'A removed account' }}</div>
                <div style="font-size:.8125rem;opacity:.75">{{ $use->user?->email }}</div>
            </div>
            <div style="font-size:.8125rem;text-align:right">
                <div>{{ $use->used_at->copy()->setTimezone($zone)->format('M j, Y H:i') }}</div>
                <div style="opacity:.75">{{ $use->upgradeRequest !== null ? 'Request '.ucfirst($use->upgradeRequest->status) : 'No request' }}</div>
            </div>
        </div>
    @empty
        <p style="opacity:.75">Nobody has used it yet.</p>
    @endforelse
</div>
