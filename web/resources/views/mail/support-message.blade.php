<x-mail::message>
# {{ $topic !== '' ? $topic : 'A question' }}

From **{{ $name }}** ({{ $email }}){{ $platform ? ', on '.$platform : '' }}. Reply to this email to answer them.

<x-mail::panel>
{{ $body }}
</x-mail::panel>

{{ $reported ? 'Their device report is attached (device-report.json).' : 'They did not attach a device report.' }}
</x-mail::message>
