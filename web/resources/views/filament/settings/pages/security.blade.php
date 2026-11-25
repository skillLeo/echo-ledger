{{--
    Settings › Sign-in & security: the password, two-step sign-in (and its recovery codes), Google, passkeys, the
    browsers signed in, the sign-in activity and Sign out everywhere.
--}}
<x-el.section>
    <x-el.row setting="security.password" :title="__('Password')" click="mountAction('changePassword')" :value="$hasPassword ? null : __('settings_context.password_not_set')" />
    <x-el.row setting="security.two_step" :title="__('Two-step sign-in')" :value="$twoStep ? __('On') : __('Off')"
        :click="$twoStep ? 'mountAction(\'disableAppAuthentication\')' : 'mountAction(\'setUpAppAuthentication\')'" />
    @if ($twoStep)
        <x-el.row setting="security.recovery" :title="__('Recovery codes')" click="mountAction('regenerateAppAuthenticationRecoveryCodes')" />
    @endif
    @if ($google)
        @if ($googleLinked)
            <x-el.row setting="security.google" :title="__('Google sign-in')" :value="__('Connected')" click="mountAction('unlinkGoogle')" />
        @else
            <x-el.row setting="security.google" :title="__('Google sign-in')" :value="__('Not connected')" :href="$googleConfigured ? $googleUrl : null" />
        @endif
    @endif
</x-el.section>
@if ($twoStep)
    <p class="el-set-foot">{{ __('Two-step sign-in asks for a code from your authenticator app when you sign in.') }}</p>
@endif

@if ($passkeysOn)
    @include('filament.passkey-script')
    <x-el.section :title="__('Passkeys')" setting="security.passkeys">
        @foreach ($passkeys as $passkey)
            <div class="el-set-row has-icon">
                @svg('heroicon-o-finger-print', ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
                <span class="el-set-text"><span class="el-set-title">{{ $passkey['name'] }}</span><span class="el-set-sub">{{ $passkey['value'] }}</span></span>
                <button type="button" class="el-btn el-btn-text is-danger" wire:click="mountAction('removePasskey', { passkey: '{{ $passkey['id'] }}' })">{{ __('Remove') }}</button>
            </div>
        @endforeach
        @if (count($passkeys) < $maxPasskeys)
            <div class="el-set-group" x-data="{ name: '', busy: false, error: null, supported: window.elPasskeys?.supported?.() ?? false,
                    async add() { this.busy = true; this.error = null;
                        try { const options = await $wire.passkeyOptions(); const credential = await window.elPasskeys.create(options); await $wire.savePasskey(credential, this.name); this.name = ''; }
                        catch (e) { this.error = e && e.name === 'NotAllowedError' ? @js(__('Adding the passkey was cancelled.')) : @js(__('That passkey could not be added. Try again.')); }
                        finally { this.busy = false; } } }">
                <form class="el-form-field" x-show="supported" x-on:submit.prevent="add()">
                    <label for="el-passkey-name">{{ __('Add a passkey') }}</label>
                    <div class="el-form-inline">
                        <input id="el-passkey-name" type="text" maxlength="80" placeholder="{{ __('Name, for example Work laptop') }}" x-model="name">
                        <button type="submit" class="el-btn el-btn-outline" x-bind:disabled="busy">{{ __('Add') }}</button>
                    </div>
                    <p class="el-error" x-show="error" x-text="error" role="alert"></p>
                </form>
                <p class="el-prose is-muted" x-show="! supported" style="padding-bottom:16px">{!! htmlspecialchars(__("This browser can't make passkeys."), ENT_NOQUOTES) !!}</p>
            </div>
        @endif
    </x-el.section>
    <p class="el-set-foot">{{ __('Sign in with your fingerprint, face or device PIN instead of a password.') }}</p>
@endif

@if ($browsersOn)
    <x-el.section :title="__('Signed-in browsers')" setting="security.browsers">
        @foreach ($browsers as $browser)
            <div class="el-set-row has-icon">
                @svg('heroicon-o-globe-alt', ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
                <span class="el-set-text">
                    <span class="el-set-title">{{ $browser['browser'] }}</span>
                    <span class="el-set-sub">{{ $browser['current'] ? __('This browser') : __('Active :when', ['when' => $browser['last_active']->diffForHumans(short: true)]) }}{{ $browser['country'] ? ', '.$browser['country'] : '' }}</span>
                </span>
                @unless ($browser['current'])
                    <button type="button" class="el-btn el-btn-text is-danger" wire:click="mountAction('signOutBrowser', { browser: '{{ $browser['id'] }}' })">{{ __('Sign out') }}</button>
                @endunless
            </div>
        @endforeach
    </x-el.section>
@endif

<x-el.section>
    @if ($activityUrl)
        <x-el.row setting="security.activity" :title="__('Sign-in activity')" :href="$activityUrl" chevron />
    @endif
    <x-el.row setting="security.signout_all" :title="__('Sign out everywhere')" click="mountAction('signOutEverywhere')" danger />
</x-el.section>
