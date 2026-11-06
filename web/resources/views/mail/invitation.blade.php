<x-mail::message>
# You are invited to {{ $workspace }}

{{ $inviter }} added you to the workspace **{{ $workspace }}** on EchoLedger.

@if ($signUp)
Make your account with this email address to join it.

<x-mail::button :url="$url">
Make my account
</x-mail::button>

@if ($expires)
The invitation works until {{ $expires }}.
@endif
@else
Sign in with this email address to open it. If you have no password yet, {{ $inviter }} gives you a one-time one, or use "Forgot password?" on the sign-in page.

<x-mail::button :url="$url">
Open EchoLedger
</x-mail::button>
@endif

If you did not expect this, you can ignore this email.
</x-mail::message>
