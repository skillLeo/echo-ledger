<x-mail::message>
# Confirm your email

Open the link below to confirm this address for your EchoLedger account. Until it is confirmed, the console and the apps cannot sign you in.

<x-mail::button :url="$url">
Confirm my email
</x-mail::button>

The link works for {{ $minutes }} minutes. If you did not make an EchoLedger account, you can ignore this email.
</x-mail::message>
