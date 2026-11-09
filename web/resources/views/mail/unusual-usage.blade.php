<x-mail::message>
# Unusual use

{{ $name }} ({{ $email }}) recorded {{ $hours }} hours in the last 24 hours. You asked to hear about more than {{ $limit }} hours a day.

If it does not look right, you can suspend the account on their customer page in the admin panel. You hear about this person at most once a day.

<x-mail::button :url="$url">
Open their page
</x-mail::button>
</x-mail::message>
