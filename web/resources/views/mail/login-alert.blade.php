<x-mail::message>
# New sign-in to your account

**{{ $what }}** signed in to your EchoLedger account on {{ $when }}.

<x-mail::table>
| | |
|:--|:--|
| Where | {{ $what }} |
| When | {{ $when }} |
@if ($ip)
| Address | {{ $ip }}{{ $country ? ' ('.$country.')' : '' }} |
@endif
</x-mail::table>

If this was you, there is nothing to do. If it was not, open your devices, revoke it and change your password.

<x-mail::button :url="$devicesUrl">
Open my devices
</x-mail::button>
</x-mail::message>
