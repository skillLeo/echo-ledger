<x-mail::message>
# The server's PHP is missing something it needs

The health check of the scheduled tasks' PHP ({{ $php }}) failed. Uploads and the queue can fail until it is fixed.
Recordings that fail meanwhile keep their parts and are finished by the recovery once the server is fixed.

@if ($missing !== [])
**Missing PHP extensions:** {{ implode(', ', $missing) }}
@endif

@if ($cipher !== null)
**Recordings cipher:** {{ $cipher }}
@endif

Turn the extensions on for both the web PHP and the CLI PHP (web/DEPLOY.md, Requirements), then run
`php artisan echoledger:health`. You hear about it once an hour while it lasts.

<x-mail::button :url="$url">
Open the admin dashboard
</x-mail::button>
</x-mail::message>
