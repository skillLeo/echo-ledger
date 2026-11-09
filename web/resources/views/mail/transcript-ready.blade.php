<x-mail::message>
# Your transcript is ready

The transcript of **{{ $title }}** is ready to read, copy and share.

<x-mail::button :url="$url">
Open the recording
</x-mail::button>

@if ($asked ?? false)
You get this email because you chose "Notify me when ready" for this recording.
@else
You get this email because "Email me when a transcript is ready" is on in your profile.
@endif
</x-mail::message>
