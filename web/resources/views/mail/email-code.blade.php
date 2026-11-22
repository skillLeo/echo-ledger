<x-mail::message>
# {{ $heading ?? ($signUp ? 'Your code to create your account' : 'Your code to sign in') }}

Enter this code in @brand:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

@if (! empty($url))
Or confirm it here:

<x-mail::button :url="$url">
Confirm this address
</x-mail::button>

@endif
It works for {{ $minutes }} minutes, once.
@if ($signIn ?? true)
If you did not ask for it, you can ignore this email: nobody can sign in without the code.
@elseif (! empty($url))
If you did not ask for it, you can ignore this email: the address is not changed without the code or the button.
@else
If you did not ask for it, someone may know your password: change it in @brand's Settings.
@endif
</x-mail::message>
