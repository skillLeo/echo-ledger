{{-- "Sign in with a passkey" under the console login form (B16). --}}
@include('filament.passkey-script')
<div x-data="{
        busy: false,
        error: null,
        supported: window.elPasskeys.supported(),
        async signIn() {
            this.busy = true; this.error = null;
            const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) };
            try {
                const options = await (await fetch(@js(route('passkeys.login.options')), { method: 'POST', headers, credentials: 'same-origin' })).json();
                const credential = await window.elPasskeys.get(options);
                const answer = await fetch(@js(route('passkeys.login')), { method: 'POST', headers, credentials: 'same-origin', body: JSON.stringify({ credential }) });
                const body = await answer.json();
                if (answer.ok && body.redirect) { window.location.assign(body.redirect); return; }
                this.error = body.message || 'That passkey could not sign you in.';
            } catch (e) {
                this.error = e && e.name === 'NotAllowedError' ? 'Passkey sign-in was cancelled.' : 'That passkey could not sign you in.';
            } finally { this.busy = false; }
        },
    }" x-show="supported" x-cloak style="margin-top:1rem">
    <x-filament::button color="gray" class="w-full" x-on:click="signIn()" x-bind:disabled="busy" icon="heroicon-o-finger-print">
        Sign in with a passkey
    </x-filament::button>
    <p x-show="error" x-text="error" role="alert" style="margin-top:.5rem;color:rgb(220 38 38);font-size:.875rem"></p>
</div>
