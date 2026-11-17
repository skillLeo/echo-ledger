{{-- Contact support (v1.8 P6.4). Every word from the person is in a code block or code span already (SupportMessageMail): printed raw so it is not escaped twice, and Markdown reads none of it. --}}
<x-mail::message>
# A message to support

From {!! $nameCode !!} ({!! $emailText !!}){{ $platform ? ', on '.$platform : '' }}. Reply to this email to answer them.

**Subject**

{!! $subjectBlock !!}

**Message**

{!! $messageBlock !!}

{{ $reported ? 'Their device report is attached (device-report.json).' : 'They did not attach a device report.' }}
</x-mail::message>
