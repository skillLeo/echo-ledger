<x-mail::message>
@if ($restored)
# The account went back to its previous address

The @brand account that used this address uses **{{ $oldAddress }}** again: someone opened the "Put my address back" link sent there. This address no longer signs in. Every device was signed out, and the account's password, passkeys, Google and two-step sign-in were reset.

If that was not you or someone you trust, contact support: reply to this email, or use Help → Contact support on the @brand website.
@else
# Your previous address can undo this change

Your @brand account now uses this address. The address it had before, **{{ $oldAddress }}**, got a link that can put it back for 7 days. Until then the address cannot be changed again from the apps.

If you made this change, there is nothing to do. If that previous address is no longer yours, contact support: reply to this email, or use Help → Contact support on the @brand website.
@endif
</x-mail::message>
