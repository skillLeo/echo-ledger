@php $state = $getState() ?? []; @endphp
<div style="display:flex;flex-direction:column;align-items:center;gap:1rem;padding:.5rem 0">
    @if (! empty($state['qr']))
        <div style="background:#fff;padding:.75rem;border-radius:1rem">
            <img src="{{ $state['qr'] }}" alt="Pairing QR code" style="width:13rem;height:13rem;display:block">
        </div>
    @endif
    <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:2rem;letter-spacing:.3em;font-weight:700">
        {{ $state['code'] ?? '' }}
    </div>
    <div style="font-size:.8rem;opacity:.7;text-align:center">
        Server: {{ $state['url'] ?? '' }}<br>
        The code is stored only as a hash and cannot be shown again.
    </div>
</div>
