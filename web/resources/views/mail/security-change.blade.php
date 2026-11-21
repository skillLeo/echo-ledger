<x-mail::message>
# {{ $heading }}

{{ $what }} from the @brand app on **{{ $device }}**.

If this was you, there is nothing to do. If it was not, someone has your device or your password: open Sign-in & security, remove what was added, change your password and sign out everywhere.

<x-mail::button :url="$securityUrl">
Open Sign-in & security
</x-mail::button>
</x-mail::message>
