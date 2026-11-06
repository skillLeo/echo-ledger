<x-mail::message>
# Reset your password

Someone (we hope you) asked to reset the password of your EchoLedger account.

<x-mail::button :url="$url">
Choose a new password
</x-mail::button>

The link works for {{ $minutes }} minutes and only once. If you did not ask for it, ignore this email: your password stays as it is.
</x-mail::message>
