<x-mail::message>
# Fixed: the server's PHP has what it needs again

The scheduled health check passes again (PHP {{ $php }}): every required extension is loaded and recordings can be
encrypted. Recordings that failed meanwhile are finished by the recovery within a few minutes, and the apps send
the rest again on their own.

<x-mail::button :url="$url">
Open the admin dashboard
</x-mail::button>
</x-mail::message>
