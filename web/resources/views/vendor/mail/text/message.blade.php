{{-- The plain-text part of every email (since v1.5): the same name and footer as the HTML part, never Laravel's stock text. --}}
<x-mail::layout>
<x-slot:header>
EchoLedger
</x-slot:header>

{{ $slot }}

@isset($subcopy)
<x-slot:subcopy>
{{ $subcopy }}
</x-slot:subcopy>
@endisset

<x-slot:footer>
EchoLedger · Privacy: {{ url('/privacy') }} · Terms: {{ url('/terms') }}
</x-slot:footer>
</x-mail::layout>
