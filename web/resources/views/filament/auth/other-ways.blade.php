{{--
    The other ways in, under the email and Continue (since v1.6.2): "or", then Continue with Google and Passkey side by
    side, equal width (stacked below 360 px).

    Passkeys work by themselves: where the browser has conditional mediation, the page asks for a passkey quietly on
    load (navigator.credentials.get with mediation "conditional"), so a saved passkey is offered in the email box's
    autofill (autocomplete="username webauthn") without a click, and never in a dialog on load. The Passkey button is
    for browsers without it, or to choose another passkey; it stops the quiet request first.

    The Google button follows Google's sign-in branding: the multicolour "G", a white button with a 1 px border (dark:
    dark grey), "Continue with Google".
--}}
@php
    $google = app(\App\Services\Accounts\GoogleSignIn::class)->configured();
@endphp
@include('filament.passkey-script')
<div class="el-other-ways" data-other-ways
    x-data="{
        busy: false,
        error: null,
        supported: window.elPasskeys.supported(),
        quiet: null,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
        async options() {
            return (await fetch(@js(route('passkeys.login.options')), { method: 'POST', headers: this.headers, credentials: 'same-origin' })).json();
        },
        async finish(credential) {
            const answer = await fetch(@js(route('passkeys.login')), { method: 'POST', headers: this.headers, credentials: 'same-origin', body: JSON.stringify({ credential }) });
            const body = await answer.json();
            if (answer.ok && body.redirect) { window.location.assign(body.redirect); return; }
            this.error = body.message || 'That passkey could not sign you in.';
        },
        async init() {
            if (! (await window.elPasskeys.conditional())) return;
            this.quiet = new AbortController();
            try {
                const credential = await window.elPasskeys.get(await this.options(), { mediation: 'conditional', signal: this.quiet.signal });
                this.busy = true;
                await this.finish(credential);
            } catch (e) {
                // Cancelled, aborted for the button, or nothing chosen: nothing to say.
            } finally { this.busy = false; this.quiet = null; }
        },
        async signIn() {
            this.quiet?.abort();
            this.quiet = null;
            this.busy = true; this.error = null;
            try {
                await this.finish(await window.elPasskeys.get(await this.options()));
            } catch (e) {
                this.error = e && e.name === 'NotAllowedError' ? 'Passkey sign-in was cancelled.' : 'That passkey could not sign you in.';
            } finally { this.busy = false; }
        },
        destroy() { this.quiet?.abort(); },
    }"
    @if (! $google) x-show="supported" x-cloak @endif>
    <div class="el-or" role="separator" aria-label="or"><span>or</span></div>
    <div @class(['el-other-ways-row', 'el-one' => ! $google])>
        @if ($google)
            <a href="{{ route('auth.google.redirect') }}" class="el-auth-btn el-google-btn" data-google>
                <svg class="el-google-g" viewBox="0 0 48 48" width="18" height="18" aria-hidden="true" focusable="false">
                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    <path fill="none" d="M0 0h48v48H0z"/>
                </svg>
                <span>Continue with Google</span>
            </a>
        @endif
        <button type="button" class="el-auth-btn el-passkey-btn" data-passkey x-show="supported" x-cloak x-on:click="signIn()" x-bind:disabled="busy"
            title="Sign in with a passkey: your fingerprint, face or device PIN">
            @svg('heroicon-o-key', 'el-auth-btn-icon', ['aria-hidden' => 'true'])
            <span>Passkey</span>
        </button>
    </div>
    <p class="el-other-ways-error" x-show="error" x-text="error" role="alert" x-cloak></p>
</div>
