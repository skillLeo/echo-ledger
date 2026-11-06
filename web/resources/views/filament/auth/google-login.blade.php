{{-- "Sign in with Google" under the console's sign-in and sign-up forms (since v1.5), once API Credentials has the client. --}}
@if (app(\App\Services\Accounts\GoogleSignIn::class)->configured())
    <div style="margin-top:1rem">
        <x-filament::button tag="a" color="gray" class="w-full" :href="route('auth.google.redirect')" icon="heroicon-o-arrow-right-end-on-rectangle">
            {{ $label ?? 'Sign in with Google' }}
        </x-filament::button>
    </div>
@endif
