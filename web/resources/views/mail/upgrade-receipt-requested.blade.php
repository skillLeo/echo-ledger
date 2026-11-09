<x-mail::message>
# We need another receipt for {{ $plan }}

We looked at the receipt for your request {{ $reference }} and need another one before we can switch {{ $plan }} on.

<x-mail::panel>
{{ $note }}
</x-mail::panel>

Open your request in the app or the console, add the receipt and press Send. Your request stays open in the meantime.

<x-mail::button :url="$url">
Open EchoLedger
</x-mail::button>
</x-mail::message>
