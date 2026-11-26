<x-mail::message>
# Your email address was changed

The email address of your @brand account is now **{{ $newAddress }}**. This address no longer signs you in or gets the account's emails.

@if ($url)
If this wasn't you, put your address back. It needs no sign-in and works for {{ $linkDays }} days from the change. It signs out every phone, computer and browser, removes every passkey, Google and two-step sign-in, and stops the password: we then email you a link to choose a new one. Until then the address cannot be changed again from the apps:

<x-mail::button :url="$url">
Put my address back
</x-mail::button>
@else
The change was confirmed with the account's two-step sign-in. If this wasn't you, contact support: reply to this email, or use Help → Contact support on the @brand website, and say which address the account had.
@endif
</x-mail::message>
