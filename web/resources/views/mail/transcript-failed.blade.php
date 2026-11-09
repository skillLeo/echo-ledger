<x-mail::message>
# The transcript could not be made

The transcript of **{{ $title }}** could not be made. The recording's page says why and, where it helps, has **Retry**.

<x-mail::button :url="$url">
Open the recording
</x-mail::button>

You get this email because you chose "Notify me when ready" for this recording.
</x-mail::message>
