<x-mail::message>
# The server's PHP is missing something it needs

The scheduled health check failed. Uploads and the queue can fail until it is fixed. Recordings that fail meanwhile keep
their parts and are finished by the recovery once the server is fixed.

@if ($missing !== [] || $cipher !== null)
**The scheduled tasks' PHP ({{ $php }}):**
@if ($missing !== [])
missing {{ implode(', ', $missing) }}.
@endif
@if ($cipher !== null)
The recordings cipher fails: {{ $cipher }}
@endif
@endif

@if ($webFails)
**The web PHP (uploads):**
@if ($webMissing !== [])
missing {{ implode(', ', $webMissing) }}.
@endif
@if ($webCipher !== null)
{{ $webCipher }}
@endif
@endif

@if ($fix !== null)
**To turn it back on**, on the server (the hosting's PHP selector; it changes the CLI and the web PHP together):

<x-mail::panel>
{{ $fix }}
</x-mail::panel>

Then check both PHPs with `bash deploy/check-php.sh` (web/DEPLOY.md, Requirements).
@else
Turn the extensions on for both the web PHP and the CLI PHP (web/DEPLOY.md, Requirements), then run
`php artisan echoledger:health`.
@endif

You hear about it once an hour while it lasts, and once more when it is fixed.

<x-mail::button :url="$url">
Open the admin dashboard
</x-mail::button>
</x-mail::message>
