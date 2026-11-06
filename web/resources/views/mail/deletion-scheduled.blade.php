<x-mail::message>
# Your account will be deleted

You asked to delete your EchoLedger account. On **{{ $date }}** we erase it with everything only it owns: recordings, transcripts, screens, files, projects and devices. This cannot be undone.

Changed your mind? Sign in before then and choose "Keep my account".

<x-mail::button :url="$keepUrl">
Keep my account
</x-mail::button>

Until then you are signed out everywhere, and signing in only offers to keep the account.
</x-mail::message>
