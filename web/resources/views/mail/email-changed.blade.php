<x-mail::message>
# Your email address was changed

The email address of your @brand account is now **{{ $newAddress }}**. This address no longer signs you in or gets the account's emails.

If this wasn't you, put your address back. It needs no sign-in, works for {{ $linkDays }} days from the change, signs out every phone, computer and browser and removes the ways in added since. Until then the address cannot be changed again from the apps:

<x-mail::button :url="$url">
Put my address back
</x-mail::button>

The password then stops working, and we email you a link to choose a new one: whoever changed the address knew your password or a code.
</x-mail::message>
