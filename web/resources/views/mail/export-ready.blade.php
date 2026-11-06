<x-mail::message>
# Your export is ready

Your EchoLedger data is in one ZIP file ({{ $size }}): every recording's audio, its transcripts in each view that was made, the screens, your projects' files and notes, and a manifest.json that lists them.

<x-mail::button :url="$url">
Download my data
</x-mail::button>

The link works until {{ $expires }}; after that the file is deleted. Keep the ZIP somewhere safe: it holds your recordings unencrypted.
</x-mail::message>
