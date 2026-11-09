{{--
    Cloudflare Turnstile on the sign-up (since v1.6), only while its keys are saved in API Credentials and the admin
    panel's switch is on. Since v1.6.2 it shows on the sign-up page's first step, under the email, where the code is
    asked for (on the sign-in page, for a new address, at "One quick check"). The managed widget fills the field; the
    server checks the answer again (turnstile_failed) before it emails a code. An answer that expires (5 minutes) is
    cleared, so Continue asks for a new one.
--}}
@if (filled($siteKey ?? null))
    <div class="el-turnstile" data-turnstile wire:ignore x-data x-init="
        const box = $refs.box;
        const done = (token) => $wire.set('data.turnstile', token, false);
        const clear = () => $wire.set('data.turnstile', null, false);
        const draw = () => {
            if (box.dataset.drawn) return;
            box.dataset.drawn = '1';
            window.turnstile.render(box, {
                sitekey: @js($siteKey), action: 'sign-up', appearance: 'always', size: 'flexible',
                theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
                callback: done, 'expired-callback': clear, 'error-callback': clear,
            });
        };
        if (window.turnstile) { draw(); } else {
            window.elTurnstileReady = (window.elTurnstileReady || []).concat(draw);
            if (! document.querySelector('script[data-el-turnstile]')) {
                const s = document.createElement('script');
                s.src = @js(\App\Services\Accounts\Turnstile::SCRIPT_URL.'?render=explicit');
                s.async = true; s.defer = true; s.dataset.elTurnstile = '1';
                s.onload = () => (window.elTurnstileReady || []).forEach((f) => f());
                document.head.appendChild(s);
            }
        }
        window.addEventListener('turnstile-reset', () => window.turnstile && window.turnstile.reset(box));
    ">
        <div x-ref="box" class="el-turnstile-box"></div>
        <p class="el-turnstile-note">Protected by Cloudflare Turnstile · <a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noopener">Privacy</a></p>
    </div>
@endif
