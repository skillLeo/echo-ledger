<x-mail::message>
# {{ $heading }}

@if ($change === 'two_factor_on')
Two-step sign-in was turned on for your account from the @brand app on **{!! $deviceText !!}**.
@elseif ($change === 'two_factor_off')
Two-step sign-in was turned off for your account from the @brand app on **{!! $deviceText !!}**. Signing in now needs only your password or an email code.
@elseif ($change === 'recovery_codes')
New recovery codes were made for your account from the @brand app on **{!! $deviceText !!}**. The old ones no longer work.
@elseif ($change === 'password_changed')
The password of your account was changed from the @brand app on **{!! $deviceText !!}**.
@elseif ($change === 'google_unlinked')
Google was unlinked from your account from the @brand app on **{!! $deviceText !!}**. Google no longer signs you in.
@elseif ($change === 'passkey_removed')
A passkey{!! $passkeyText !== null ? ' named “'.$passkeyText.'”' : '' !!} was removed from your account from the @brand app on **{!! $deviceText !!}**. It no longer signs you in.
@elseif ($passkeyText !== null)
A passkey named “{!! $passkeyText !!}” was added to your account from the @brand app on **{!! $deviceText !!}**.
@else
A passkey was added to your account from the @brand app on **{!! $deviceText !!}**.
@endif

If this was you, there is nothing to do. If it was not, someone has your device and your password: open Sign-in & security, undo the change, change your password and sign out everywhere.

<x-mail::button :url="$securityUrl">
Open Sign-in & security
</x-mail::button>
</x-mail::message>
