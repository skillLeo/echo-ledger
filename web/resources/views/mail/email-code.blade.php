<x-mail::message>
# {{ $signUp ? 'Your code to create your account' : 'Your code to sign in' }}

Enter this code in EchoLedger:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

It works for {{ $minutes }} minutes, once. If you did not ask for it, you can ignore this email: nobody can sign in without the code.
</x-mail::message>
