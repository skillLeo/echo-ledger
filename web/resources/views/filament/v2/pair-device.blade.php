{{--
    The new frame's Sign in a device window (W-207): filament/pair-device.blade.php with its words in the console's
    language and its looks in the design values. Each line takes its own direction, and the server's address stays left
    to right. Its wire:, x- and script hooks are the original's (UrduPageTitlesTest keeps the two the same).
--}}
@php $state = $getState() ?? []; @endphp
@if (! empty($state['error']))
    <p role="alert" dir="auto" style="text-align:center;padding-block:var(--el-space-16)">{{ $state['error'] }}</p>
@else
<div style="display:flex;flex-direction:column;align-items:center;gap:var(--el-space-16);padding-block:var(--el-space-8)"
     x-data="{ state: 'waiting', name: null }"
     x-init="const timer = setInterval(async () => {
         const answer = await $wire.pairingStatus();
         state = answer.state; name = answer.name;
         if (state === 'signed_in') { clearInterval(timer); setTimeout(() => $wire.unmountAction(), 2000); }
         if (state === 'expired') { clearInterval(timer); }
     }, 2000)">
    @if (! empty($state['qr']))
        {{-- A QR code needs a light plate to scan, in dark mode too: white whatever the theme. --}}
        <div style="background:white;padding:var(--el-space-12);border-radius:var(--el-radius-lg)" x-show="state === 'waiting'">
            <img src="{{ $state['qr'] }}" alt="{{ __('Sign-in QR code') }}" style="width:calc(var(--el-space-64) * 3.25);height:calc(var(--el-space-64) * 3.25);display:block">
        </div>
    @endif
    <div dir="ltr" style="font-family:var(--el-font-family-mono);font-size:var(--el-text-display-size);letter-spacing:var(--el-space-8);font-weight:var(--el-text-display-weight)" x-show="state === 'waiting'">
        {{ $state['code'] ?? '' }}
    </div>
    <p role="status" aria-live="polite" style="font-weight:var(--el-text-body-strong-weight);text-align:center">
        <span dir="auto" x-show="state === 'waiting'">{{ __('Waiting for the app…') }}</span>
        <span dir="auto" x-show="state === 'signed_in'" x-cloak style="color:var(--el-role-success-default)" x-text="'✓ ' + {{ \Illuminate\Support\Js::from(__(':name signed in')) }}.replace(':name', name)"></span>
        <span dir="auto" x-show="state === 'expired'" x-cloak>{{ __('This code has expired. Close and make a new one.') }}</span>
    </p>
    <div style="font-size:var(--el-text-caption-size);color:var(--el-role-text-secondary);text-align:center" x-show="state === 'waiting'">
        <span dir="auto">{{ __('Server:') }} <bdi dir="ltr">{{ $state['url'] ?? '' }}</bdi></span><br>
        <span dir="auto">{{ __('It works once, for 10 minutes.') }}</span>
    </div>
</div>
@endif
