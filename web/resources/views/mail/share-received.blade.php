<x-mail::message>
# {{ $sender }} sent you {{ $project ? 'a project' : 'a recording' }}

**{{ $what }}** is waiting for you under Shared with me. Open it, or save a copy to your own library.

@if ($note)
<x-mail::panel>
{{ $note }}
</x-mail::panel>
@endif

<x-mail::button :url="$url">
Open Shared with me
</x-mail::button>
</x-mail::message>
