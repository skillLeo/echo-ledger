{{-- Cloudflare Turnstile on the sign-up (since v1.6), only while its keys are set in API Credentials. --}}
@if (filled($siteKey ?? null))
    <div wire:ignore x-data x-init="
        window.elTurnstileDone = (token) => $wire.set('data.turnstile', token, false);
        const draw = () => window.turnstile.render($refs.box, { sitekey: @js($siteKey), callback: window.elTurnstileDone });
        if (window.turnstile) { draw(); } else {
            const s = document.createElement('script');
            s.src = @js(\App\Services\Accounts\Turnstile::SCRIPT_URL.'?render=explicit');
            s.async = true; s.onload = draw; document.head.appendChild(s);
        }
        window.addEventListener('turnstile-reset', () => window.turnstile && window.turnstile.reset($refs.box));
    ">
        <div x-ref="box"></div>
    </div>
@endif
