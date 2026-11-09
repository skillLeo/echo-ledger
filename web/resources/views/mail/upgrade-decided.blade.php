<x-mail::message>
@if ($approved)
# You are on {{ $plan }} now

Thank you! Everything in {{ $plan }} is on in your apps and the console{{ $until ? ' until '.$until : '' }}. Open the app again if it still shows a lock.

@if ($reply)
<x-mail::panel>
{{ $reply }}
</x-mail::panel>
@endif
@else
# About your request for {{ $plan }}

We could not switch {{ $plan }} on this time.

@if ($reply)
<x-mail::panel>
{{ $reply }}
</x-mail::panel>
@endif

Reply to this email if you have a question.
@endif

<x-mail::button :url="$url">
Open EchoLedger
</x-mail::button>
</x-mail::message>
