<x-mail::message>
# Your email address was changed

The email address of your @brand account is now **{{ $newAddress }}**. This address no longer signs you in or gets the account's emails.

If this wasn't you, put your address back. It needs no sign-in, signs out every phone, computer and browser, and works for {{ $linkDays }} days:

<x-mail::button :url="$url">
Put my address back
</x-mail::button>

Then change your password with "Forgot password?" on the sign-in page: whoever changed it knew your password or a code.
</x-mail::message>
