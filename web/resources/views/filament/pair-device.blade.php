@php $state = $getState() ?? []; @endphp
@if (! empty($state['error']))
    <p role="alert" style="text-align:center;padding:1rem 0">{{ $state['error'] }}</p>
@else
<div style="display:flex;flex-direction:column;align-items:center;gap:1rem;padding:.5rem 0"
     x-data="{ state: 'waiting', name: null }"
     x-init="const timer = setInterval(async () => {
         const answer = await $wire.pairingStatus();
         state = answer.state; name = answer.name;
         if (state === 'signed_in') { clearInterval(timer); setTimeout(() => $wire.unmountAction(), 2000); }
         if (state === 'expired') { clearInterval(timer); }
     }, 2000)">
    @if (! empty($state['qr']))
        <div style="background:#fff;padding:.75rem;border-radius:1rem" x-show="state === 'waiting'">
            <img src="{{ $state['qr'] }}" alt="Sign-in QR code" style="width:13rem;height:13rem;display:block">
        </div>
    @endif
    <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:2rem;letter-spacing:.3em;font-weight:700" x-show="state === 'waiting'">
        {{ $state['code'] ?? '' }}
    </div>
    <p role="status" aria-live="polite" style="font-weight:600;text-align:center">
        <span x-show="state === 'waiting'">Waiting for the app…</span>
        <span x-show="state === 'signed_in'" x-cloak style="color:rgb(22 163 74)" x-text="'✓ ' + name + ' signed in'"></span>
        <span x-show="state === 'expired'" x-cloak>This code has expired. Close and make a new one.</span>
    </p>
    <div style="font-size:.8rem;opacity:.7;text-align:center" x-show="state === 'waiting'">
        Server: {{ $state['url'] ?? '' }}<br>
        It works once, for 10 minutes.
    </div>
</div>
@endif
