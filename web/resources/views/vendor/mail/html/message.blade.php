<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
EchoLedger
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: the legal pages (since v1.5) --}}
<x-slot:footer>
<x-mail::footer>
EchoLedger · [Privacy]({{ url('/privacy') }}) · [Terms]({{ url('/terms') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
